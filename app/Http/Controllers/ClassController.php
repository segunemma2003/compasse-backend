<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ClassController extends Controller
{
    /**
     * List all classes for the current school.
     */
    public function index(): JsonResponse
    {
        try {
            $classes = ClassModel::with([
                    'classTeacher:id,first_name,last_name,employee_id',
                    'school:id,name',
                    'classLevel:id,name,order',
                ])
                ->withCount(['students', 'arms'])
                ->orderBy('name')
                ->get();

            return response()->json(['data' => $classes]);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to fetch classes',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new class.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'             => 'required|string|max:255',
            'level'            => 'nullable|string|max:255',
            'class_level_id'   => 'nullable|exists:class_levels,id',
            'section_type'     => 'nullable|in:nursery,primary,junior_secondary,senior_secondary,tertiary,custom',
            'description'      => 'nullable|string|max:1000',
            'academic_year_id' => 'required|exists:academic_years,id',
            'term_id'          => 'nullable|exists:terms,id',
            'class_teacher_id' => 'nullable|exists:teachers,id',
            'capacity'         => 'nullable|integer|min:1',
        ]);

        $school = School::first();
        if (! $school) {
            return response()->json(['error' => 'School not found'], 400);
        }

        $class = ClassModel::create([
            'school_id'        => $school->id,
            'name'             => $request->input('name'),
            'level'            => $request->input('level'),
            'class_level_id'   => $request->input('class_level_id'),
            'section_type'     => $request->input('section_type'),
            'description'      => $request->input('description'),
            'academic_year_id' => $request->input('academic_year_id'),
            'term_id'          => $request->input('term_id'),
            'class_teacher_id' => $request->input('class_teacher_id'),
            'capacity'         => $request->input('capacity'),
        ]);

        $class->load(['classTeacher:id,first_name,last_name,employee_id', 'classLevel:id,name,order']);

        return response()->json([
            'message' => 'Class created successfully.',
            'class'   => $class,
        ], 201);
    }

    /**
     * Show a single class with its students.
     */
    public function show(ClassModel $class): JsonResponse
    {
        $class->load([
            'classTeacher:id,first_name,last_name,employee_id',
            'school:id,name',
        ]);
        $class->loadCount('students');

        return response()->json(['class' => $class]);
    }

    /**
     * Update a class.
     */
    public function update(Request $request, ClassModel $class): JsonResponse
    {
        $request->validate([
            'name'             => 'sometimes|string|max:255',
            'level'            => 'nullable|string|max:255',
            'class_level_id'   => 'nullable|exists:class_levels,id',
            'section_type'     => 'nullable|in:nursery,primary,junior_secondary,senior_secondary,tertiary,custom',
            'description'      => 'nullable|string|max:1000',
            'academic_year_id' => 'sometimes|exists:academic_years,id',
            'term_id'          => 'nullable|exists:terms,id',
            'class_teacher_id' => 'nullable|exists:teachers,id',
            'capacity'         => 'nullable|integer|min:1',
        ]);

        $class->update($request->only([
            'name', 'level', 'class_level_id', 'section_type', 'description',
            'academic_year_id', 'term_id', 'class_teacher_id', 'capacity',
        ]));

        $class->load(['classTeacher:id,first_name,last_name,employee_id', 'classLevel:id,name,order']);

        return response()->json([
            'message' => 'Class updated successfully.',
            'class'   => $class,
        ]);
    }

    /**
     * Delete a class. Blocks if students are enrolled.
     */
    public function destroy(ClassModel $class): JsonResponse
    {
        $count = $class->students()->count();
        if ($count > 0) {
            return response()->json([
                'error'   => 'Cannot delete class',
                'message' => "Move or remove the {$count} enrolled student(s) first.",
                'students_count' => $count,
            ], 422);
        }

        $class->delete();

        return response()->json(['message' => 'Class deleted.']);
    }

    /**
     * Get students enrolled in a class.
     */
    public function getStudents(ClassModel $class): JsonResponse
    {
        try {
            $students = $class->students()
                ->with(['arm:id,name', 'user:id,name,email'])
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get();

            return response()->json([
                'class'    => ['id' => $class->id, 'name' => $class->name],
                'students' => $students,
                'total'    => $students->count(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to fetch class students',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
