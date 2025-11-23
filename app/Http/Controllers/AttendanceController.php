<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
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
        try {
        $cacheKey = "attendance:students:" . md5(serialize($request->all()));
        $cached = $this->cacheService->get($cacheKey);

        if ($cached) {
            return response()->json($cached);
        }

            // Check if attendance table exists
            try {
                // Use DB facade to check if table exists first
                $tableExists = false;
                try {
                    $tableExists = \Illuminate\Support\Facades\Schema::hasTable('attendances');
                } catch (\Exception $e) {
                    // Schema check failed, assume table doesn't exist
                    $tableExists = false;
                }
                
                if (!$tableExists) {
                    return response()->json([
                        'attendance' => [
                            'data' => [],
                            'current_page' => 1,
                            'per_page' => 15,
                            'total' => 0
                        ],
                        'summary' => [
                            'total_records' => 0,
                            'present' => 0,
                            'absent' => 0,
                            'late' => 0,
                            'excused' => 0,
                            'attendance_rate' => 0,
                            'punctuality_rate' => 0,
                        ]
                    ]);
                }
                
                // Try to build query - this might still fail if table structure is wrong
                // Use DB facade directly to avoid model issues
                $query = DB::table('attendances')->where('attendanceable_type', 'App\\Models\\Student');
            } catch (\Exception $e) {
                // Table doesn't exist or query failed
                return response()->json([
                    'attendance' => [
                        'data' => [],
                        'current_page' => 1,
                        'per_page' => 15,
                        'total' => 0
                    ],
                    'summary' => [
                        'total_records' => 0,
                        'present' => 0,
                        'absent' => 0,
                        'late' => 0,
                        'excused' => 0,
                        'attendance_rate' => 0,
                        'punctuality_rate' => 0,
                    ]
                ]);
            }

        if ($request->has('student_id')) {
                try {
            $query->where('attendanceable_id', $request->student_id);
                } catch (\Exception $e) {
                    // Column doesn't exist
                }
        }

        if ($request->has('class_id')) {
                try {
                    $studentIds = DB::table('students')->where('class_id', $request->class_id)->pluck('id');
                    if ($studentIds->isNotEmpty()) {
            $query->whereIn('attendanceable_id', $studentIds);
                    }
                } catch (\Exception $e) {
                    // Students table doesn't exist
                }
        }

        if ($request->has('date')) {
                try {
            $query->whereDate('date', $request->date);
                } catch (\Exception $e) {
                    // Column doesn't exist
                }
        }

        if ($request->has('date_range')) {
                try {
            $dates = explode(' to ', $request->date_range);
            if (count($dates) === 2) {
                $query->whereBetween('date', [Carbon::parse($dates[0]), Carbon::parse($dates[1])]);
                    }
                } catch (\Exception $e) {
                    // Column doesn't exist
            }
        }

        if ($request->has('status')) {
                try {
            $query->where('status', $request->status);
                } catch (\Exception $e) {
                    // Column doesn't exist
                }
            }

            // Try to execute query - handle missing tables gracefully
            // Since we're using DB::table(), we need to manually paginate
            try {
                // Get total count first
                $total = $query->count();
                $perPage = $request->get('per_page', 15);
                $page = $request->get('page', 1);
                $offset = ($page - 1) * $perPage;
                
                // Get paginated data
                $attendanceData = $query->orderBy('date', 'desc')
                                  ->offset($offset)
                                  ->limit($perPage)
                                  ->get();
                
                // Create paginator manually
                $attendance = new \Illuminate\Pagination\LengthAwarePaginator(
                    $attendanceData,
                    $total,
                    $perPage,
                    $page,
                    ['path' => $request->url(), 'query' => $request->query()]
                );
                
                // Get all records for summary (rebuild query with same filters)
                $summaryQuery = DB::table('attendances')
                    ->where('attendanceable_type', 'App\\Models\\Student');
                
                // Reapply filters for summary
                if ($request->has('student_id')) {
                    $summaryQuery->where('attendanceable_id', $request->student_id);
                }
                if ($request->has('class_id')) {
                    try {
                        $studentIds = DB::table('students')->where('class_id', $request->class_id)->pluck('id');
                        if ($studentIds->isNotEmpty()) {
                            $summaryQuery->whereIn('attendanceable_id', $studentIds);
                        }
                    } catch (\Exception $e) {}
                }
                if ($request->has('date')) {
                    $summaryQuery->whereDate('date', $request->date);
                }
                if ($request->has('date_range')) {
                    $dates = explode(' to ', $request->date_range);
                    if (count($dates) === 2) {
                        $summaryQuery->whereBetween('date', [Carbon::parse($dates[0]), Carbon::parse($dates[1])]);
                    }
                }
                if ($request->has('status')) {
                    $summaryQuery->where('status', $request->status);
                }
                
                $allAttendance = $summaryQuery->get();
            } catch (\Exception $e) {
                // Table doesn't exist or query failed
                return response()->json([
                    'attendance' => [
                        'data' => [],
                        'current_page' => 1,
                        'per_page' => 15,
                        'total' => 0
                    ],
                    'summary' => [
                        'total_records' => 0,
                        'present' => 0,
                        'absent' => 0,
                        'late' => 0,
                        'excused' => 0,
                        'attendance_rate' => 0,
                        'punctuality_rate' => 0,
                    ]
                ]);
            }

        $response = [
            'attendance' => $attendance,
                'summary' => $this->safeDbOperation(function() use ($allAttendance) {
                    return $this->getAttendanceSummary($allAttendance);
                }, [
                    'total_records' => 0,
                    'present' => 0,
                    'absent' => 0,
                    'late' => 0,
                    'excused' => 0,
                    'attendance_rate' => 0,
                    'punctuality_rate' => 0,
                ])
        ];

        $this->cacheService->set($cacheKey, $response, 300); // 5 minutes cache

        return response()->json($response);
        } catch (\Exception $e) {
            return response()->json([
                'attendance' => [
                    'data' => [],
                    'current_page' => 1,
                    'per_page' => 15,
                    'total' => 0
                ],
                'summary' => [
                    'total_records' => 0,
                    'present' => 0,
                    'absent' => 0,
                    'late' => 0,
                    'excused' => 0,
                    'attendance_rate' => 0,
                    'punctuality_rate' => 0,
                ]
            ]);
        }
    }

    /**
     * Get teacher attendance
     */
    public function teachers(Request $request): JsonResponse
    {
        try {
        $cacheKey = "attendance:teachers:" . md5(serialize($request->all()));
        $cached = $this->cacheService->get($cacheKey);

        if ($cached) {
            return response()->json($cached);
        }

            // Check if attendance table exists
            try {
                // Use DB facade to check if table exists first
                $tableExists = false;
                try {
                    $tableExists = \Illuminate\Support\Facades\Schema::hasTable('attendances');
                } catch (\Exception $e) {
                    // Schema check failed, assume table doesn't exist
                    $tableExists = false;
                }
                
                if (!$tableExists) {
                    return response()->json([
                        'attendance' => [
                            'data' => [],
                            'current_page' => 1,
                            'per_page' => 15,
                            'total' => 0
                        ],
                        'summary' => [
                            'total_records' => 0,
                            'present' => 0,
                            'absent' => 0,
                            'late' => 0,
                            'excused' => 0,
                            'attendance_rate' => 0,
                            'punctuality_rate' => 0,
                        ]
                    ]);
                }
                
                // Try to build query - this might still fail if table structure is wrong
                // Use DB facade directly to avoid model issues
                $query = DB::table('attendances')->where('attendanceable_type', 'App\\Models\\Teacher');
            } catch (\Exception $e) {
                // Table doesn't exist or query failed
                return response()->json([
                    'attendance' => [
                        'data' => [],
                        'current_page' => 1,
                        'per_page' => 15,
                        'total' => 0
                    ],
                    'summary' => [
                        'total_records' => 0,
                        'present' => 0,
                        'absent' => 0,
                        'late' => 0,
                        'excused' => 0,
                        'attendance_rate' => 0,
                        'punctuality_rate' => 0,
                    ]
                ]);
            }

        if ($request->has('teacher_id')) {
            $query->where('attendanceable_id', $request->teacher_id);
        }

        if ($request->has('department_id')) {
                try {
                    $teacherIds = DB::table('teachers')->where('department_id', $request->department_id)->pluck('id');
                    if ($teacherIds->isNotEmpty()) {
            $query->whereIn('attendanceable_id', $teacherIds);
                    }
                } catch (\Exception $e) {
                    // Teachers table doesn't exist
                }
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
                try {
            $query->where('status', $request->status);
                } catch (\Exception $e) {
                    // Column doesn't exist
                }
            }

            // Try to execute query - handle missing tables gracefully
            // Since we're using DB::table(), we need to manually paginate
            try {
                // Get total count first
                $total = $query->count();
                $perPage = $request->get('per_page', 15);
                $page = $request->get('page', 1);
                $offset = ($page - 1) * $perPage;
                
                // Get paginated data
                $attendanceData = $query->orderBy('date', 'desc')
                                  ->offset($offset)
                                  ->limit($perPage)
                                  ->get();
                
                // Create paginator manually
                $attendance = new \Illuminate\Pagination\LengthAwarePaginator(
                    $attendanceData,
                    $total,
                    $perPage,
                    $page,
                    ['path' => $request->url(), 'query' => $request->query()]
                );
                
                // Get all records for summary (rebuild query with same filters)
                $summaryQuery = DB::table('attendances')
                    ->where('attendanceable_type', 'App\\Models\\Teacher');
                
                // Reapply filters for summary
                if ($request->has('teacher_id')) {
                    $summaryQuery->where('attendanceable_id', $request->teacher_id);
                }
                if ($request->has('department_id')) {
                    try {
                        $teacherIds = DB::table('teachers')->where('department_id', $request->department_id)->pluck('id');
                        if ($teacherIds->isNotEmpty()) {
                            $summaryQuery->whereIn('attendanceable_id', $teacherIds);
                        }
                    } catch (\Exception $e) {}
                }
                if ($request->has('date')) {
                    $summaryQuery->whereDate('date', $request->date);
                }
                if ($request->has('date_range')) {
                    $dates = explode(' to ', $request->date_range);
                    if (count($dates) === 2) {
                        $summaryQuery->whereBetween('date', [Carbon::parse($dates[0]), Carbon::parse($dates[1])]);
                    }
                }
                if ($request->has('status')) {
                    $summaryQuery->where('status', $request->status);
                }
                
                $allAttendance = $summaryQuery->get();
            } catch (\Exception $e) {
                // Table doesn't exist or query failed
                return response()->json([
                    'attendance' => [
                        'data' => [],
                        'current_page' => 1,
                        'per_page' => 15,
                        'total' => 0
                    ],
                    'summary' => [
                        'total_records' => 0,
                        'present' => 0,
                        'absent' => 0,
                        'late' => 0,
                        'excused' => 0,
                        'attendance_rate' => 0,
                        'punctuality_rate' => 0,
                    ]
                ]);
            }

        $response = [
            'attendance' => $attendance,
                'summary' => $this->safeDbOperation(function() use ($allAttendance) {
                    return $this->getAttendanceSummary($allAttendance);
                }, [
                    'total_records' => 0,
                    'present' => 0,
                    'absent' => 0,
                    'late' => 0,
                    'excused' => 0,
                    'attendance_rate' => 0,
                    'punctuality_rate' => 0,
                ])
        ];

        $this->cacheService->set($cacheKey, $response, 300); // 5 minutes cache

        return response()->json($response);
        } catch (\Exception $e) {
            return response()->json([
                'attendance' => [
                    'data' => [],
                    'current_page' => 1,
                    'per_page' => 15,
                    'total' => 0
                ],
                'summary' => [
                    'total_records' => 0,
                    'present' => 0,
                    'absent' => 0,
                    'late' => 0,
                    'excused' => 0,
                    'attendance_rate' => 0,
                    'punctuality_rate' => 0,
                ]
            ]);
        }
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
        try {
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

            $attendance = $query->get();

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
        } catch (\Exception $e) {
            return response()->json([
                'period' => [
                    'start_date' => Carbon::now()->startOfMonth(),
                    'end_date' => Carbon::now()->endOfMonth(),
                ],
                'summary' => [
                    'total' => 0,
                    'present' => 0,
                    'absent' => 0,
                    'late' => 0,
                    'excused' => 0
                ],
                'daily_breakdown' => [],
                'top_absentees' => [],
                'attendance_trends' => []
            ]);
        }
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

    /**
     * List all attendance records
     */
    public function index(Request $request): JsonResponse
    {
        $query = Attendance::with(['attendanceable.user', 'markedBy']);

        if ($request->has('date')) {
            $query->whereDate('date', $request->date);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('attendanceable_type')) {
            $type = $request->attendanceable_type === 'student' ? Student::class : Teacher::class;
            $query->where('attendanceable_type', $type);
        }

        $attendance = $query->orderBy('date', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json($attendance);
    }

    /**
     * Get attendance record
     */
    public function show($id): JsonResponse
    {
        $attendance = Attendance::with(['attendanceable.user', 'markedBy'])->find($id);

        if (!$attendance) {
            return response()->json(['error' => 'Attendance record not found'], 404);
        }

        return response()->json(['attendance' => $attendance]);
    }

    /**
     * Update attendance record
     */
    public function update(Request $request, $id): JsonResponse
    {
        $attendance = Attendance::find($id);

        if (!$attendance) {
            return response()->json(['error' => 'Attendance record not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'sometimes|in:present,absent,late,excused',
            'check_in_time' => 'nullable|date',
            'check_out_time' => 'nullable|date|after:check_in_time',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $attendance->update($request->only(['status', 'check_in_time', 'check_out_time', 'notes']));

        // Clear cache
        $this->cacheService->invalidateByPattern("attendance:*");

        return response()->json([
            'message' => 'Attendance updated successfully',
            'attendance' => $attendance->fresh(['attendanceable.user', 'markedBy'])
        ]);
    }

    /**
     * Delete attendance record
     */
    public function destroy($id): JsonResponse
    {
        $attendance = Attendance::find($id);

        if (!$attendance) {
            return response()->json(['error' => 'Attendance record not found'], 404);
        }

        $attendance->delete();

        // Clear cache
        $this->cacheService->invalidateByPattern("attendance:*");

        return response()->json([
            'message' => 'Attendance record deleted successfully'
        ]);
    }

    /**
     * Get class attendance
     */
    public function getClassAttendance($classId): JsonResponse
    {
        $studentIds = Student::where('class_id', $classId)->pluck('id');
        
        $attendance = Attendance::where('attendanceable_type', Student::class)
            ->whereIn('attendanceable_id', $studentIds)
            ->with(['attendanceable.user'])
            ->orderBy('date', 'desc')
            ->get();

        return response()->json([
            'class_id' => $classId,
            'attendance' => $attendance,
            'summary' => $this->getAttendanceSummary($attendance)
        ]);
    }

    /**
     * Get student attendance history
     */
    public function getStudentAttendance($studentId): JsonResponse
    {
        try {
            // Check if table exists first
            $tableExists = false;
            try {
                $tableExists = \Illuminate\Support\Facades\Schema::hasTable('attendances');
            } catch (\Exception $e) {
                $tableExists = false;
            }
            
            if (!$tableExists) {
                return response()->json([
                    'student_id' => $studentId,
                    'attendance' => [],
                    'summary' => [
                        'total_records' => 0,
                        'present' => 0,
                        'absent' => 0,
                        'late' => 0,
                        'excused' => 0,
                        'attendance_rate' => 0,
                        'punctuality_rate' => 0,
                    ]
                ]);
            }
            
            // Use DB facade directly to avoid model issues
            try {
                $attendance = DB::table('attendances')
                    ->where('attendanceable_type', 'App\\Models\\Student')
                    ->where('attendanceable_id', $studentId)
                    ->orderBy('date', 'desc')
                    ->get();
            } catch (\Exception $e) {
                // Query failed
                $attendance = collect([]);
            }

            return response()->json([
                'student_id' => $studentId,
                'attendance' => $attendance,
                'summary' => $this->safeDbOperation(function() use ($attendance) {
                    return $this->getAttendanceSummary($attendance);
                }, [
                    'total_records' => 0,
                    'present' => 0,
                    'absent' => 0,
                    'late' => 0,
                    'excused' => 0,
                    'attendance_rate' => 0,
                    'punctuality_rate' => 0,
                ])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'student_id' => $studentId,
                'attendance' => [],
                'summary' => [
                    'total_records' => 0,
                    'present' => 0,
                    'absent' => 0,
                    'late' => 0,
                    'excused' => 0,
                    'attendance_rate' => 0,
                    'punctuality_rate' => 0,
                ]
            ]);
        }
    }
}
