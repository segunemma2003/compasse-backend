<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class HouseController extends Controller
{
    /**
     * List houses
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $houses = DB::table('houses')
                ->orderBy('name')
                ->get();

            return response()->json(['houses' => $houses]);
        } catch (\Exception $e) {
            return response()->json(['houses' => []]);
        }
    }

    /**
     * Get house details
     */
    public function show($id): JsonResponse
    {
        $house = DB::table('houses')->find($id);

        if (!$house) {
            return response()->json(['error' => 'House not found'], 404);
        }

        $members = DB::table('house_members')
            ->where('house_id', $id)
            ->count();

        $totalPoints = DB::table('house_points')
            ->where('house_id', $id)
            ->sum('points');

        return response()->json([
            'house' => $house,
            'members_count' => $members,
            'total_points' => $totalPoints
        ]);
    }

    /**
     * Create house
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'house_master_id' => 'nullable|exists:teachers,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $houseId = DB::table('houses')->insertGetId([
            'school_id' => $request->school_id ?? 1,
            'name' => $request->name,
            'color' => $request->color,
            'description' => $request->description,
            'house_master_id' => $request->house_master_id,
            'total_points' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $house = DB::table('houses')->find($houseId);

        return response()->json([
            'message' => 'House created successfully',
            'house' => $house
        ], 201);
    }

    /**
     * Update house
     */
    public function update(Request $request, $id): JsonResponse
    {
        $house = DB::table('houses')->find($id);

        if (!$house) {
            return response()->json(['error' => 'House not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'color' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'house_master_id' => 'nullable|exists:teachers,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        DB::table('houses')
            ->where('id', $id)
            ->update(array_merge(
                $request->only(['name', 'color', 'description', 'house_master_id']),
                ['updated_at' => now()]
            ));

        $house = DB::table('houses')->find($id);

        return response()->json([
            'message' => 'House updated successfully',
            'house' => $house
        ]);
    }

    /**
     * Delete house
     */
    public function destroy($id): JsonResponse
    {
        $house = DB::table('houses')->find($id);

        if (!$house) {
            return response()->json(['error' => 'House not found'], 404);
        }

        DB::table('houses')->where('id', $id)->delete();

        return response()->json([
            'message' => 'House deleted successfully'
        ]);
    }

    /**
     * Get house members
     */
    public function getMembers($id): JsonResponse
    {
        $house = DB::table('houses')->find($id);

        if (!$house) {
            return response()->json(['error' => 'House not found'], 404);
        }

        $memberIds = DB::table('house_members')
            ->where('house_id', $id)
            ->pluck('student_id');

        $members = DB::table('students')
            ->whereIn('id', $memberIds)
            ->get();

        return response()->json([
            'house_id' => $id,
            'members' => $members
        ]);
    }

    /**
     * Add house points
     */
    public function addPoints(Request $request, $id): JsonResponse
    {
        $house = DB::table('houses')->find($id);

        if (!$house) {
            return response()->json(['error' => 'House not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'points' => 'required|integer',
            'reason' => 'required|string|max:255',
            'type' => 'nullable|in:award,deduction',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $points = $request->points;
        $type = $request->type ?? 'award';

        // If deduction, make points negative
        if ($type === 'deduction') {
            $points = -abs($points);
        }

        DB::table('house_points')->insert([
            'house_id' => $id,
            'points' => $points,
            'reason' => $request->reason,
            'type' => $type,
            'awarded_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Update house total points
        DB::table('houses')
            ->where('id', $id)
            ->increment('total_points', abs($points));

        $house = DB::table('houses')->find($id);

        return response()->json([
            'message' => 'Points added successfully',
            'house' => $house
        ]);
    }

    /**
     * Get house points history
     */
    public function getPoints($id): JsonResponse
    {
        $house = DB::table('houses')->find($id);

        if (!$house) {
            return response()->json(['error' => 'House not found'], 404);
        }

        $points = DB::table('house_points')
            ->where('house_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'house_id' => $id,
            'points_history' => $points
        ]);
    }

    /**
     * Get house competitions
     */
    public function getCompetitions(Request $request): JsonResponse
    {
        // This would typically come from sports_events or a competitions table
        // For now, return empty array
        return response()->json([
            'competitions' => []
        ]);
    }
}
