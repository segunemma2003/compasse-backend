<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ExamSubmissionController extends Controller
{
    /**
     * Students in the exam's class with any saved written (non-CBT) scores.
     */
    public function showGrid(Exam $exam): JsonResponse
    {
        if (! $exam->class_id) {
            return response()->json([
                'error' => 'Exam has no class',
                'exam' => ['id' => $exam->id, 'name' => $exam->name],
                'students' => [],
            ]);
        }

        $students = Student::query()
            ->where('class_id', $exam->class_id)
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
}
