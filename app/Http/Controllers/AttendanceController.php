<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    protected $cacheService;

    public function __construct(CacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Get student attendance
     */
    public function students(Request $request): JsonResponse
    {
        $cacheKey = "attendance:students:" . md5(serialize($request->all()));
        $cached = $this->cacheService->get($cacheKey);

        if ($cached) {
            return response()->json($cached);
        }

        $query = Attendance::where('attendanceable_type', Student::class);

        if ($request->has('student_id')) {
            $query->where('attendanceable_id', $request->student_id);
        }

        if ($request->has('class_id')) {
            $studentIds = Student::where('class_id', $request->class_id)->pluck('id');
            $query->whereIn('attendanceable_id', $studentIds);
        }

        if ($request->has('date')) {
            $query->whereDate('date', $request->date);
        }

        if ($request->has('date_range')) {
            $dates = explode(' to ', $request->date_range);
            if (count($dates) === 2) {
                $query->whereBetween('date', [Carbon::parse($dates[0]), Carbon::parse($dates[1])]);
            }
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $attendance = $query->with(['attendanceable.user', 'markedBy'])
                          ->orderBy('date', 'desc')
                          ->paginate($request->get('per_page', 15));

        $response = [
            'attendance' => $attendance,
            'summary' => $this->getAttendanceSummary($query->get())
        ];

        $this->cacheService->set($cacheKey, $response, 300); // 5 minutes cache

        return response()->json($response);
    }

    /**
     * Get teacher attendance
     */
    public function teachers(Request $request): JsonResponse
    {
        $cacheKey = "attendance:teachers:" . md5(serialize($request->all()));
        $cached = $this->cacheService->get($cacheKey);

        if ($cached) {
            return response()->json($cached);
        }

        $query = Attendance::where('attendanceable_type', Teacher::class);

        if ($request->has('teacher_id')) {
            $query->where('attendanceable_id', $request->teacher_id);
        }

        if ($request->has('department_id')) {
            $teacherIds = Teacher::where('department_id', $request->department_id)->pluck('id');
            $query->whereIn('attendanceable_id', $teacherIds);
        }

        if ($request->has('date')) {
            $query->whereDate('date', $request->date);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $attendance = $query->with(['attendanceable.user', 'markedBy'])
                          ->orderBy('date', 'desc')
                          ->paginate($request->get('per_page', 15));

        $response = [
            'attendance' => $attendance,
            'summary' => $this->getAttendanceSummary($query->get())
        ];

        $this->cacheService->set($cacheKey, $response, 300); // 5 minutes cache

        return response()->json($response);
    }

    /**
     * Mark attendance
     */
    public function mark(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'attendanceable_id' => 'required|integer',
            'attendanceable_type' => 'required|string|in:student,teacher',
            'date' => 'required|date',
            'status' => 'required|in:present,absent,late,excused',
            'check_in_time' => 'nullable|date',
            'check_out_time' => 'nullable|date|after:check_in_time',
            'location' => 'nullable|string',
            'notes' => 'nullable|string',
            'is_excused' => 'boolean',
            'excuse_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $attendanceableType = $request->attendanceable_type === 'student' ? Student::class : Teacher::class;

            $attendance = Attendance::updateOrCreate(
                [
                    'school_id' => auth()->user()->school_id,
                    'attendanceable_id' => $request->attendanceable_id,
                    'attendanceable_type' => $attendanceableType,
                    'date' => $request->date,
                ],
                [
                    'status' => $request->status,
                    'check_in_time' => $request->check_in_time,
                    'check_out_time' => $request->check_out_time,
                    'location' => $request->location,
                    'notes' => $request->notes,
                    'is_excused' => $request->boolean('is_excused'),
                    'excuse_notes' => $request->excuse_notes,
                    'marked_by' => auth()->id(),
                    'device_info' => $request->header('User-Agent'),
                    'ip_address' => $request->ip(),
                ]
            );

            // Calculate additional fields
            if ($attendance->check_in_time && $attendance->check_out_time) {
                $attendance->total_hours = $attendance->calculateTotalHours();
                $attendance->is_late = $attendance->isLate();
                $attendance->late_minutes = $attendance->getLateMinutes();
                $attendance->overtime_hours = $attendance->getOvertimeHours();
                $attendance->save();
            }

            // Clear cache
            $this->cacheService->invalidateByPattern("attendance:*");

            return response()->json([
                'message' => 'Attendance marked successfully',
                'attendance' => $attendance->load(['attendanceable.user', 'markedBy'])
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to mark attendance',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clock in/out
     */
    public function clockInOut(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:clock_in,clock_out',
            'location' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $user = auth()->user();
            $today = Carbon::today();

            $attendance = Attendance::where('attendanceable_id', $user->id)
                                 ->where('attendanceable_type', $user->getMorphClass())
                                 ->whereDate('date', $today)
                                 ->first();

            if ($request->action === 'clock_in') {
                if ($attendance && $attendance->check_in_time) {
                    return response()->json([
                        'error' => 'Already clocked in today'
                    ], 400);
                }

                if (!$attendance) {
                    $attendance = Attendance::create([
                        'school_id' => $user->school_id,
                        'attendanceable_id' => $user->id,
                        'attendanceable_type' => $user->getMorphClass(),
                        'date' => $today,
                        'status' => 'present',
                        'check_in_time' => now(),
                        'location' => $request->location,
                        'notes' => $request->notes,
                        'marked_by' => $user->id,
                        'device_info' => $request->header('User-Agent'),
                        'ip_address' => $request->ip(),
                    ]);
                } else {
                    $attendance->update([
                        'check_in_time' => now(),
                        'status' => 'present',
                        'location' => $request->location,
                        'notes' => $request->notes,
                    ]);
                }

                $message = 'Clocked in successfully';
            } else {
                if (!$attendance || !$attendance->check_in_time) {
                    return response()->json([
                        'error' => 'Must clock in before clocking out'
                    ], 400);
                }

                if ($attendance->check_out_time) {
                    return response()->json([
                        'error' => 'Already clocked out today'
                    ], 400);
                }

                $attendance->update([
                    'check_out_time' => now(),
                    'total_hours' => $attendance->calculateTotalHours(),
                    'is_late' => $attendance->isLate(),
                    'late_minutes' => $attendance->getLateMinutes(),
                    'overtime_hours' => $attendance->getOvertimeHours(),
                ]);

                $message = 'Clocked out successfully';
            }

            // Clear cache
            $this->cacheService->invalidateByPattern("attendance:*");

            return response()->json([
                'message' => $message,
                'attendance' => $attendance->fresh(['attendanceable.user'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to process clock in/out',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get attendance reports
     */
    public function reports(Request $request): JsonResponse
    {
        $cacheKey = "attendance:reports:" . md5(serialize($request->all()));
        $cached = $this->cacheService->get($cacheKey);

        if ($cached) {
            return response()->json($cached);
        }

        $startDate = $request->get('start_date', Carbon::now()->startOfMonth());
        $endDate = $request->get('end_date', Carbon::now()->endOfMonth());
        $type = $request->get('type', 'students');

        $query = Attendance::whereBetween('date', [$startDate, $endDate]);

        if ($type === 'students') {
            $query->where('attendanceable_type', Student::class);
        } else {
            $query->where('attendanceable_type', Teacher::class);
        }

        $attendance = $query->with(['attendanceable.user'])
                          ->get();

        $response = [
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'summary' => $this->getAttendanceSummary($attendance),
            'daily_breakdown' => $this->getDailyBreakdown($attendance),
            'top_absentees' => $this->getTopAbsentees($attendance),
            'attendance_trends' => $this->getAttendanceTrends($attendance),
        ];

        $this->cacheService->set($cacheKey, $response, 600); // 10 minutes cache

        return response()->json($response);
    }

    /**
     * Get attendance summary
     */
    protected function getAttendanceSummary($attendance)
    {
        $total = $attendance->count();
        $present = $attendance->where('status', 'present')->count();
        $absent = $attendance->where('status', 'absent')->count();
        $late = $attendance->where('is_late', true)->count();
        $excused = $attendance->where('is_excused', true)->count();

        return [
            'total_records' => $total,
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'excused' => $excused,
            'attendance_rate' => $total > 0 ? round(($present / $total) * 100, 2) : 0,
            'punctuality_rate' => $total > 0 ? round((($present - $late) / $total) * 100, 2) : 0,
        ];
    }

    /**
     * Get daily breakdown
     */
    protected function getDailyBreakdown($attendance)
    {
        return $attendance->groupBy('date')
                        ->map(function ($dayAttendance) {
                            return $this->getAttendanceSummary($dayAttendance);
                        });
    }

    /**
     * Get top absentees
     */
    protected function getTopAbsentees($attendance)
    {
        return $attendance->where('status', 'absent')
                         ->groupBy('attendanceable_id')
                         ->map(function ($userAttendance) {
                             return [
                                 'user' => $userAttendance->first()->attendanceable,
                                 'absent_days' => $userAttendance->count(),
                             ];
                         })
                         ->sortByDesc('absent_days')
                         ->take(10)
                         ->values();
    }

    /**
     * Get attendance trends
     */
    protected function getAttendanceTrends($attendance)
    {
        $weeklyTrends = $attendance->groupBy(function ($item) {
            return $item->date->format('Y-W');
        })->map(function ($weekAttendance) {
            return $this->getAttendanceSummary($weekAttendance);
        });

        return [
            'weekly' => $weeklyTrends,
            'monthly' => $this->getMonthlyTrends($attendance),
        ];
    }

    /**
     * Get monthly trends
     */
    protected function getMonthlyTrends($attendance)
    {
        return $attendance->groupBy(function ($item) {
            return $item->date->format('Y-m');
        })->map(function ($monthAttendance) {
            return $this->getAttendanceSummary($monthAttendance);
        });
    }
}
