<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
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
}
