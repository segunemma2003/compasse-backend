<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ExamSubmissionController extends Controller
{
    /**
     * Students in the exam's class with any saved written (non-CBT) scores.
     */
    public function showGrid(Request $request, Exam $exam): JsonResponse
    {
        if ($denied = $this->assertCanGradeExam($request->user(), $exam)) {
            return $denied;
        }

        if (! $exam->class_id) {
            return response()->json([
                'error' => 'Exam has no class',
                'exam' => ['id' => $exam->id, 'name' => $exam->name],
                'students' => [],
            ]);
        }

        $studentsQuery = Student::query()->where('class_id', $exam->class_id);

        // Same row-level scoping used everywhere else a teacher lists students —
        // for an optional subject this also excludes students not enrolled in it.
        $allowedIds = $this->accessibleStudentIds($request->user());
        if ($allowedIds !== null) {
            $studentsQuery->whereIn('id', $allowedIds);
        }

        $students = $studentsQuery
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'middle_name', 'admission_number']);

        $subs = ExamSubmission::query()
            ->where('exam_id', $exam->id)
            ->get()
            ->keyBy('student_id');

        return response()->json([
            'exam' => [
                'id' => $exam->id,
                'name' => $exam->name,
                'total_marks' => $exam->total_marks,
                'is_cbt' => $exam->is_cbt,
            ],
            'students' => $students->map(function (Student $s) use ($subs) {
                $sub = $subs->get($s->id);

                return [
                    'student_id' => $s->id,
                    'name' => $s->getFullNameAttribute(),
                    'admission_number' => $s->admission_number,
                    'score' => $sub ? (float) $sub->score : null,
                    'remarks' => $sub?->remarks,
                ];
            })->values(),
        ]);
    }

    /**
     * Bulk create/update written exam scores (paper-based exams; not CBT attempts).
     */
    public function bulkUpsert(Request $request, Exam $exam): JsonResponse
    {
        if ($denied = $this->assertCanGradeExam($request->user(), $exam)) {
            return $denied;
        }

        if ($exam->is_cbt) {
            return response()->json([
                'error' => 'Written scores are not recorded this way for CBT exams.',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'scores' => 'required|array|min:1',
            'scores.*.student_id' => 'required|exists:students,id',
            'scores.*.score' => 'required|numeric|min:0',
            'scores.*.remarks' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        $allowedIds = $this->accessibleStudentIds($request->user());
        if ($allowedIds !== null) {
            $outOfScope = collect($request->scores)->pluck('student_id')->diff($allowedIds);
            if ($outOfScope->isNotEmpty()) {
                return $this->forbiddenResponse('One or more students are outside the classes/subjects you teach.');
            }
        }

        $max = (float) $exam->total_marks;

        foreach ($request->scores as $row) {
            if ((float) $row['score'] > $max) {
                return response()->json([
                    'error' => 'Score exceeds exam total marks',
                    'messages' => ['scores' => ["Max score for this exam is {$max}."]],
                ], 422);
            }
        }

        $userId = Auth::id();
        $saved = 0;

        foreach ($request->scores as $row) {
            ExamSubmission::query()->updateOrCreate(
                [
                    'exam_id' => $exam->id,
                    'student_id' => (int) $row['student_id'],
                ],
                [
                    'score' => $row['score'],
                    'remarks' => $row['remarks'] ?? null,
                    'recorded_by' => $userId,
                ]
            );
            $saved++;
        }

        return response()->json([
            'message' => 'Scores saved',
            'saved' => $saved,
        ]);
    }

    /**
     * Route middleware here just checks the caller has *some* teacher-tier
     * role — it can't know whether this specific exam belongs to a subject
     * they actually teach. Without this, any subject teacher could open and
     * edit any other subject's score grid for a class they have nothing to
     * do with.
     */
    private function assertCanGradeExam(User $user, Exam $exam): ?JsonResponse
    {
        // accessibleStudentIds() returning null means an admin/school-wide
        // role — reuse its role classification rather than duplicating it.
        if ($this->accessibleStudentIds($user) === null) {
            return null;
        }

        $teacher = $user->teacher;
        if (! $teacher) {
            return $this->forbiddenResponse('No teacher profile linked to your account.');
        }

        $teachesSubject = $exam->subject_id && DB::table('teacher_subjects')
            ->where('teacher_id', $teacher->id)
            ->where('subject_id', $exam->subject_id)
            ->where('status', 'active')
            ->exists();

        $isClassTeacher = $exam->class_id && DB::table('classes')
            ->where('id', $exam->class_id)
            ->where('class_teacher_id', $teacher->id)
            ->exists();

        if (! $teachesSubject && ! $isClassTeacher) {
            return $this->forbiddenResponse('You are not assigned to this exam\'s subject or class.');
        }

        return null;
    }
}
