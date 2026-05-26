<?php

namespace App\Http\Controllers;

use App\Models\Livestream;
use App\Models\LivestreamAttendance;
use App\Models\Student;
use App\Services\GoogleMeetService;
use App\Services\CacheService;
use App\Jobs\SendEmailJob;
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
            $startTime = \Carbon\Carbon::parse($request->start_time);
            $school    = $request->attributes->get('school') ?? \App\Models\School::first();

            // Generate Google Meet link
            $meetingData = $this->googleMeetService->createMeeting([
                'title'            => $request->title,
                'start_time'       => $startTime,
                'duration_minutes' => $request->duration_minutes,
            ]);

            $livestream = Livestream::create([
                'school_id'          => $school?->id,
                'teacher_id'         => $request->teacher_id,
                'class_id'           => $request->class_id,
                'subject_id'         => $request->subject_id,
                'title'              => $request->title,
                'description'        => $request->description,
                'meeting_link'       => $meetingData['meeting_link'],
                'meeting_id'         => $meetingData['meeting_id'],
                'meeting_password'   => $meetingData['meeting_password'],
                'start_time'         => $startTime,
                'end_time'           => $startTime->copy()->addMinutes((int) $request->duration_minutes),
                'duration_minutes'   => $request->duration_minutes,
                'max_participants'   => $request->max_participants ?? 100,
                'is_recurring'       => $request->boolean('is_recurring'),
                'recurrence_pattern' => $request->recurrence_pattern,
                'status'             => 'scheduled',
                'created_by'         => auth()->id(),
            ]);

            // Clear cache
            $this->cacheService->invalidateByPattern("livestreams:*");

            $livestream->load(['teacher.user', 'class', 'subject']);

            // Dispatch notification emails (queued — non-blocking)
            $this->dispatchMeetingEmails($livestream, $school?->id);

            return response()->json([
                'message' => 'Livestream created successfully',
                'livestream' => $livestream,
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
