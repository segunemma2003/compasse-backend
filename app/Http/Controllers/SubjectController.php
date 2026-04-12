<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use App\Models\School;
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
                ])
                ->withCount(['students', 'assignments', 'exams'])
                ->orderBy('name')
                ->get();

            return response()->json(['subjects' => $subjects]);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to fetch subjects',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new subject.
     *
     * `department_id` is optional — schools with no departments yet can still
     * create subjects and assign a department later via update.
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
            'is_elective'   => 'nullable|boolean',
        ]);

        $school = School::first();
        if (! $school) {
            return response()->json(['error' => 'School not found'], 400);
        }

        $subject = Subject::create([
            'school_id'     => $school->id,
            'name'          => $request->input('name'),
            'code'          => $request->input('code'),
            'description'   => $request->input('description'),
            'department_id' => $request->input('department_id'),
            'class_id'      => $request->input('class_id'),
            'teacher_id'    => $request->input('teacher_id'),
            'credits'       => $request->input('credits'),
            'is_elective'   => $request->boolean('is_elective', false),
        ]);

        $subject->load([
            'department:id,name',
            'teacher:id,first_name,last_name,employee_id',
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
            'is_elective'   => 'nullable|boolean',
        ]);

        $subject->update($request->only([
            'name', 'code', 'description', 'department_id',
            'class_id', 'teacher_id', 'credits', 'is_elective',
        ]));

        $subject->load([
            'department:id,name',
            'teacher:id,first_name,last_name,employee_id',
        ]);

        return response()->json([
            'message' => 'Subject updated successfully.',
            'subject' => $subject,
        ]);
    }

    /**
     * Delete a subject. Blocks if exams or assignments reference it.
     */
    public function destroy(Subject $subject): JsonResponse
    {
        $examCount = $subject->exams()->count();
        $assignCount = $subject->assignments()->count();

        if ($examCount > 0 || $assignCount > 0) {
            return response()->json([
                'error'   => 'Cannot delete subject',
                'message' => "Remove the {$examCount} exam(s) and {$assignCount} assignment(s) linked to this subject first.",
                'exams_count'       => $examCount,
                'assignments_count' => $assignCount,
            ], 422);
        }

        $subject->delete();

        return response()->json(['message' => 'Subject deleted.']);
    }
}
