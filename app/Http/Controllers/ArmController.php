<?php

namespace App\Http\Controllers;

use App\Models\Arm;
use App\Models\ClassModel;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ArmController extends Controller
{
    /**
     * Display a listing of arms
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Arm::with(['class', 'students', 'classTeacher']);

            // Filter by class_id
            if ($request->has('class_id')) {
                $query->where('class_id', $request->class_id);
            }

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $arms = $query->withCount('students')->get();

            // Add statistics to each arm
            $arms->each(function ($arm) {
                $arm->stats = $arm->getStats();
                $arm->is_full = $arm->isFull();
                $arm->available_capacity = $arm->getAvailableCapacity();
            });

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
     * Store a newly created arm
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'class_id' => 'required|exists:classes,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
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
            DB::beginTransaction();

            $armData = [
                'class_id' => $request->class_id,
                'name' => $request->name,
                'description' => $request->description,
                'capacity' => $request->capacity ?? 30,
                'class_teacher_id' => $request->class_teacher_id,
                'status' => $request->status ?? 'active',
            ];

            $arm = Arm::create($armData);
            $arm->load(['class', 'classTeacher']);

            DB::commit();

            return response()->json([
                'message' => 'Arm created successfully',
                'arm' => $arm
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to create arm',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified arm
     */
    public function show($id): JsonResponse
    {
        try {
            $arm = Arm::with(['class', 'students', 'classTeacher'])
                ->withCount('students')
                ->find($id);

            if (!$arm) {
                return response()->json([
                    'error' => 'Arm not found'
                ], 404);
            }

            $arm->stats = $arm->getStats();
            $arm->is_full = $arm->isFull();
            $arm->available_capacity = $arm->getAvailableCapacity();

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
     * Update the specified arm
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
            'class_id' => 'sometimes|exists:classes,id',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'capacity' => 'sometimes|integer|min:1',
            'class_teacher_id' => 'nullable|exists:teachers,id',
            'status' => 'sometimes|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $arm->update($request->only([
                'class_id', 'name', 'description', 'capacity', 'class_teacher_id', 'status'
            ]));

            $arm->load(['class', 'classTeacher']);

            DB::commit();

            return response()->json([
                'message' => 'Arm updated successfully',
                'arm' => $arm->fresh()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to update arm',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified arm
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
     * Get arms for a specific class
     */
    public function getByClass($classId): JsonResponse
    {
        try {
            $class = ClassModel::find($classId);

            if (!$class) {
                return response()->json([
                    'error' => 'Class not found'
                ], 404);
            }

            $arms = Arm::where('class_id', $classId)
                ->with(['classTeacher'])
                ->withCount('students')
                ->get();

            $arms->each(function ($arm) {
                $arm->stats = $arm->getStats();
                $arm->is_full = $arm->isFull();
                $arm->available_capacity = $arm->getAvailableCapacity();
            });

            return response()->json([
                'class' => $class,
                'arms' => $arms,
                'total_arms' => $arms->count(),
                'total_capacity' => $arms->sum('capacity'),
                'total_students' => $arms->sum('students_count')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch arms for class',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get students in a specific arm
     */
    public function getStudents($id): JsonResponse
    {
        try {
            $arm = Arm::with(['class', 'classTeacher'])->find($id);

            if (!$arm) {
                return response()->json([
                    'error' => 'Arm not found'
                ], 404);
            }

            $students = $arm->students()
                ->with(['user', 'guardians'])
                ->orderBy('first_name')
                ->get();

            return response()->json([
                'arm' => $arm,
                'students' => $students,
                'total_students' => $students->count(),
                'capacity' => $arm->capacity,
                'available_capacity' => $arm->getAvailableCapacity()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch students',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign class teacher to arm
     */
    public function assignTeacher(Request $request, $id): JsonResponse
    {
        $arm = Arm::find($id);

        if (!$arm) {
            return response()->json([
                'error' => 'Arm not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'class_teacher_id' => 'required|exists:teachers,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $arm->update([
                'class_teacher_id' => $request->class_teacher_id
            ]);

            $arm->load(['classTeacher']);

            return response()->json([
                'message' => 'Teacher assigned successfully',
                'arm' => $arm
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to assign teacher',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}

