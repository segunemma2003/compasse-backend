<?php

namespace App\Http\Controllers;

use App\Models\Arm;
use App\Models\ClassModel;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ArmController extends Controller
{
    /**
     * Display a listing of global arms
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // In tenant context, just get the first (and only) school
            $school = School::first();

            if (!$school) {
                return response()->json([
                    'error' => 'School not found'
                ], 404);
            }

            $arms = Arm::where('school_id', $school->id)
                ->when($request->has('status'), function ($query) use ($request) {
                    $query->where('status', $request->status);
                })
                ->with('classes')
                ->get();

            return response()->json([
                'arms' => $arms
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch arms',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new global arm
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            // Get school_id from subdomain
            // In tenant context, get the first (and only) school
            $school = School::first();

            if (!$school) {
                return response()->json([
                    'error' => 'School not found'
                ], 400);
            }

            // Check if arm with same name exists for this school
            $existingArm = Arm::where('school_id', $school->id)
                ->where('name', $request->name)
                ->first();

            if ($existingArm) {
                return response()->json([
                    'error' => 'An arm with this name already exists'
                ], 400);
            }

            $arm = Arm::create([
                'school_id' => $school->id,
                'name' => $request->name,
                'description' => $request->description,
                'status' => $request->status ?? 'active',
            ]);

            return response()->json([
                'message' => 'Arm created successfully',
                'arm' => $arm
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create arm',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific arm
     */
    public function show($id): JsonResponse
    {
        try {
            $arm = Arm::with('classes')->find($id);

            if (!$arm) {
                return response()->json([
                    'error' => 'Arm not found'
                ], 404);
            }

            return response()->json([
                'arm' => $arm
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch arm',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an arm
     */
    public function update(Request $request, $id): JsonResponse
    {
        $arm = Arm::find($id);

        if (!$arm) {
            return response()->json([
                'error' => 'Arm not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $arm->update($request->only(['name', 'description', 'status']));

            return response()->json([
                'message' => 'Arm updated successfully',
                'arm' => $arm->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update arm',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an arm
     */
    public function destroy($id): JsonResponse
    {
        $arm = Arm::find($id);

        if (!$arm) {
            return response()->json([
                'error' => 'Arm not found'
            ], 404);
        }

        try {
            // Check if arm is being used by any class
            if ($arm->classes()->count() > 0) {
                return response()->json([
                    'error' => 'Cannot delete arm that is assigned to classes',
                    'message' => 'Please remove this arm from all classes before deleting'
                ], 400);
            }

            // Check if arm has students
            if ($arm->students()->count() > 0) {
                return response()->json([
                    'error' => 'Cannot delete arm with existing students',
                    'message' => 'Please move or remove students before deleting this arm'
                ], 400);
            }

            $arm->delete();

            return response()->json([
                'message' => 'Arm deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete arm',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign arm to a class
     */
    public function assignToClass(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'class_id' => 'required|exists:classes,id',
            'arm_id' => 'required|exists:arms,id',
            'capacity' => 'nullable|integer|min:1',
            'class_teacher_id' => 'nullable|exists:teachers,id',
            'status' => 'nullable|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $class = ClassModel::find($request->class_id);
            $arm = Arm::find($request->arm_id);

            // Check if already assigned
            if ($class->arms()->where('arm_id', $arm->id)->exists()) {
                return response()->json([
                    'error' => 'This arm is already assigned to this class'
                ], 400);
            }

            $class->arms()->attach($arm->id, [
                'capacity' => $request->capacity ?? 30,
                'class_teacher_id' => $request->class_teacher_id,
                'status' => $request->status ?? 'active',
            ]);

            return response()->json([
                'message' => 'Arm assigned to class successfully',
                'class' => $class->load('arms')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to assign arm to class',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove arm from a class
     */
    public function removeFromClass(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'class_id' => 'required|exists:classes,id',
            'arm_id' => 'required|exists:arms,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $class = ClassModel::find($request->class_id);
            $arm = Arm::find($request->arm_id);

            // Check if there are students in this class-arm combination
            $studentsCount = $arm->students()
                ->where('class_id', $class->id)
                ->count();

            if ($studentsCount > 0) {
                return response()->json([
                    'error' => 'Cannot remove arm from class with existing students',
                    'message' => "There are $studentsCount students in this class-arm combination"
                ], 400);
            }

            $class->arms()->detach($arm->id);

            return response()->json([
                'message' => 'Arm removed from class successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to remove arm from class',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get arms for a specific class
     */
    public function getByClass($classId): JsonResponse
    {
        try {
            $class = ClassModel::with(['arms' => function ($query) {
                $query->withPivot(['capacity', 'class_teacher_id', 'status']);
            }])->find($classId);

            if (!$class) {
                return response()->json([
                    'error' => 'Class not found'
                ], 404);
            }

            $armsWithStats = $class->arms->map(function ($arm) use ($class) {
                $stats = $arm->getStatsForClass($class->id);
                return [
                    'id' => $arm->id,
                    'name' => $arm->name,
                    'description' => $arm->description,
                    'capacity' => $arm->pivot->capacity,
                    'class_teacher_id' => $arm->pivot->class_teacher_id,
                    'status' => $arm->pivot->status,
                    'students_count' => $stats['total_students'],
                    'capacity_utilization' => $stats['capacity_utilization'],
                    'available_capacity' => $stats['available_capacity'],
                ];
            });

            return response()->json([
                'class' => $class,
                'arms' => $armsWithStats,
                'total_arms' => $armsWithStats->count(),
                'total_capacity' => $armsWithStats->sum('capacity'),
                'total_students' => $armsWithStats->sum('students_count')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch arms for class',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get students in a specific class-arm combination
     */
    public function getStudents(Request $request, $armId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'class_id' => 'required|exists:classes,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $arm = Arm::find($armId);
            $class = ClassModel::find($request->class_id);

            if (!$arm) {
                return response()->json([
                    'error' => 'Arm not found'
                ], 404);
            }

            $students = $arm->students()
                ->where('class_id', $class->id)
                ->with(['user', 'guardians'])
                ->orderBy('first_name')
                ->get();

            $stats = $arm->getStatsForClass($class->id);

            return response()->json([
                'arm' => $arm,
                'class' => $class,
                'students' => $students,
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch students',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
