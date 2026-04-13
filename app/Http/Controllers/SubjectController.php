<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use App\Models\School;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SubjectController extends Controller
{
    /**
     * List all subjects for the current school.
     */
    public function index(): JsonResponse
    {
        try {
            $subjects = Subject::with([
                    'department:id,name',
                    'teacher:id,first_name,last_name,employee_id',
                    'class:id,name,level',
                ])
                ->orderBy('name')
                ->get();

            // Add counts safely – pivot/related tables may not exist on a fresh tenant
            try {
                $subjects->loadCount(['students', 'assignments', 'exams']);
            } catch (\Exception $e) {
                $subjects->each(function ($s) {
                    $s->setAttribute('students_count',    0);
                    $s->setAttribute('assignments_count', 0);
                    $s->setAttribute('exams_count',       0);
                });
            }

            return response()->json(['data' => $subjects]);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to fetch subjects',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new subject.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'          => 'required|string|max:255',
            'code'          => 'nullable|string|max:20',
            'description'   => 'nullable|string|max:1000',
            'department_id' => 'nullable|exists:departments,id',
            'class_id'      => 'nullable|exists:classes,id',
            'teacher_id'    => 'nullable|exists:teachers,id',
            'credits'       => 'nullable|integer|min:1',
        ]);

        $school = School::first();
        if (! $school) {
            return response()->json(['error' => 'School not found'], 400);
        }

        $subject = Subject::create([
            'school_id'     => $school->id,
            'name'          => $request->input('name'),
            'code'          => $this->resolveCode($request->input('code'), $request->input('name')),
            'description'   => $request->input('description'),
            'department_id' => $request->input('department_id'),
            'class_id'      => $request->input('class_id'),
            'teacher_id'    => $request->input('teacher_id'),
            'credits'       => $request->input('credits', 1),
        ]);

        $subject->load([
            'department:id,name',
            'teacher:id,first_name,last_name,employee_id',
            'class:id,name,level',
        ]);

        return response()->json([
            'message' => 'Subject created successfully.',
            'subject' => $subject,
        ], 201);
    }

    /**
     * Show a single subject with full relationships.
     */
    public function show(Subject $subject): JsonResponse
    {
        $subject->load([
            'department:id,name',
            'teacher:id,first_name,last_name,employee_id',
            'class:id,name,level',
        ]);
        $subject->loadCount(['students', 'assignments', 'exams']);

        return response()->json(['subject' => $subject]);
    }

    /**
     * Update a subject.
     */
    public function update(Request $request, Subject $subject): JsonResponse
    {
        $request->validate([
            'name'          => 'sometimes|string|max:255',
            'code'          => 'nullable|string|max:20',
            'description'   => 'nullable|string|max:1000',
            'department_id' => 'nullable|exists:departments,id',
            'class_id'      => 'nullable|exists:classes,id',
            'teacher_id'    => 'nullable|exists:teachers,id',
            'credits'       => 'nullable|integer|min:1',
            'status'        => 'nullable|in:active,inactive',
        ]);

        $updateData = $request->only([
            'name', 'description', 'department_id',
            'class_id', 'teacher_id', 'credits', 'status',
        ]);

        // Resolve code: use provided value, regenerate if blank, skip if not sent
        if ($request->has('code')) {
            $updateData['code'] = $this->resolveCode(
                $request->input('code'),
                $request->input('name', $subject->name),
                $subject->id
            );
        }

        $subject->update($updateData);

        $subject->load([
            'department:id,name',
            'teacher:id,first_name,last_name,employee_id',
            'class:id,name,level',
        ]);

        return response()->json([
            'message' => 'Subject updated successfully.',
            'subject' => $subject,
        ]);
    }

    /**
     * Delete a subject. Blocked if exams or assignments reference it.
     */
    public function destroy(Subject $subject): JsonResponse
    {
        $examCount   = $subject->exams()->count();
        $assignCount = $subject->assignments()->count();

        if ($examCount > 0 || $assignCount > 0) {
            return response()->json([
                'error'             => 'Cannot delete subject',
                'message'           => "Remove the {$examCount} exam(s) and {$assignCount} assignment(s) linked to this subject first.",
                'exams_count'       => $examCount,
                'assignments_count' => $assignCount,
            ], 422);
        }

        $subject->delete();
        return response()->json(['message' => 'Subject deleted.']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    /**
     * Return the provided code, or auto-derive one from the subject name.
     * Keeps trying suffixes (1, 2, …) until unique.
     */
    private function resolveCode(?string $code, string $name, ?int $excludeId = null): string
    {
        if ($code && $code !== '') {
            return strtoupper($code);
        }

        // Build base from name: strip non-alphanumeric, uppercase, max 6 chars
        $base = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 6));
        if ($base === '') $base = 'SUB';

        $candidate = $base;
        $i = 1;

        $query = fn ($c) => Subject::where('code', $c)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId));

        while ($query($candidate)->exists()) {
            $candidate = $base . $i;
            $i++;
        }

        return $candidate;
    }

    // ── Enrollment ──────────────────────────────────────────────────────────────

    /**
     * List students enrolled in this subject.
     * GET /subjects/{subject}/students
     */
    public function enrolledStudents(Subject $subject): JsonResponse
    {
        $students = $subject->students()
            ->with(['class:id,name', 'arm:id,name'])
            ->select('students.id', 'students.first_name', 'students.last_name',
                     'students.admission_number', 'students.class_id', 'students.arm_id', 'students.status')
            ->withPivot('status')
            ->orderBy('students.first_name')
            ->get()
            ->map(function ($s) {
                return [
                    'id'               => $s->id,
                    'full_name'        => trim("{$s->first_name} {$s->last_name}"),
                    'admission_number' => $s->admission_number,
                    'class'            => $s->class?->name,
                    'arm'              => $s->arm?->name,
                    'status'           => $s->pivot->status,
                    'student_status'   => $s->status,
                ];
            });

        return response()->json([
            'subject'  => ['id' => $subject->id, 'name' => $subject->name],
            'students' => $students,
            'total'    => $students->count(),
        ]);
    }

    /**
     * Enroll students in a subject.
     *
     * POST /subjects/{subject}/enroll
     *
     * Body options (use one):
     *   { "class_id": 3 }                   → enroll all active students in that class
     *   { "class_id": 3, "arm_id": 2 }      → enroll all active students in class + arm
     *   { "student_ids": [1, 4, 7] }         → enroll specific students
     */
    public function enroll(Request $request, Subject $subject): JsonResponse
    {
        $request->validate([
            'class_id'    => 'nullable|exists:classes,id',
            'arm_id'      => 'nullable|exists:arms,id',
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'exists:students,id',
        ]);

        if (! $request->filled('class_id') && ! $request->filled('student_ids')) {
            return response()->json([
                'error' => 'Provide either class_id or student_ids to enroll.',
            ], 422);
        }

        $studentIds = collect();

        if ($request->filled('class_id')) {
            $query = Student::where('class_id', $request->class_id)
                ->where('status', 'active');
            if ($request->filled('arm_id')) {
                $query->where('arm_id', $request->arm_id);
            }
            $studentIds = $query->pluck('id');
        } elseif ($request->filled('student_ids')) {
            $studentIds = collect($request->student_ids);
        }

        if ($studentIds->isEmpty()) {
            return response()->json(['message' => 'No students found to enroll.', 'enrolled' => 0]);
        }

        // syncWithoutDetaching keeps existing enrollments intact
        $pivotData = $studentIds->mapWithKeys(fn ($id) => [$id => ['status' => 'active']])->all();
        $subject->students()->syncWithoutDetaching($pivotData);

        return response()->json([
            'message'  => "Enrolled {$studentIds->count()} student(s) in {$subject->name}.",
            'enrolled' => $studentIds->count(),
        ]);
    }

    /**
     * Unenroll a single student from this subject.
     * DELETE /subjects/{subject}/students/{studentId}
     */
    public function unenroll(Subject $subject, int $studentId): JsonResponse
    {
        $subject->students()->detach($studentId);

        return response()->json([
            'message' => 'Student removed from subject.',
        ]);
    }
}
