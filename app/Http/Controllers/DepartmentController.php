<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DepartmentController extends Controller
{
    /**
     * List all departments for the current school, with HOD name and counts.
     */
    public function index(): JsonResponse
    {
        $school = School::first();

        if (! $school) {
            return response()->json(['data' => []]);
        }

        $departments = Department::with(['head:id,first_name,last_name,employee_id'])
            ->withCount(['teachers', 'subjects'])
            ->where('school_id', $school->id)
            ->orderBy('name')
            ->get()
            ->map(function ($dept) {
                return [
                    'id'             => $dept->id,
                    'name'           => $dept->name,
                    'description'    => $dept->description,
                    'status'         => $dept->status,
                    'head_id'        => $dept->head_id,
                    'head_name'      => $dept->head
                        ? trim("{$dept->head->first_name} {$dept->head->last_name}")
                        : null,
                    'head_employee_id' => $dept->head?->employee_id,
                    'teachers_count' => $dept->teachers_count,
                    'subjects_count' => $dept->subjects_count,
                    'created_at'     => $dept->created_at,
                    'updated_at'     => $dept->updated_at,
                ];
            });

        return response()->json(['data' => $departments]);
    }

    /**
     * Create a new department.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'head_id'     => 'nullable|integer|exists:teachers,id',
            'status'      => 'nullable|in:active,inactive',
        ]);

        $school = School::first();
        if (! $school) {
            return response()->json(['error' => 'School not found'], 400);
        }

        $department = Department::create([
            'school_id'   => $school->id,
            'name'        => $request->input('name'),
            'description' => $request->input('description'),
            'head_id'     => $request->input('head_id'),
            'status'      => $request->input('status', 'active'),
        ]);

        $department->load('head:id,first_name,last_name,employee_id');

        return response()->json([
            'message'    => 'Department created successfully.',
            'department' => $this->formatDepartment($department),
        ], 201);
    }

    /**
     * Show a single department with its teachers and subjects.
     */
    public function show(Department $department): JsonResponse
    {
        $department->load([
            'head:id,first_name,last_name,employee_id',
            'teachers:id,first_name,last_name,employee_id,department_id',
            'subjects:id,name,code,department_id',
        ]);

        return response()->json([
            'department' => array_merge(
                $this->formatDepartment($department),
                [
                    'teachers' => $department->teachers,
                    'subjects' => $department->subjects,
                ]
            ),
        ]);
    }

    /**
     * Update a department.
     */
    public function update(Request $request, Department $department): JsonResponse
    {
        $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'head_id'     => 'nullable|integer|exists:teachers,id',
            'status'      => 'sometimes|in:active,inactive',
        ]);

        $department->update($request->only(['name', 'description', 'head_id', 'status']));
        $department->load('head:id,first_name,last_name,employee_id');

        return response()->json([
            'message'    => 'Department updated successfully.',
            'department' => $this->formatDepartment($department),
        ]);
    }

    /**
     * Delete a department. Blocks deletion when teachers or subjects are still assigned.
     */
    public function destroy(Department $department): JsonResponse
    {
        $teacherCount = $department->teachers()->count();
        $subjectCount = $department->subjects()->count();

        if ($teacherCount > 0 || $subjectCount > 0) {
            return response()->json([
                'error'   => 'Cannot delete department',
                'message' => "Reassign or remove the {$teacherCount} teacher(s) and {$subjectCount} subject(s) in this department first.",
                'teachers_count' => $teacherCount,
                'subjects_count' => $subjectCount,
            ], 422);
        }

        $department->delete();

        return response()->json(['message' => 'Department deleted.']);
    }

    // ── Private helper ────────────────────────────────────────────────────────

    private function formatDepartment(Department $department): array
    {
        return [
            'id'               => $department->id,
            'name'             => $department->name,
            'description'      => $department->description,
            'status'           => $department->status,
            'head_id'          => $department->head_id,
            'head_name'        => $department->head
                ? trim("{$department->head->first_name} {$department->head->last_name}")
                : null,
            'head_employee_id' => $department->head?->employee_id,
            'created_at'       => $department->created_at,
            'updated_at'       => $department->updated_at,
        ];
    }
}
