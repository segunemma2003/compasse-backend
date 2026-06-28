<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

class ClassController extends Controller
{
    /**
     * List all classes for the current school.
     */
    public function index(): JsonResponse
    {
        try {
            $eagerLoads = [
                'classTeacher:id,first_name,last_name,employee_id',
                'school:id,name',
                'arms:id,name,status',
            ];

            // Only eager-load classLevel when the table exists (production may lag on migrations)
            if (Schema::hasTable('class_levels')) {
                $eagerLoads[] = 'classLevel:id,name,order';
            }

            $counts = ['students'];
            // Only count arms when the pivot table exists
            if (Schema::hasTable('class_arm')) {
                $counts[] = 'arms';
            }

            $classes = ClassModel::with($eagerLoads)
                ->withCount($counts)
                ->orderBy('name')
                ->get()
                ->map(function ($class) {
                    $data = $class->toArray();
                    $data['class_id'] = $class->id;
                    $data['arms'] = $class->arms->map(fn($arm) => [
                        'arm_id'   => $arm->id,
                        'name'     => $arm->name,
                        'capacity' => $arm->pivot->capacity ?? null,
                    ]);
                    return $data;
                });

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
        $classLevelRule = Schema::hasTable('class_levels') ? 'nullable|exists:class_levels,id' : 'nullable';
        $request->validate([
            'name'             => 'required|string|max:255',
            'level'            => 'nullable|string|max:255',
            'class_level_id'   => $classLevelRule,
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

        // Derive `level` and auto-detect `section_type` from the class level record.
        $level       = $request->input('level');
        $sectionType = $request->input('section_type');

        if ($request->input('class_level_id')) {
            try {
                if (Schema::hasTable('class_levels')) {
                    $cl = \App\Models\ClassLevel::find($request->input('class_level_id'));
                    if ($cl) {
                        if (! $level) $level = $cl->name;

                        // Auto-derive section_type from the level name when not supplied.
                        if (! $sectionType) {
                            $n = strtolower($cl->name);
                            if (str_contains($n, 'nursery'))                              $sectionType = 'nursery';
                            elseif (str_contains($n, 'primary'))                          $sectionType = 'primary';
                            elseif (str_contains($n, 'jss') || str_contains($n, 'junior')) $sectionType = 'junior_secondary';
                            elseif (str_contains($n, 'sss') || str_contains($n, 'senior')) $sectionType = 'senior_secondary';
                            elseif (str_contains($n, 'tertiary') || str_contains($n, 'university')) $sectionType = 'tertiary';
                        }
                    }
                }
            } catch (\Exception) {}
        }

        // Final fallbacks so NOT NULL columns are never null.
        if (! $level)       $level       = $request->input('name');
        if (! $sectionType) $sectionType = 'custom';

        $class = ClassModel::create([
            'school_id'        => $school->id,
            'name'             => $request->input('name'),
            'level'            => $level,
            'class_level_id'   => $request->input('class_level_id'),
            'section_type'     => $sectionType,
            'description'      => $request->input('description'),
            'academic_year_id' => $request->input('academic_year_id'),
            'term_id'          => $request->input('term_id'),
            'class_teacher_id' => $request->input('class_teacher_id'),
            'capacity'         => $request->input('capacity'),
        ]);

        $loadRelations = ['classTeacher:id,first_name,last_name,employee_id'];
        if (Schema::hasTable('class_levels')) $loadRelations[] = 'classLevel:id,name,order';
        $class->load($loadRelations);

        return response()->json([
            'message' => 'Class created successfully.',
            'class'   => $class,
        ], 201);
    }

    /**
     * Show a single class with its students and arms.
     */
    public function show(ClassModel $class): JsonResponse
    {
        $class->load([
            'classTeacher:id,first_name,last_name,employee_id',
            'school:id,name',
            'arms:id,name,description,status',
        ]);
        $class->loadCount('students');

        // Build a clean arms list with explicit class_id + arm_id for bulk upload reference
        $arms = $class->arms->map(fn($arm) => [
            'arm_id'   => $arm->id,
            'name'     => $arm->name,
            'status'   => $arm->pivot->status ?? $arm->status,
            'capacity' => $arm->pivot->capacity ?? null,
        ]);

        $data = $class->toArray();
        $data['class_id'] = $class->id;
        $data['arms']     = $arms;

        return response()->json(['class' => $data]);
    }

    /**
     * Update a class.
     */
    public function update(Request $request, ClassModel $class): JsonResponse
    {
        $classLevelRule = Schema::hasTable('class_levels') ? 'nullable|exists:class_levels,id' : 'nullable';
        $request->validate([
            'name'             => 'sometimes|string|max:255',
            'level'            => 'nullable|string|max:255',
            'class_level_id'   => $classLevelRule,
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

        $loadRelations = ['classTeacher:id,first_name,last_name,employee_id'];
        if (Schema::hasTable('class_levels')) $loadRelations[] = 'classLevel:id,name,order';
        $class->load($loadRelations);

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
