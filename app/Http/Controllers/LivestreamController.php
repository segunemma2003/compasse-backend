<?php

namespace App\Http\Controllers;

use App\Models\Livestream;
use App\Models\LivestreamAttendance;
use App\Models\Student;
use App\Models\User;
use App\Services\GoogleMeetService;
use App\Services\MuxLiveStreamService;
use App\Services\CacheService;
use App\Jobs\SendEmailJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class LivestreamController extends Controller
{
    protected $cacheService;
    protected $googleMeetService;
    protected MuxLiveStreamService $muxLiveStreamService;

    public function __construct(CacheService $cacheService, GoogleMeetService $googleMeetService, MuxLiveStreamService $muxLiveStreamService)
    {
        $this->cacheService = $cacheService;
        $this->googleMeetService = $googleMeetService;
        $this->muxLiveStreamService = $muxLiveStreamService;
    }

    /**
     * Whether $user may view/join a livestream — admin/school-wide roles see
     * everything, teachers must be assigned to its subject or class, everyone
     * else (student/guardian) must belong to its class.
     */
    private function canViewLivestream(User $user, Livestream $livestream): bool
    {
        if ($this->accessibleStudentIds($user) === null) {
            return true;
        }

        if ($user->teacher) {
            return $this->assertCanManageSubjectResource($user, $livestream->subject_id, $livestream->class_id) === null;
        }

        if (! $livestream->class_id) {
            return true;
        }

        return $this->classWithinScope($user, (int) $livestream->class_id);
    }

    /**
     * Which streaming backend the tenant uses (meet = external link, mux = in-app HLS).
     */
    public function config(): JsonResponse
    {
        $provider = config('services.livestream.provider', 'meet');
        $muxReady = $this->muxLiveStreamService->isConfigured();

        return response()->json([
            'provider'        => ($provider === 'mux' && $muxReady) ? 'mux' : 'meet',
            'mux_configured'  => $muxReady,
            'configured_as'   => $provider,
        ]);
    }

    /**
     * Get all livestreams
     */
    public function index(Request $request): JsonResponse
    {
        $cacheKey = "livestreams:list:" . $request->user()->id . ':' . md5(serialize($request->all()));
        $cached = $this->cacheService->get($cacheKey);

        if ($cached) {
            return response()->json($cached);
        }

        $query = Livestream::query();

        // Route middleware only checks the tenant has the livestream module —
        // without this, any authenticated user (including students in other
        // classes) sees every livestream across every subject/class.
        $user = $request->user();
        if ($this->accessibleStudentIds($user) !== null) {
            if ($user->teacher) {
                $subjectIds = $this->accessibleSubjectIds($user);
                $classIds   = $this->accessibleClassIds($user);
                $query->where(function ($q) use ($subjectIds, $classIds) {
                    $q->whereIn('subject_id', $subjectIds ?? [])
                      ->orWhereIn('class_id', $classIds ?? []);
                });
            } else {
                $ownId = $this->ownStudentId($user);
                $classId = $ownId ? Student::where('id', $ownId)->value('class_id') : null;
                if ($classId) {
                    $query->where('class_id', $classId);
                } else {
                    $guardianIds = $this->accessibleStudentIdsForGuardian($user);
                    $wardClassIds = $guardianIds ? Student::whereIn('id', $guardianIds)->pluck('class_id') : collect();
                    if ($wardClassIds->isNotEmpty()) {
                        $query->whereIn('class_id', $wardClassIds);
                    } else {
                        $query->whereRaw('1 = 0');
                    }
                }
            }
        }

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

    public function show(Request $request, Livestream $livestream): JsonResponse
    {
        if (! $this->canViewLivestream($request->user(), $livestream)) {
            return $this->forbiddenResponse('You may not view this livestream.');
        }

        $livestream->load(['teacher.user', 'class', 'subject']);

        return response()->json(['livestream' => $livestream]);
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

        if ($denied = $this->assertCanManageSubjectResource($request->user(), (int) $request->subject_id, (int) $request->class_id, 'livestream')) {
            return $denied;
        }

        if ($request->user()->teacher && (int) $request->teacher_id !== $request->user()->teacher->id) {
            return $this->forbiddenResponse('You may only schedule livestreams under your own teacher profile.');
        }

        try {
            $startTime = \Carbon\Carbon::parse($request->start_time);
            $school    = $request->attributes->get('school') ?? \App\Models\School::first();

            $providerPref = $request->input('stream_provider', config('services.livestream.provider', 'meet'));
            if (! in_array($providerPref, ['meet', 'mux'], true)) {
                $providerPref = 'meet';
            }
            if ($providerPref === 'mux' && ! $this->muxLiveStreamService->isConfigured()) {
                return response()->json(['error' => 'Mux is not configured. Choose Google Meet or set MUX_* keys.'], 422);
            }

            $livestream = Livestream::create([
                'school_id'          => $school?->id,
                'teacher_id'         => $request->teacher_id,
                'class_id'           => $request->class_id,
                'subject_id'         => $request->subject_id,
                'title'              => $request->title,
                'description'        => $request->description,
                'stream_provider'    => $providerPref,
                'meeting_link'       => null,
                'meeting_id'         => null,
                'meeting_password'   => null,
                'mux_live_stream_id' => null,
                'mux_playback_id'    => null,
                'mux_stream_key'     => null,
                'mux_rtmp_url'       => null,
                'start_time'         => $startTime,
                'end_time'           => $startTime->copy()->addMinutes((int) $request->duration_minutes),
                'duration_minutes'   => $request->duration_minutes,
                'max_participants'   => $request->max_participants ?? 100,
                'is_recurring'       => $request->boolean('is_recurring'),
                'recurrence_pattern' => $request->recurrence_pattern,
                'status'             => 'provisioning',
                'created_by'         => auth()->id(),
            ]);

            $this->cacheService->invalidateByPattern("livestreams:*");

            \App\Jobs\ProvisionLivestreamJob::dispatch(
                $livestream->id,
                $providerPref,
                $school?->id
            );

            return response()->json([
                'message'    => 'Livestream queued — Meet/Mux link will be ready in a few seconds.',
                'livestream' => $livestream->load(['teacher.user', 'class', 'subject']),
                'poll_url'   => '/livestreams/' . $livestream->id,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create livestream',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a scheduled livestream
     */
    public function update(Request $request, Livestream $livestream): JsonResponse
    {
        if ($denied = $this->assertCanManageSubjectResource($request->user(), $livestream->subject_id, $livestream->class_id, 'livestream')) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'start_time' => 'sometimes|date',
            'duration_minutes' => 'sometimes|integer|min:15|max:480',
            'max_participants' => 'nullable|integer|min:1|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->only(['title', 'description', 'max_participants']);

            if ($request->has('start_time')) {
                $startTime = \Carbon\Carbon::parse($request->start_time);
                $data['start_time'] = $startTime;
                $data['end_time'] = $startTime->copy()->addMinutes((int) ($request->duration_minutes ?? $livestream->duration_minutes));
            }
            if ($request->has('duration_minutes')) {
                $data['duration_minutes'] = $request->duration_minutes;
            }

            $livestream->update($data);
            $this->cacheService->invalidateByPattern("livestreams:*");

            return response()->json([
                'message' => 'Livestream updated successfully',
                'livestream' => $livestream->fresh()->load(['teacher.user', 'class', 'subject'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update livestream',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel/delete a scheduled livestream
     */
    public function destroy(Request $request, Livestream $livestream): JsonResponse
    {
        if ($denied = $this->assertCanManageSubjectResource($request->user(), $livestream->subject_id, $livestream->class_id, 'livestream')) {
            return $denied;
        }

        try {
            $livestream->delete();
            $this->cacheService->invalidateByPattern("livestreams:*");

            return response()->json(['message' => 'Livestream deleted successfully']);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete livestream',
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

        if (! $this->canViewLivestream($request->user(), $livestream)) {
            return $this->forbiddenResponse('You may not join this livestream.');
        }

        if (! $request->user()->teacher
            && $this->accessibleStudentIds($request->user()) !== null
            && ! $this->studentWithinScope($request->user(), (int) $request->student_id)
        ) {
            return $this->forbiddenResponse('You may only join as your own student record.');
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

        if (! $request->user()->teacher
            && $this->accessibleStudentIds($request->user()) !== null
            && ! $this->studentWithinScope($request->user(), (int) $request->student_id)
        ) {
            return $this->forbiddenResponse('You may only leave as your own student record.');
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
    public function attendance(Request $request, Livestream $livestream): JsonResponse
    {
        if (! $this->canViewLivestream($request->user(), $livestream)) {
            return $this->forbiddenResponse('You may not view this livestream.');
        }

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
    public function start(Request $request, Livestream $livestream): JsonResponse
    {
        if ($denied = $this->assertCanManageSubjectResource($request->user(), $livestream->subject_id, $livestream->class_id, 'livestream')) {
            return $denied;
        }

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
    public function end(Request $request, Livestream $livestream): JsonResponse
    {
        if ($denied = $this->assertCanManageSubjectResource($request->user(), $livestream->subject_id, $livestream->class_id, 'livestream')) {
            return $denied;
        }

        try {
            if ($livestream->stream_provider === 'mux' && $livestream->mux_live_stream_id) {
                $this->muxLiveStreamService->signalComplete($livestream->mux_live_stream_id);
            }

            $livestream->update([
                'status' => 'completed',
                'end_time' => now(),
            ]);

            if ($livestream->mux_live_stream_id) {
                \App\Jobs\FinalizeLivestreamRecordingJob::dispatch($livestream->id);
            }

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

    /**
     * Queue notification emails to the teacher and all students (+ their guardians) in the class.
     */
    private function dispatchMeetingEmails(Livestream $livestream, ?int $schoolId): void
    {
        $teacherName  = optional($livestream->teacher?->user)->name ?? 'Teacher';
        $className    = $livestream->class?->name ?? 'your class';
        $subjectName  = $livestream->subject?->name ?? '';
        $startFormatted = $livestream->start_time->format('l, d M Y \a\t g:i A');
        $duration     = $livestream->duration_minutes . ' minutes';
        $meetingLink  = $livestream->meeting_link;
        $title        = $livestream->title;

        $html = $this->buildEmailHtml($title, $teacherName, $className, $subjectName, $startFormatted, $duration, $meetingLink);

        $dispatched = [];

        // Notify teacher
        $teacherEmail = $livestream->teacher?->email
            ?? optional($livestream->teacher?->user)->email;
        if ($teacherEmail && filter_var($teacherEmail, FILTER_VALIDATE_EMAIL)) {
            dispatch(new SendEmailJob(
                to:       $teacherEmail,
                subject:  "You have a scheduled session: {$title}",
                body:     $html,
                schoolId: $schoolId ? (string) $schoolId : null,
                isHtml:   true,
                type:     'meeting_invite',
            ));
            $dispatched[] = $teacherEmail;
        }

        // Notify students in the class (and their guardians)
        if ($livestream->class_id) {
            $students = Student::where('class_id', $livestream->class_id)
                ->with(['user', 'guardians'])
                ->get();

            foreach ($students as $student) {
                // Student's own email (direct or via user account)
                $studentEmail = $student->email ?? optional($student->user)->email;
                if ($studentEmail
                    && filter_var($studentEmail, FILTER_VALIDATE_EMAIL)
                    && !in_array($studentEmail, $dispatched, true)
                ) {
                    dispatch(new SendEmailJob(
                        to:       $studentEmail,
                        subject:  "Upcoming class: {$title}",
                        body:     $html,
                        schoolId: $schoolId ? (string) $schoolId : null,
                        isHtml:   true,
                        type:     'meeting_invite',
                    ));
                    $dispatched[] = $studentEmail;
                }

                // Guardians / parents
                foreach ($student->guardians as $guardian) {
                    $gEmail = $guardian->email;
                    if ($gEmail
                        && filter_var($gEmail, FILTER_VALIDATE_EMAIL)
                        && !in_array($gEmail, $dispatched, true)
                    ) {
                        dispatch(new SendEmailJob(
                            to:       $gEmail,
                            subject:  "Upcoming class for {$student->first_name}: {$title}",
                            body:     $html,
                            schoolId: $schoolId ? (string) $schoolId : null,
                            isHtml:   true,
                            type:     'meeting_invite',
                        ));
                        $dispatched[] = $gEmail;
                    }
                }
            }
        }
    }

    private function buildEmailHtml(
        string $title,
        string $teacher,
        string $className,
        string $subject,
        string $startTime,
        string $duration,
        string $meetingLink,
    ): string {
        $subjectLine = $subject ? " — {$subject}" : '';
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>{$title}</title></head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:32px 0;">
    <tr><td align="center">
      <table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">

        <!-- Header -->
        <tr><td style="background:#1a3a6b;padding:28px 32px;">
          <h1 style="margin:0;color:#ffffff;font-size:20px;">📹 Class Session Scheduled</h1>
        </td></tr>

        <!-- Body -->
        <tr><td style="padding:28px 32px;">
          <h2 style="margin:0 0 4px;font-size:18px;color:#1a3a6b;">{$title}</h2>
          <p style="margin:0 0 20px;font-size:13px;color:#6b7280;">{$className}{$subjectLine}</p>

          <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8faff;border-radius:8px;padding:16px;margin-bottom:24px;">
            <tr>
              <td style="padding:6px 0;font-size:13px;color:#6b7280;width:130px;">📅 Date &amp; Time</td>
              <td style="padding:6px 0;font-size:13px;color:#111827;font-weight:600;">{$startTime}</td>
            </tr>
            <tr>
              <td style="padding:6px 0;font-size:13px;color:#6b7280;">⏱ Duration</td>
              <td style="padding:6px 0;font-size:13px;color:#111827;">{$duration}</td>
            </tr>
            <tr>
              <td style="padding:6px 0;font-size:13px;color:#6b7280;">👨‍🏫 Teacher</td>
              <td style="padding:6px 0;font-size:13px;color:#111827;">{$teacher}</td>
            </tr>
          </table>

          <p style="margin:0 0 16px;font-size:14px;color:#374151;">Click the button below to join the session at the scheduled time:</p>

          <a href="{$meetingLink}" target="_blank"
             style="display:inline-block;background:#1a3a6b;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:8px;font-size:14px;font-weight:600;">
            Join Google Meet →
          </a>

          <p style="margin:24px 0 0;font-size:12px;color:#9ca3af;">
            Or copy this link: <a href="{$meetingLink}" style="color:#1a3a6b;">{$meetingLink}</a>
          </p>
        </td></tr>

        <!-- Footer -->
        <tr><td style="background:#f8faff;padding:16px 32px;border-top:1px solid #e5e7eb;">
          <p style="margin:0;font-size:11px;color:#9ca3af;">This is an automated notification from Compasse School Management System.</p>
        </td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }
}
