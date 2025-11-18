<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AnnouncementController extends Controller
{
    /**
     * List announcements
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = DB::table('announcements');

            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            if ($request->has('target_audience')) {
                $query->where('target_audience', $request->target_audience);
            }

            if ($request->has('is_published')) {
                $query->where('is_published', $request->is_published);
            }

            if ($request->has('class_id')) {
                $query->where('class_id', $request->class_id);
            }

            $announcements = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            return response()->json($announcements);
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
     * Get announcement details
     */
    public function show($id): JsonResponse
    {
        $announcement = DB::table('announcements')->find($id);

        if (!$announcement) {
            return response()->json(['error' => 'Announcement not found'], 404);
        }

        return response()->json(['announcement' => $announcement]);
    }

    /**
     * Create announcement
     */
    public function store(Request $request): JsonResponse
    {
        // Check if announcements table exists
        $tableExists = false;
        try {
            $tableExists = \Illuminate\Support\Facades\Schema::hasTable('announcements');
        } catch (\Exception $e) {
            $tableExists = false;
        }
        
        if (!$tableExists) {
            return response()->json([
                'error' => 'Announcements table not found',
                'message' => 'The announcements table does not exist. Please run tenant migrations.',
                'announcement' => null
            ], 500);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'type' => 'nullable|in:general,academic,event,emergency',
            'target_audience' => 'nullable|in:all,students,teachers,parents,staff',
            'class_id' => 'nullable|exists:classes,id',
            'publish_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after:publish_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $announcementId = DB::table('announcements')->insertGetId([
                'school_id' => $request->school_id ?? 1,
                'title' => $request->title,
                'content' => $request->content,
                'type' => $request->type ?? 'general',
                'target_audience' => $request->target_audience ?? 'all',
                'class_id' => $request->class_id,
                'publish_date' => $request->publish_date,
                'expiry_date' => $request->expiry_date,
                'is_published' => false,
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $announcement = DB::table('announcements')->find($announcementId);

            return response()->json([
                'message' => 'Announcement created successfully',
                'announcement' => $announcement
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create announcement',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update announcement
     */
    public function update(Request $request, $id): JsonResponse
    {
        $announcement = DB::table('announcements')->find($id);

        if (!$announcement) {
            return response()->json(['error' => 'Announcement not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'type' => 'nullable|in:general,academic,event,emergency',
            'target_audience' => 'nullable|in:all,students,teachers,parents,staff',
            'publish_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after:publish_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        DB::table('announcements')
            ->where('id', $id)
            ->update(array_merge(
                $request->only(['title', 'content', 'type', 'target_audience', 'publish_date', 'expiry_date']),
                ['updated_at' => now()]
            ));

        $announcement = DB::table('announcements')->find($id);

        return response()->json([
            'message' => 'Announcement updated successfully',
            'announcement' => $announcement
        ]);
    }

    /**
     * Delete announcement
     */
    public function destroy($id): JsonResponse
    {
        $announcement = DB::table('announcements')->find($id);

        if (!$announcement) {
            return response()->json(['error' => 'Announcement not found'], 404);
        }

        DB::table('announcements')->where('id', $id)->delete();

        return response()->json([
            'message' => 'Announcement deleted successfully'
        ]);
    }

    /**
     * Publish announcement
     */
    public function publish($id): JsonResponse
    {
        $announcement = DB::table('announcements')->find($id);

        if (!$announcement) {
            return response()->json(['error' => 'Announcement not found'], 404);
        }

        DB::table('announcements')
            ->where('id', $id)
            ->update([
                'is_published' => true,
                'publish_date' => now(),
                'updated_at' => now(),
            ]);

        $announcement = DB::table('announcements')->find($id);

        return response()->json([
            'message' => 'Announcement published successfully',
            'announcement' => $announcement
        ]);
    }
}
