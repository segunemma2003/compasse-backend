<?php

namespace App\Http\Controllers;

use App\Models\Livestream;
use App\Models\LivestreamAttendance;
use App\Services\GoogleMeetService;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class LivestreamController extends Controller
{
    protected $cacheService;
    protected $googleMeetService;

    public function __construct(CacheService $cacheService, GoogleMeetService $googleMeetService)
    {
        $this->cacheService = $cacheService;
        $this->googleMeetService = $googleMeetService;
    }

    /**
     * Get all livestreams
     */
    public function index(Request $request): JsonResponse
    {
        $cacheKey = "livestreams:list:" . md5(serialize($request->all()));
        $cached = $this->cacheService->get($cacheKey);
        
        if ($cached) {
            return response()->json($cached);
        }

        $query = Livestream::query();

        if ($request->has('teacher_id')) {
            $query->where('teacher_id', $request->teacher_id);
        }

        if ($request->has('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        if ($request->has('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('date')) {
            $query->whereDate('start_time', $request->date);
        }

        $livestreams = $query->with(['teacher', 'class', 'subject'])
                           ->orderBy('start_time', 'desc')
                           ->paginate($request->get('per_page', 15));

        $response = [
            'livestreams' => $livestreams
        ];

        $this->cacheService->set($cacheKey, $response, 300); // 5 minutes cache

        return response()->json($response);
    }

    /**
     * Create new livestream
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'teacher_id' => 'required|exists:teachers,id',
            'class_id' => 'required|exists:classes,id',
            'subject_id' => 'required|exists:subjects,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_time' => 'required|date|after:now',
            'duration_minutes' => 'required|integer|min:15|max:480',
            'max_participants' => 'nullable|integer|min:1|max:1000',
            'is_recurring' => 'boolean',
            'recurrence_pattern' => 'nullable|string|in:daily,weekly,monthly',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            // Generate Google Meet link
            $meetingData = $this->googleMeetService->createMeeting([
                'title' => $request->title,
                'start_time' => $request->start_time,
                'duration_minutes' => $request->duration_minutes,
            ]);

            $livestream = Livestream::create([
                'school_id' => auth()->user()->school_id,
                'teacher_id' => $request->teacher_id,
                'class_id' => $request->class_id,
                'subject_id' => $request->subject_id,
                'title' => $request->title,
                'description' => $request->description,
                'meeting_link' => $meetingData['meeting_link'],
                'meeting_id' => $meetingData['meeting_id'],
                'meeting_password' => $meetingData['meeting_password'],
                'start_time' => $request->start_time,
                'end_time' => $request->start_time->addMinutes($request->duration_minutes),
                'duration_minutes' => $request->duration_minutes,
                'max_participants' => $request->max_participants ?? 100,
                'is_recurring' => $request->boolean('is_recurring'),
                'recurrence_pattern' => $request->recurrence_pattern,
                'status' => 'scheduled',
                'created_by' => auth()->id(),
            ]);

            // Clear cache
            $this->cacheService->invalidateByPattern("livestreams:*");

            return response()->json([
                'message' => 'Livestream created successfully',
                'livestream' => $livestream->load(['teacher', 'class', 'subject'])
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create livestream',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Join livestream
     */
    public function join(Request $request, Livestream $livestream): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|exists:students,id',
            'device_info' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            // Check if livestream is active
            if (!$livestream->isActive()) {
                return response()->json([
                    'error' => 'Livestream is not currently active'
                ], 400);
            }

            // Record attendance
            $attendance = LivestreamAttendance::create([
                'livestream_id' => $livestream->id,
                'student_id' => $request->student_id,
                'joined_at' => now(),
                'status' => 'present',
                'device_info' => $request->device_info,
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Successfully joined livestream',
                'meeting_link' => $livestream->meeting_link,
                'meeting_password' => $livestream->meeting_password,
                'attendance' => $attendance
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to join livestream',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Leave livestream
     */
    public function leave(Request $request, Livestream $livestream): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|exists:students,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $attendance = LivestreamAttendance::where('livestream_id', $livestream->id)
                                           ->where('student_id', $request->student_id)
                                           ->whereNull('left_at')
                                           ->first();

            if (!$attendance) {
                return response()->json([
                    'error' => 'No active attendance found'
                ], 404);
            }

            $attendance->update([
                'left_at' => now(),
                'duration_minutes' => $attendance->getDurationMinutes(),
                'status' => 'completed',
            ]);

            return response()->json([
                'message' => 'Successfully left livestream',
                'attendance' => $attendance
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to leave livestream',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get livestream attendance
     */
    public function attendance(Livestream $livestream): JsonResponse
    {
        $attendance = $livestream->attendees()
                               ->with(['student.user'])
                               ->orderBy('joined_at', 'desc')
                               ->get();

        return response()->json([
            'livestream' => $livestream,
            'attendance' => $attendance,
            'attendance_rate' => $livestream->getAttendanceRate(),
            'total_participants' => $attendance->count(),
        ]);
    }

    /**
     * Start livestream
     */
    public function start(Livestream $livestream): JsonResponse
    {
        try {
            $livestream->update([
                'status' => 'active',
                'start_time' => now(),
            ]);

            return response()->json([
                'message' => 'Livestream started successfully',
                'livestream' => $livestream
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to start livestream',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * End livestream
     */
    public function end(Livestream $livestream): JsonResponse
    {
        try {
            $livestream->update([
                'status' => 'completed',
                'end_time' => now(),
            ]);

            return response()->json([
                'message' => 'Livestream ended successfully',
                'livestream' => $livestream
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to end livestream',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
