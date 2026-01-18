<?php

namespace App\Http\Controllers;

use App\Models\GradingSystem;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class GradingSystemController extends Controller
{
    /**
     * Get grading systems for school
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // In tenant context, just get the first (and only) school
            $school = School::first();

            if (!$school) {
                return response()->json(['error' => 'School not found'], 404);
            }

            $systems = GradingSystem::where('school_id', $school->id)->get();

            return response()->json(['grading_systems' => $systems]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch grading systems',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get default grading system
     */
    public function getDefault(Request $request): JsonResponse
    {
        try {
            // In tenant context, just get the first (and only) school
            $school = School::first();

            if (!$school) {
                return response()->json(['error' => 'School not found'], 404);
            }

            $system = GradingSystem::where('school_id', $school->id)
                ->where('is_default', true)
                ->first();

            // Return null if no default exists instead of 404
            return response()->json(['grading_system' => $system]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch default grading system',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create grading system
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'grade_boundaries' => 'required|array',
            'grade_boundaries.*.min' => 'required|numeric',
            'grade_boundaries.*.max' => 'required|numeric',
            'grade_boundaries.*.grade' => 'required|string',
            'grade_boundaries.*.remark' => 'required|string',
            'pass_mark' => 'required|numeric|min:0|max:100',
            'is_default' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            // In tenant context, get the first (and only) school
            $school = School::first();

            if (!$school) {
                return response()->json(['error' => 'School not found'], 400);
            }

            DB::beginTransaction();

            // If setting as default, unset other defaults
            if ($request->is_default) {
                GradingSystem::where('school_id', $school->id)
                    ->update(['is_default' => false]);
            }

            $system = GradingSystem::create([
                'school_id' => $school->id,
                'name' => $request->name,
                'description' => $request->description,
                'grade_boundaries' => $request->grade_boundaries,
                'pass_mark' => $request->pass_mark,
                'is_default' => $request->is_default ?? false,
                'status' => 'active',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Grading system created successfully',
                'grading_system' => $system
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to create grading system',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update grading system
     */
    public function update(Request $request, $id): JsonResponse
    {
        $system = GradingSystem::find($id);

        if (!$system) {
            return response()->json(['error' => 'Grading system not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'grade_boundaries' => 'sometimes|array',
            'pass_mark' => 'sometimes|numeric|min:0|max:100',
            'is_default' => 'boolean',
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

            if ($request->has('is_default') && $request->is_default) {
                GradingSystem::where('school_id', $system->school_id)
                    ->where('id', '!=', $id)
                    ->update(['is_default' => false]);
            }

            $system->update($request->only([
                'name', 'description', 'grade_boundaries', 'pass_mark', 'is_default', 'status'
            ]));

            DB::commit();

            return response()->json([
                'message' => 'Grading system updated successfully',
                'grading_system' => $system->fresh()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to update grading system',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete grading system
     */
    public function destroy($id): JsonResponse
    {
        $system = GradingSystem::find($id);

        if (!$system) {
            return response()->json(['error' => 'Grading system not found'], 404);
        }

        if ($system->is_default) {
            return response()->json([
                'error' => 'Cannot delete default grading system'
            ], 400);
        }

        try {
            $system->delete();
            return response()->json(['message' => 'Grading system deleted successfully']);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete grading system',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get grade for a score
     */
    public function getGradeForScore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'score' => 'required|numeric|min:0|max:100',
            'grading_system_id' => 'nullable|exists:grading_systems,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            if ($request->grading_system_id) {
                $system = GradingSystem::find($request->grading_system_id);
            } else {
                // In tenant context, get the first (and only) school
                $school = School::first();
                $system = GradingSystem::where('school_id', $school->id)
                    ->where('is_default', true)
                    ->first();
            }

            if (!$system) {
                return response()->json(['error' => 'Grading system not found'], 404);
            }

            $gradeInfo = $system->getGrade($request->score);

            return response()->json([
                'score' => $request->score,
                'grade' => $gradeInfo['grade'],
                'remark' => $gradeInfo['remark'],
                'is_passing' => $system->isPassing($request->score)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to calculate grade',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}

