<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AchievementController extends Controller
{
    /**
     * List achievements
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = DB::table('achievements');

            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            if ($request->has('student_id')) {
                $query->where('student_id', $request->student_id);
            }

            $achievements = $query->orderBy('achievement_date', 'desc')
                ->paginate($request->get('per_page', 15));

            return response()->json($achievements);
        } catch (\Exception $e) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'per_page' => 15,
                'total' => 0
            ]);
        }
    }

    /**
     * Get student achievements
     */
    public function getStudentAchievements($studentId): JsonResponse
    {
        $achievements = DB::table('achievements')
            ->where('student_id', $studentId)
            ->orderBy('achievement_date', 'desc')
            ->get();

        return response()->json([
            'student_id' => $studentId,
            'achievements' => $achievements
        ]);
    }

    /**
     * Create achievement
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|exists:students,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|in:academic,sports,arts,leadership,community,other',
            'category' => 'nullable|string|max:100',
            'achievement_date' => 'required|date',
            'awarded_by' => 'nullable|string|max:255',
            'certificate_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $achievementId = DB::table('achievements')->insertGetId([
            'school_id' => $request->school_id ?? 1,
            'student_id' => $request->student_id,
            'title' => $request->title,
            'description' => $request->description,
            'type' => $request->type ?? 'academic',
            'category' => $request->category,
            'achievement_date' => $request->achievement_date,
            'awarded_by' => $request->awarded_by,
            'certificate_url' => $request->certificate_url,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $achievement = DB::table('achievements')->find($achievementId);

        return response()->json([
            'message' => 'Achievement created successfully',
            'achievement' => $achievement
        ], 201);
    }

    /**
     * Update achievement
     */
    public function update(Request $request, $id): JsonResponse
    {
        $achievement = DB::table('achievements')->find($id);

        if (!$achievement) {
            return response()->json(['error' => 'Achievement not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|in:academic,sports,arts,leadership,community,other',
            'achievement_date' => 'sometimes|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        DB::table('achievements')
            ->where('id', $id)
            ->update(array_merge(
                $request->only(['title', 'description', 'type', 'achievement_date', 'certificate_url']),
                ['updated_at' => now()]
            ));

        $achievement = DB::table('achievements')->find($id);

        return response()->json([
            'message' => 'Achievement updated successfully',
            'achievement' => $achievement
        ]);
    }

    /**
     * Delete achievement
     */
    public function destroy($id): JsonResponse
    {
        $achievement = DB::table('achievements')->find($id);

        if (!$achievement) {
            return response()->json(['error' => 'Achievement not found'], 404);
        }

        DB::table('achievements')->where('id', $id)->delete();

        return response()->json([
            'message' => 'Achievement deleted successfully'
        ]);
    }
}
