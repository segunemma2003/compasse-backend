<?php

namespace App\Http\Controllers;

use App\Jobs\FinalizeMeetingRecordingJob;
use App\Jobs\ProvisionSchoolMeetingJob;
use App\Models\SchoolMeeting;
use App\Models\SchoolMeetingParticipant;
use App\Models\Student;
use App\Services\MuxLiveStreamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class SchoolMeetingController extends Controller
{
    public function config(MuxLiveStreamService $mux): JsonResponse
    {
        return response()->json([
            'mux_configured' => $mux->isConfigured(),
            'providers'      => ['meet', 'mux'],
            'meeting_types'  => ['class_session', 'staff_meeting', 'one_on_one'],
            'recording_default' => true,
        ]);
    }

    /** Fast directory for scheduling (id + name only, capped at 200). */
    public function directory(): JsonResponse
    {
        $users = \App\Models\User::query()
            ->select(['id', 'name', 'email', 'role'])
            ->orderBy('name')
            ->limit(200)
            ->get();

        return response()->json(['users' => $users]);
    }

    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('school_meetings')) {
            return response()->json([
                'meetings' => [
                    'data' => [],
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 20,
                    'total' => 0,
                ],
            ]);
        }

        $user = Auth::user();
        $uid  = (int) $user->id;

        $query = SchoolMeeting::query()
            ->with(['host:id,name,email', 'participants.user:id,name,email'])
            ->where(function ($q) use ($uid) {
                $q->where('host_user_id', $uid)
                    ->orWhere('created_by', $uid)
                    ->orWhereHas('participants', fn ($p) => $p->where('user_id', $uid));
            })
            ->orderByDesc('start_time');

        if ($request->filled('meeting_type')) {
            $query->where('meeting_type', $request->meeting_type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $meetings = $query->paginate(min((int) $request->get('per_page', 20), 50));

        return response()->json(['meetings' => $meetings]);
    }

    public function show(SchoolMeeting $meeting): JsonResponse
    {
        $this->authorizeView($meeting);

        $meeting->load(['host:id,name,email', 'participants.user:id,name,email', 'teacher.user', 'class', 'subject']);

        return response()->json(['meeting' => $meeting]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'meeting_type'      => 'required|in:class_session,staff_meeting,one_on_one',
            'title'             => 'required|string|max:255',
            'description'       => 'nullable|string',
            'stream_provider'   => 'required|in:meet,mux',
            'start_time'        => 'required|date|after:now',
            'duration_minutes'  => 'required|integer|min:15|max:480',
            'recording_required'=> 'boolean',
            'participant_user_ids' => 'nullable|array',
            'participant_user_ids.*' => 'integer|exists:users,id',
            'other_user_id'     => 'nullable|integer|exists:users,id',
            'teacher_id'        => 'nullable|exists:teachers,id',
            'class_id'          => 'nullable|exists:classes,id',
            'subject_id'        => 'nullable|exists:subjects,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $type = $request->meeting_type;
        if ($type === 'one_on_one' && ! $request->other_user_id) {
            return response()->json(['error' => 'other_user_id is required for one-on-one meetings'], 422);
        }
        if ($type === 'class_session' && (! $request->teacher_id || ! $request->class_id)) {
            return response()->json(['error' => 'teacher_id and class_id are required for class sessions'], 422);
        }

        $provider = $request->stream_provider;
        if ($provider === 'mux' && ! app(MuxLiveStreamService::class)->isConfigured()) {
            return response()->json(['error' => 'Mux is not configured on this school. Choose Google Meet or ask admin to set MUX_* keys.'], 422);
        }

        $user = Auth::user();

        $participantIds = collect($request->input('participant_user_ids', []))
            ->map(fn ($id) => (int) $id);

        if ($type === 'one_on_one') {
            $participantIds = $participantIds->push((int) $request->other_user_id);
        }

        $participantIds = $participantIds->push((int) $user->id)->unique()->values();

        if ($type === 'staff_meeting' && $participantIds->count() < 2) {
            return response()->json(['error' => 'Add at least one other participant for staff meetings'], 422);
        }

        $school = $request->attributes->get('school') ?? \App\Models\School::first();
        $start  = \Carbon\Carbon::parse($request->start_time);

        $meeting = SchoolMeeting::create([
            'school_id'          => $school?->id,
            'host_user_id'       => $user->id,
            'meeting_type'       => $type,
            'title'              => $request->title,
            'description'        => $request->description,
            'stream_provider'    => $provider,
            'recording_required' => $request->boolean('recording_required', true),
            'recording_status'   => 'pending',
            'teacher_id'         => $request->teacher_id,
            'class_id'           => $request->class_id,
            'subject_id'         => $request->subject_id,
            'start_time'         => $start,
            'end_time'           => $start->copy()->addMinutes((int) $request->duration_minutes),
            'duration_minutes'   => (int) $request->duration_minutes,
            'status'             => 'provisioning',
            'created_by'         => $user->id,
        ]);

        foreach ($participantIds as $pid) {
            SchoolMeetingParticipant::create([
                'school_meeting_id' => $meeting->id,
                'user_id'           => $pid,
                'role'              => $pid === (int) $user->id ? 'host' : 'participant',
                'invited_at'        => now(),
            ]);
        }

        if ($type === 'class_session' && $meeting->class_id) {
            Student::where('class_id', $meeting->class_id)
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->each(function ($studentUserId) use ($meeting, $user) {
                    if ((int) $studentUserId === (int) $user->id) {
                        return;
                    }
                    SchoolMeetingParticipant::firstOrCreate(
                        ['school_meeting_id' => $meeting->id, 'user_id' => $studentUserId],
                        ['role' => 'participant', 'invited_at' => now()]
                    );
                });
        }

        ProvisionSchoolMeetingJob::dispatch($meeting->id);

        return response()->json([
            'message' => 'Meeting scheduled — link will be ready in a few seconds.',
            'meeting' => $meeting->load('participants.user'),
        ], 201);
    }

    public function start(SchoolMeeting $meeting): JsonResponse
    {
        $this->authorizeHost($meeting);
        if (! in_array($meeting->status, ['scheduled', 'active'], true)) {
            return response()->json(['error' => 'Meeting cannot be started'], 400);
        }
        $meeting->update(['status' => 'active', 'start_time' => $meeting->start_time->isFuture() ? now() : $meeting->start_time]);

        return response()->json(['message' => 'Meeting started', 'meeting' => $meeting->fresh()]);
    }

    public function end(SchoolMeeting $meeting, MuxLiveStreamService $mux): JsonResponse
    {
        $this->authorizeHost($meeting);

        if ($meeting->stream_provider === 'mux' && $meeting->mux_live_stream_id) {
            $mux->signalComplete($meeting->mux_live_stream_id);
        }

        $meeting->update([
            'status'   => 'completed',
            'end_time' => now(),
        ]);

        if ($meeting->recording_required) {
            FinalizeMeetingRecordingJob::dispatch($meeting->id);
        }

        return response()->json(['message' => 'Meeting ended', 'meeting' => $meeting->fresh()]);
    }

    public function recording(SchoolMeeting $meeting): JsonResponse
    {
        $this->authorizeView($meeting);

        if (! $meeting->recording_url) {
            return response()->json([
                'recording_status' => $meeting->recording_status,
                'message'          => 'Recording is not ready yet.',
            ], 404);
        }

        return response()->json([
            'recording_status' => $meeting->recording_status,
            'recording_url'    => $meeting->recording_url,
            'download'         => true,
        ]);
    }

    protected function authorizeView(SchoolMeeting $meeting): void
    {
        $uid = (int) Auth::id();
        $ok  = $meeting->host_user_id === $uid
            || $meeting->created_by === $uid
            || $meeting->participants()->where('user_id', $uid)->exists();

        if (! $ok) {
            abort(403, 'You are not invited to this meeting.');
        }
    }

    protected function authorizeHost(SchoolMeeting $meeting): void
    {
        if ((int) Auth::id() !== (int) $meeting->host_user_id && (int) Auth::id() !== (int) $meeting->created_by) {
            abort(403, 'Only the host can control this meeting.');
        }
    }
}
