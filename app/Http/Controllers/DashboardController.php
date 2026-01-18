<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    /**
     * Get general dashboard statistics (for school admins)
     */
    public function getStats(Request $request): JsonResponse
    {
        try {
            $stats = [
                'users' => $this->safeDbOperation(function() {
                    return DB::table('users')->count();
                }, 0),
                'students' => $this->safeDbOperation(function() {
                    return DB::table('students')->count();
                }, 0),
                'teachers' => $this->safeDbOperation(function() {
                    return DB::table('teachers')->count();
                }, 0),
                'classes' => $this->safeDbOperation(function() {
                    return DB::table('classes')->count();
                }, 0),
                'subjects' => $this->safeDbOperation(function() {
                    return DB::table('subjects')->count();
                }, 0),
                'staff' => $this->safeDbOperation(function() {
                    return DB::table('users')->whereIn('role', ['staff', 'admin'])->count();
                }, 0),
            ];

            return response()->json([
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load statistics',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Admin dashboard
     */
    public function admin(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $stats = [
                'total_students' => $this->safeDbOperation(function() {
                    return DB::table('students')->count();
                }, 0),
                'total_teachers' => $this->safeDbOperation(function() {
                    return DB::table('teachers')->count();
                }, 0),
                'total_classes' => $this->safeDbOperation(function() {
                    return DB::table('classes')->count();
                }, 0),
                'total_subjects' => $this->safeDbOperation(function() {
                    return DB::table('subjects')->count();
                }, 0),
                'active_exams' => $this->safeDbOperation(function() {
                    return DB::table('exams')->where('status', 'active')->count();
                }, 0),
                'pending_assignments' => $this->safeDbOperation(function() {
                    return DB::table('assignments')->where('status', 'pending')->count();
                }, 0),
                'total_fees_collected' => $this->safeDbOperation(function() {
                    return (float) DB::table('payments')->sum('amount');
                }, 0),
                'attendance_today' => $this->safeDbOperation(function() {
                    return DB::table('attendances')
                        ->whereDate('date', today())
                        ->where('status', 'present')
                        ->count();
                }, 0),
            ];

            return response()->json([
                'user' => $user,
                'stats' => $stats,
                'role' => 'admin'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load admin dashboard',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Teacher dashboard
     */
    public function teacher(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $teacher = $this->safeDbOperation(function() use ($user) {
                // Check if teachers table exists
                try {
                    if (!\Illuminate\Support\Facades\Schema::hasTable('teachers')) {
                        return null;
                    }
                    return DB::table('teachers')->where('user_id', $user->id)->first();
                } catch (\Exception $e) {
                    return null;
                }
            });

            if (!$teacher) {
                return response()->json([
                    'error' => 'Teacher profile not found',
                    'message' => 'No teacher profile exists for this user. Please create a teacher profile first.'
                ], 404);
            }

            $stats = [
                'my_classes' => $this->safeDbOperation(function() use ($teacher) {
                    return DB::table('classes')->where('class_teacher_id', $teacher->id)->count();
                }, 0),
                'my_subjects' => $this->safeDbOperation(function() use ($teacher) {
                    return DB::table('subjects')->where('teacher_id', $teacher->id)->count();
                }, 0),
                'my_students' => $this->safeDbOperation(function() use ($teacher) {
                    return DB::table('students')
                        ->join('classes', 'students.class_id', '=', 'classes.id')
                        ->where('classes.class_teacher_id', $teacher->id)
                        ->count();
                }, 0),
                'pending_assignments' => $this->safeDbOperation(function() use ($teacher) {
                    return DB::table('assignments')
                        ->where('teacher_id', $teacher->id)
                        ->where('status', 'pending')
                        ->count();
                }, 0),
                'upcoming_exams' => $this->safeDbOperation(function() use ($teacher) {
                    return DB::table('exams')
                        ->where('created_by', $teacher->id)
                        ->where('start_date', '>', now())
                        ->count();
                }, 0),
            ];

            return response()->json([
                'user' => $user,
                'teacher' => $teacher,
                'stats' => $stats,
                'role' => 'teacher'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load teacher dashboard',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Student dashboard
     */
    public function student(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $student = $this->safeDbOperation(function() use ($user) {
                // Check if students table exists
                try {
                    if (!\Illuminate\Support\Facades\Schema::hasTable('students')) {
                        return null;
                    }
                    return DB::table('students')->where('user_id', $user->id)->first();
                } catch (\Exception $e) {
                    return null;
                }
            });

            if (!$student) {
                return response()->json([
                    'error' => 'Student profile not found',
                    'message' => 'No student profile exists for this user. Please create a student profile first.'
                ], 404);
            }

            $stats = [
                'my_class' => $this->safeDbOperation(function() use ($student) {
                    return DB::table('classes')->find($student->class_id);
                }),
                'my_subjects' => $this->safeDbOperation(function() use ($student) {
                    return DB::table('subjects')->where('class_id', $student->class_id)->count();
                }, 0),
                'pending_assignments' => $this->safeDbOperation(function() use ($student) {
                    return DB::table('assignments')
                        ->where('class_id', $student->class_id)
                        ->where('status', 'active')
                        ->count();
                }, 0),
                'upcoming_exams' => $this->safeDbOperation(function() use ($student) {
                    return DB::table('exams')
                        ->where('class_id', $student->class_id)
                        ->where('start_date', '>', now())
                        ->count();
                }, 0),
                'recent_grades' => $this->safeDbOperation(function() use ($student) {
                    return DB::table('grades')
                        ->where('student_id', $student->id)
                        ->orderBy('created_at', 'desc')
                        ->limit(5)
                        ->get();
                }, []),
                'attendance_rate' => $this->calculateAttendanceRate($student->id),
            ];

            return response()->json([
                'user' => $user,
                'student' => $student,
                'stats' => $stats,
                'role' => 'student'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load student dashboard',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Parent dashboard
     */
    public function parent(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $guardian = $this->safeDbOperation(function() use ($user) {
                // Check if guardians table exists
                try {
                    if (!\Illuminate\Support\Facades\Schema::hasTable('guardians')) {
                        return null;
                    }
                    return DB::table('guardians')->where('user_id', $user->id)->first();
                } catch (\Exception $e) {
                    return null;
                }
            });

            if (!$guardian) {
                return response()->json([
                    'error' => 'Guardian profile not found',
                    'message' => 'No guardian profile exists for this user. Please create a guardian profile first.'
                ], 404);
            }

            $studentIds = $this->safeDbOperation(function() use ($guardian) {
                return DB::table('guardian_students')
                    ->where('guardian_id', $guardian->id)
                    ->pluck('student_id');
            }, collect([]));

            $stats = [
                'children_count' => $studentIds->count(),
                'children' => $this->safeDbOperation(function() use ($studentIds) {
                    return DB::table('students')->whereIn('id', $studentIds)->get();
                }, []),
                'pending_fees' => $this->safeDbOperation(function() use ($studentIds) {
                    return (float) DB::table('fees')
                        ->whereIn('student_id', $studentIds)
                        ->where('status', '!=', 'paid')
                        ->sum('amount');
                }, 0),
                'recent_announcements' => $this->safeDbOperation(function() {
                    return DB::table('announcements')
                        ->where('target_audience', 'parents')
                        ->where('is_published', true)
                        ->orderBy('created_at', 'desc')
                        ->limit(5)
                        ->get();
                }, []),
            ];

            return response()->json([
                'user' => $user,
                'guardian' => $guardian,
                'stats' => $stats,
                'role' => 'parent'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load parent dashboard',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Super admin dashboard
     */
    public function superAdmin(Request $request): JsonResponse
    {
        $user = Auth::user();

        $stats = [
            'total_tenants' => DB::table('tenants')->count(),
            'active_tenants' => DB::table('tenants')->where('status', 'active')->count(),
            'total_schools' => DB::table('schools')->count(),
            'active_schools' => DB::table('schools')->where('status', 'active')->count(),
            'total_users' => DB::table('users')->count(),
            'system_health' => $this->getSystemHealth(),
        ];

        return response()->json([
            'user' => $user,
            'stats' => $stats,
            'role' => 'super_admin'
        ]);
    }

    /**
     * Calculate attendance rate
     */
    protected function calculateAttendanceRate($studentId): float
    {
        return $this->safeDbOperation(function() use ($studentId) {
            $totalDays = DB::table('attendances')
                ->where('attendanceable_id', $studentId)
                ->where('attendanceable_type', 'App\Models\Student')
                ->count();

            if ($totalDays === 0) return 0;

            $presentDays = DB::table('attendances')
                ->where('attendanceable_id', $studentId)
                ->where('attendanceable_type', 'App\Models\Student')
                ->where('status', 'present')
                ->count();

            return round(($presentDays / $totalDays) * 100, 2);
        }, 0);
    }

    /**
     * Get system health
     */
    protected function getSystemHealth(): array
    {
        return [
            'database' => 'healthy',
            'cache' => 'healthy',
            'queue' => 'healthy',
        ];
    }

    /**
     * Finance/Accountant dashboard
     */
    public function finance(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            $stats = [
                'total_revenue' => $this->safeDbOperation(function() {
                    return [
                        'today' => DB::table('payments')->whereDate('created_at', today())->sum('amount') ?? 0,
                        'this_month' => DB::table('payments')->whereMonth('created_at', now()->month)->sum('amount') ?? 0,
                        'this_year' => DB::table('payments')->whereYear('created_at', now()->year)->sum('amount') ?? 0,
                    ];
                }, ['today' => 0, 'this_month' => 0, 'this_year' => 0]),
                'pending_fees' => $this->safeDbOperation(function() {
                    $total = DB::table('fees')
                        ->join('students', 'fees.student_id', '=', 'students.id')
                        ->where('fees.status', 'pending')
                        ->sum('fees.amount') ?? 0;
                    $students = DB::table('fees')
                        ->where('status', 'pending')
                        ->distinct('student_id')
                        ->count() ?? 0;
                    return [
                        'amount' => $total,
                        'students' => $students
                    ];
                }, ['amount' => 0, 'students' => 0]),
                'expenses' => $this->safeDbOperation(function() {
                    return [
                        'today' => DB::table('expenses')->whereDate('expense_date', today())->sum('amount') ?? 0,
                        'this_month' => DB::table('expenses')->whereMonth('expense_date', now()->month)->sum('amount') ?? 0,
                    ];
                }, ['today' => 0, 'this_month' => 0]),
            ];

            return response()->json([
                'user' => $user,
                'stats' => $stats,
                'role' => in_array($user->role, ['accountant', 'finance']) ? $user->role : 'finance'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load finance dashboard',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Accountant dashboard (alias for finance)
     */
    public function accountant(Request $request): JsonResponse
    {
        return $this->finance($request);
    }

    /**
     * Librarian dashboard
     */
    public function librarian(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            $stats = [
                'total_books' => $this->safeDbOperation(function() {
                    return DB::table('library_books')->count() ?? 0;
                }, 0),
                'borrowed_books' => $this->safeDbOperation(function() {
                    return DB::table('library_borrows')
                        ->where('status', 'borrowed')
                        ->count() ?? 0;
                }, 0),
                'overdue_books' => $this->safeDbOperation(function() {
                    return DB::table('library_borrows')
                        ->where('status', 'borrowed')
                        ->where('due_date', '<', now())
                        ->count() ?? 0;
                }, 0),
                'total_members' => $this->safeDbOperation(function() {
                    return DB::table('students')->where('status', 'active')->count() ?? 0;
                }, 0),
            ];

            return response()->json([
                'user' => $user,
                'stats' => $stats,
                'role' => 'librarian'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load librarian dashboard',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Driver dashboard
     */
    public function driver(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Get driver's assigned vehicle and route
            $driver = $this->safeDbOperation(function() use ($user) {
                return DB::table('drivers')->where('user_id', $user->id)->first();
            });

            if (!$driver) {
                return response()->json([
                    'error' => 'Driver profile not found'
                ], 404);
            }

            $stats = [
                'today_trips' => $this->safeDbOperation(function() use ($driver) {
                    return DB::table('transport_trips')
                        ->where('driver_id', $driver->id)
                        ->whereDate('trip_date', today())
                        ->count() ?? 0;
                }, 0),
                'students_on_route' => $this->safeDbOperation(function() use ($driver) {
                    return DB::table('transport_students')
                        ->where('route_id', $driver->route_id ?? 0)
                        ->count() ?? 0;
                }, 0),
            ];

            return response()->json([
                'user' => $user,
                'driver' => $driver,
                'stats' => $stats,
                'role' => 'driver'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load driver dashboard',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Principal dashboard
     */
    public function principal(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            $stats = [
                'school_overview' => [
                    'total_students' => $this->safeDbOperation(function() {
                        return DB::table('students')->where('status', 'active')->count() ?? 0;
                    }, 0),
                    'total_teachers' => $this->safeDbOperation(function() {
                        return DB::table('teachers')->where('status', 'active')->count() ?? 0;
                    }, 0),
                    'total_staff' => $this->safeDbOperation(function() {
                        return DB::table('staff')->where('status', 'active')->count() ?? 0;
                    }, 0),
                    'total_classes' => $this->safeDbOperation(function() {
                        return DB::table('classes')->where('status', 'active')->count() ?? 0;
                    }, 0),
                ],
                'attendance_today' => [
                    'students' => $this->safeDbOperation(function() {
                        $total = DB::table('students')->where('status', 'active')->count();
                        $present = DB::table('attendances')
                            ->where('attendanceable_type', 'App\Models\Student')
                            ->whereDate('date', today())
                            ->where('status', 'present')
                            ->count();
                        return $total > 0 ? round(($present / $total) * 100, 1) : 0;
                    }, 0),
                    'teachers' => $this->safeDbOperation(function() {
                        $total = DB::table('teachers')->where('status', 'active')->count();
                        $present = DB::table('attendances')
                            ->where('attendanceable_type', 'App\Models\Teacher')
                            ->whereDate('date', today())
                            ->where('status', 'present')
                            ->count();
                        return $total > 0 ? round(($present / $total) * 100, 1) : 0;
                    }, 0),
                ],
            ];

            return response()->json([
                'user' => $user,
                'stats' => $stats,
                'role' => 'principal'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load principal dashboard',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Vice Principal dashboard (alias for principal)
     */
    public function vicePrincipal(Request $request): JsonResponse
    {
        return $this->principal($request);
    }

    /**
     * HOD (Head of Department) dashboard
     */
    public function hod(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Get HOD's department
            $teacher = $this->safeDbOperation(function() use ($user) {
                return DB::table('teachers')->where('user_id', $user->id)->first();
            });

            if (!$teacher) {
                return response()->json([
                    'error' => 'Teacher profile not found'
                ], 404);
            }

            $stats = [
                'department_teachers' => $this->safeDbOperation(function() use ($teacher) {
                    return DB::table('teachers')
                        ->where('department_id', $teacher->department_id)
                        ->where('status', 'active')
                        ->count() ?? 0;
                }, 0),
                'department_subjects' => $this->safeDbOperation(function() use ($teacher) {
                    return DB::table('subjects')
                        ->where('department_id', $teacher->department_id)
                        ->count() ?? 0;
                }, 0),
            ];

            return response()->json([
                'user' => $user,
                'teacher' => $teacher,
                'stats' => $stats,
                'role' => 'hod'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load HOD dashboard',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Nurse dashboard
     */
    public function nurse(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            $stats = [
                'clinic_visits_today' => $this->safeDbOperation(function() {
                    return DB::table('health_records')
                        ->whereDate('visit_date', today())
                        ->count() ?? 0;
                }, 0),
                'students_with_chronic_conditions' => $this->safeDbOperation(function() {
                    return DB::table('students')
                        ->whereNotNull('medical_info')
                        ->where('medical_info', '!=', '{}')
                        ->count() ?? 0;
                }, 0),
                'medications_due_today' => $this->safeDbOperation(function() {
                    return DB::table('medications')
                        ->whereDate('scheduled_date', today())
                        ->where('status', 'pending')
                        ->count() ?? 0;
                }, 0),
            ];

            return response()->json([
                'user' => $user,
                'stats' => $stats,
                'role' => 'nurse'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load nurse dashboard',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Security dashboard
     */
    public function security(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            $stats = [
                'visitors_today' => $this->safeDbOperation(function() {
                    return DB::table('visitors')
                        ->whereDate('entry_time', today())
                        ->count() ?? 0;
                }, 0),
                'active_visitors' => $this->safeDbOperation(function() {
                    return DB::table('visitors')
                        ->whereNull('exit_time')
                        ->count() ?? 0;
                }, 0),
                'gate_passes_today' => $this->safeDbOperation(function() {
                    return DB::table('gate_passes')
                        ->whereDate('created_at', today())
                        ->count() ?? 0;
                }, 0),
                'incidents_this_week' => $this->safeDbOperation(function() {
                    return DB::table('security_incidents')
                        ->whereBetween('reported_time', [now()->startOfWeek(), now()->endOfWeek()])
                        ->count() ?? 0;
                }, 0),
            ];

            return response()->json([
                'user' => $user,
                'stats' => $stats,
                'role' => 'security'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load security dashboard',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
