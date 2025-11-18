<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class SportController extends Controller
{
    /**
     * List sports activities
     */
    public function getActivities(Request $request): JsonResponse
    {
        try {
            $query = DB::table('sports_activities');

            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $activities = $query->orderBy('name')->get();

            return response()->json(['activities' => $activities]);
        } catch (\Exception $e) {
            return response()->json(['activities' => []]);
        }
    }

    /**
     * Create sports activity
     */
    public function createActivity(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|in:individual,team',
            'category' => 'nullable|string|max:100',
            'coach_id' => 'nullable|exists:teachers,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $activityId = DB::table('sports_activities')->insertGetId([
            'school_id' => $request->school_id ?? 1,
            'name' => $request->name,
            'description' => $request->description,
            'type' => $request->type ?? 'team',
            'category' => $request->category,
            'coach_id' => $request->coach_id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $activity = DB::table('sports_activities')->find($activityId);

        return response()->json([
            'message' => 'Sports activity created successfully',
            'activity' => $activity
        ], 201);
    }

    /**
     * Update sports activity
     */
    public function updateActivity(Request $request, $id): JsonResponse
    {
        $activity = DB::table('sports_activities')->find($id);

        if (!$activity) {
            return response()->json(['error' => 'Activity not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        DB::table('sports_activities')
            ->where('id', $id)
            ->update(array_merge(
                $request->only(['name', 'description', 'status']),
                ['updated_at' => now()]
            ));

        $activity = DB::table('sports_activities')->find($id);

        return response()->json([
            'message' => 'Activity updated successfully',
            'activity' => $activity
        ]);
    }

    /**
     * Delete sports activity
     */
    public function deleteActivity($id): JsonResponse
    {
        $activity = DB::table('sports_activities')->find($id);

        if (!$activity) {
            return response()->json(['error' => 'Activity not found'], 404);
        }

        DB::table('sports_activities')->where('id', $id)->delete();

        return response()->json([
            'message' => 'Activity deleted successfully'
        ]);
    }

    /**
     * List sports teams
     */
    public function getTeams(Request $request): JsonResponse
    {
        try {
            $query = DB::table('sports_teams');

            if ($request->has('activity_id')) {
                $query->where('activity_id', $request->activity_id);
            }

            $teams = $query->orderBy('name')->get();

            return response()->json(['teams' => $teams]);
        } catch (\Exception $e) {
            return response()->json(['teams' => []]);
        }
    }

    /**
     * Create team
     */
    public function createTeam(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'activity_id' => 'required|exists:sports_activities,id',
            'name' => 'required|string|max:255',
            'gender' => 'nullable|in:male,female,mixed',
            'age_group' => 'nullable|string|max:50',
            'captain_id' => 'nullable|exists:students,id',
            'coach_id' => 'nullable|exists:teachers,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $teamId = DB::table('sports_teams')->insertGetId([
            'school_id' => $request->school_id ?? 1,
            'activity_id' => $request->activity_id,
            'name' => $request->name,
            'gender' => $request->gender ?? 'mixed',
            'age_group' => $request->age_group,
            'captain_id' => $request->captain_id,
            'coach_id' => $request->coach_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $team = DB::table('sports_teams')->find($teamId);

        return response()->json([
            'message' => 'Team created successfully',
            'team' => $team
        ], 201);
    }

    /**
     * List sports events
     */
    public function getEvents(Request $request): JsonResponse
    {
        try {
            $query = DB::table('sports_events');

            if ($request->has('activity_id')) {
                $query->where('activity_id', $request->activity_id);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $events = $query->orderBy('event_date', 'desc')->get();

            return response()->json(['events' => $events]);
        } catch (\Exception $e) {
            // Table doesn't exist or query failed
            return response()->json(['events' => []]);
        }
    }

    /**
     * Create sports event
     */
    public function createEvent(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'activity_id' => 'nullable|exists:sports_activities,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'event_date' => 'required|date',
            'location' => 'nullable|string|max:255',
            'type' => 'nullable|in:competition,practice,tournament,match',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $eventId = DB::table('sports_events')->insertGetId([
            'school_id' => $request->school_id ?? 1,
            'activity_id' => $request->activity_id,
            'name' => $request->name,
            'description' => $request->description,
            'event_date' => $request->event_date,
            'location' => $request->location,
            'type' => $request->type ?? 'competition',
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = DB::table('sports_events')->find($eventId);

        return response()->json([
            'message' => 'Sports event created successfully',
            'event' => $event
        ], 201);
    }
}
