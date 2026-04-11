<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /**
     * Cache TTL in seconds — 5 minutes.
     * Short enough to feel live, long enough to prevent DB hammering under load.
     */
    private const TTL = 300;

    /**
     * Per-tenant cache namespace derived from the active database name.
     * This guarantees tenant isolation even when multiple tenants share one
     * Redis instance.
     */
    private function ns(): string
    {
        return DB::connection()->getDatabaseName();
    }

    /**
     * Bust all dashboard caches for the current tenant.
     * Call this from any controller that creates/updates students, teachers, etc.
     */
    public static function bustCache(): void
    {
        try {
            $db  = DB::connection()->getDatabaseName();
            $key = "dashboard_keys:{$db}";
            $keys = Cache::get($key, []);
            foreach ($keys as $k) {
                Cache::forget($k);
            }
            Cache::forget($key);
        } catch (\Throwable $ignored) {}
    }

    /**
     * Register a cache key so it can be busted later.
     */
    private function remember(string $role, callable $cb): mixed
    {
        $ns  = $this->ns();
        $key = "dashboard:{$ns}:{$role}";

        // Track this key for future invalidation
        $indexKey = "dashboard_keys:{$ns}";
        $keys = Cache::get($indexKey, []);
        if (! in_array($key, $keys, true)) {
            $keys[] = $key;
            Cache::put($indexKey, $keys, self::TTL + 60);
        }

        return Cache::remember($key, self::TTL, $cb);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // General stats (school admin landing)
    // ─────────────────────────────────────────────────────────────────────────

    public function getStats(Request $request): JsonResponse
    {
        try {
            $stats = $this->remember('general', function () {
                $row = DB::selectOne('
                    SELECT
                        (SELECT COUNT(*) FROM users)                                    AS users,
                        (SELECT COUNT(*) FROM students)                                 AS students,
                        (SELECT COUNT(*) FROM teachers)                                 AS teachers,
                        (SELECT COUNT(*) FROM classes)                                  AS classes,
                        (SELECT COUNT(*) FROM subjects)                                 AS subjects,
                        (SELECT COUNT(*) FROM users WHERE role IN (\'staff\',\'admin\')) AS staff
                ');
                return [
                    'users'    => (int) ($row->users    ?? 0),
                    'students' => (int) ($row->students ?? 0),
                    'teachers' => (int) ($row->teachers ?? 0),
                    'classes'  => (int) ($row->classes  ?? 0),
                    'subjects' => (int) ($row->subjects ?? 0),
                    'staff'    => (int) ($row->staff    ?? 0),
                ];
            });

            return response()->json(['stats' => $stats]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to load statistics', 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Admin / School Admin dashboard
    // ─────────────────────────────────────────────────────────────────────────

    public function admin(Request $request): JsonResponse
    {
        try {
            $stats = $this->remember('admin', function () {
                $row = DB::selectOne('
                    SELECT
                        (SELECT COUNT(*) FROM students)                                                         AS total_students,
                        (SELECT COUNT(*) FROM teachers)                                                         AS total_teachers,
                        (SELECT COUNT(*) FROM classes)                                                          AS total_classes,
                        (SELECT COUNT(*) FROM subjects)                                                         AS total_subjects,
                        (SELECT COUNT(*) FROM exams WHERE status = \'active\')                                  AS active_exams,
                        (SELECT COUNT(*) FROM assignments WHERE status = \'pending\')                           AS pending_assignments,
                        (SELECT COALESCE(SUM(amount), 0) FROM payments)                                        AS total_fees_collected,
                        (SELECT COUNT(*) FROM attendances
                           WHERE DATE(date) = CURDATE() AND status = \'present\')                              AS attendance_today
                ');
                return [
                    'total_students'      => (int)   ($row->total_students      ?? 0),
                    'total_teachers'      => (int)   ($row->total_teachers      ?? 0),
                    'total_classes'       => (int)   ($row->total_classes       ?? 0),
                    'total_subjects'      => (int)   ($row->total_subjects      ?? 0),
                    'active_exams'        => (int)   ($row->active_exams        ?? 0),
                    'pending_assignments' => (int)   ($row->pending_assignments ?? 0),
                    'total_fees_collected'=> (float) ($row->total_fees_collected ?? 0),
                    'attendance_today'    => (int)   ($row->attendance_today    ?? 0),
                ];
            });

            return response()->json(['user' => Auth::user(), 'stats' => $stats, 'role' => 'admin']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to load admin dashboard', 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Teacher dashboard
    // ─────────────────────────────────────────────────────────────────────────

    public function teacher(Request $request): JsonResponse
    {
        try {
            $user    = Auth::user();
            $teacher = $this->safeDbOperation(fn () =>
                \Illuminate\Support\Facades\Schema::hasTable('teachers')
                    ? DB::table('teachers')->where('user_id', $user->id)->first()
                    : null
            );

            if (! $teacher) {
                return response()->json(['error' => 'Teacher profile not found'], 404);
            }

            $tid   = $teacher->id;
            $stats = $this->remember("teacher:{$tid}", function () use ($tid) {
                $row = DB::selectOne('
                    SELECT
                        (SELECT COUNT(*) FROM classes WHERE class_teacher_id = ?)            AS my_classes,
                        (SELECT COUNT(*) FROM subjects WHERE teacher_id = ?)                 AS my_subjects,
                        (SELECT COUNT(*) FROM students s
                           JOIN classes c ON s.class_id = c.id
                           WHERE c.class_teacher_id = ?)                                    AS my_students,
                        (SELECT COUNT(*) FROM assignments
                           WHERE teacher_id = ? AND status = \'pending\')                   AS pending_assignments,
                        (SELECT COUNT(*) FROM exams
                           WHERE created_by = ? AND start_date > NOW())                     AS upcoming_exams
                ', [$tid, $tid, $tid, $tid, $tid]);

                return [
                    'my_classes'          => (int) ($row->my_classes          ?? 0),
                    'my_subjects'         => (int) ($row->my_subjects         ?? 0),
                    'my_students'         => (int) ($row->my_students         ?? 0),
                    'pending_assignments' => (int) ($row->pending_assignments ?? 0),
                    'upcoming_exams'      => (int) ($row->upcoming_exams      ?? 0),
                ];
            });

            return response()->json(['user' => $user, 'teacher' => $teacher, 'stats' => $stats, 'role' => 'teacher']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to load teacher dashboard', 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Student dashboard
    // ─────────────────────────────────────────────────────────────────────────

    public function student(Request $request): JsonResponse
    {
        try {
            $user    = Auth::user();
            $student = $this->safeDbOperation(fn () =>
                \Illuminate\Support\Facades\Schema::hasTable('students')
                    ? DB::table('students')->where('user_id', $user->id)->first()
                    : null
            );

            if (! $student) {
                return response()->json(['error' => 'Student profile not found'], 404);
            }

            $sid   = $student->id;
            $cid   = $student->class_id;
            $stats = $this->remember("student:{$sid}", function () use ($sid, $cid) {
                // Batch scalar counts in one query
                $row = DB::selectOne('
                    SELECT
                        (SELECT COUNT(*) FROM subjects WHERE class_id = ?)                               AS my_subjects,
                        (SELECT COUNT(*) FROM assignments WHERE class_id = ? AND status = \'active\')   AS pending_assignments,
                        (SELECT COUNT(*) FROM exams WHERE class_id = ? AND start_date > NOW())           AS upcoming_exams,
                        (SELECT COUNT(*) FROM attendances
                           WHERE attendanceable_id = ?
                             AND attendanceable_type = \'App\\\\Models\\\\Student\')                    AS total_days,
                        (SELECT COUNT(*) FROM attendances
                           WHERE attendanceable_id = ?
                             AND attendanceable_type = \'App\\\\Models\\\\Student\'
                             AND status = \'present\')                                                  AS present_days
                ', [$cid, $cid, $cid, $sid, $sid]);

                $totalDays   = (int) ($row->total_days   ?? 0);
                $presentDays = (int) ($row->present_days ?? 0);

                return [
                    'my_subjects'         => (int)  ($row->my_subjects         ?? 0),
                    'pending_assignments' => (int)  ($row->pending_assignments ?? 0),
                    'upcoming_exams'      => (int)  ($row->upcoming_exams      ?? 0),
                    'attendance_rate'     => $totalDays > 0
                                                ? round(($presentDays / $totalDays) * 100, 2)
                                                : 0,
                    'recent_grades'       => DB::table('grades')
                                                ->where('student_id', $sid)
                                                ->orderByDesc('created_at')
                                                ->limit(5)
                                                ->get(),
                ];
            });

            // Class info is cheap and changes rarely — keep it separate so the
            // student card always shows fresh data without busting the stat cache.
            $myClass = $this->safeDbOperation(fn () => DB::table('classes')->find($cid));

            return response()->json([
                'user' => $user, 'student' => $student,
                'stats' => array_merge($stats, ['my_class' => $myClass]),
                'role'  => 'student',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to load student dashboard', 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Parent / Guardian dashboard
    // ─────────────────────────────────────────────────────────────────────────

    public function parent(Request $request): JsonResponse
    {
        try {
            $user     = Auth::user();
            $guardian = $this->safeDbOperation(fn () =>
                \Illuminate\Support\Facades\Schema::hasTable('guardians')
                    ? DB::table('guardians')->where('user_id', $user->id)->first()
                    : null
            );

            if (! $guardian) {
                return response()->json(['error' => 'Guardian profile not found'], 404);
            }

            $gid        = $guardian->id;
            $studentIds = $this->safeDbOperation(fn () =>
                DB::table('guardian_students')->where('guardian_id', $gid)->pluck('student_id'),
                collect([])
            );

            $stats = $this->remember("parent:{$gid}", function () use ($studentIds) {
                $ids = $studentIds->toArray();
                return [
                    'children_count'       => count($ids),
                    'children'             => count($ids) > 0
                                                 ? DB::table('students')->whereIn('id', $ids)->get()
                                                 : [],
                    'pending_fees'         => count($ids) > 0
                                                 ? (float) DB::table('fees')
                                                       ->whereIn('student_id', $ids)
                                                       ->where('status', '!=', 'paid')
                                                       ->sum('amount')
                                                 : 0,
                    'recent_announcements' => DB::table('announcements')
                                                 ->where('target_audience', 'parents')
                                                 ->where('is_published', true)
                                                 ->orderByDesc('created_at')
                                                 ->limit(5)
                                                 ->get(),
                ];
            });

            return response()->json(['user' => $user, 'guardian' => $guardian, 'stats' => $stats, 'role' => 'parent']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to load parent dashboard', 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Super Admin dashboard (central DB — no tenant cache needed)
    // ─────────────────────────────────────────────────────────────────────────

    public function superAdmin(Request $request): JsonResponse
    {
        $overview = Cache::remember('dashboard:central:super_admin', self::TTL, function () {
            $row = DB::selectOne('
                SELECT
                    (SELECT COUNT(*) FROM tenants)                              AS total_tenants,
                    (SELECT COUNT(*) FROM tenants WHERE status = \'active\')    AS active_tenants,
                    (SELECT COUNT(*) FROM tenants WHERE status = \'suspended\') AS suspended_tenants,
                    (SELECT COUNT(*) FROM schools)                              AS total_schools,
                    (SELECT COUNT(*) FROM users WHERE role = \'student\')       AS total_students,
                    (SELECT COUNT(*) FROM users WHERE role = \'teacher\')       AS total_teachers
            ');
            return [
                'total_tenants'     => (int) ($row->total_tenants     ?? 0),
                'active_tenants'    => (int) ($row->active_tenants    ?? 0),
                'suspended_tenants' => (int) ($row->suspended_tenants ?? 0),
                'total_schools'     => (int) ($row->total_schools     ?? 0),
                'total_students'    => (int) ($row->total_students    ?? 0),
                'total_teachers'    => (int) ($row->total_teachers    ?? 0),
                'total_revenue_ngn' => 0,
            ];
        });

        $recentTenants = DB::table('tenants')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'name', 'status', 'subscription_plan', 'created_at'])
            ->map(fn ($t) => [
                'id'         => $t->id,
                'name'       => $t->name,
                'status'     => $t->status ?? 'active',
                'plan'       => $t->subscription_plan ?? 'basic',
                'created_at' => $t->created_at,
            ])->values()->all();

        try {
            $expiringSubscriptions = DB::table('subscriptions')
                ->join('schools', 'subscriptions.school_id', '=', 'schools.id')
                ->leftJoin('plans', 'subscriptions.plan_id', '=', 'plans.id')
                ->where('subscriptions.end_date', '>=', now())
                ->where('subscriptions.end_date', '<=', now()->addDays(30))
                ->where('subscriptions.status', 'active')
                ->orderBy('subscriptions.end_date')
                ->limit(10)
                ->get(['subscriptions.id as subscription_id', 'schools.name as school_name', 'plans.name as plan', 'subscriptions.end_date'])
                ->map(fn ($s) => [
                    'tenant_id'      => $s->subscription_id,
                    'school_name'    => $s->school_name,
                    'plan'           => $s->plan ?? 'Unknown',
                    'end_date'       => $s->end_date,
                    'days_remaining' => now()->diffInDays($s->end_date),
                ])->values()->all();
        } catch (\Exception $e) {
            $expiringSubscriptions = [];
        }

        return response()->json([
            'overview'               => $overview,
            'recent_tenants'         => $recentTenants,
            'expiring_subscriptions' => $expiringSubscriptions,
            'revenue_by_month'       => [],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Finance / Accountant dashboard
    // ─────────────────────────────────────────────────────────────────────────

    public function finance(Request $request): JsonResponse
    {
        try {
            $stats = $this->remember('finance', function () {
                $rev = DB::selectOne('
                    SELECT
                        COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN amount ELSE 0 END), 0)                  AS today,
                        COALESCE(SUM(CASE WHEN MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW()) THEN amount ELSE 0 END), 0) AS this_month,
                        COALESCE(SUM(CASE WHEN YEAR(created_at) = YEAR(NOW()) THEN amount ELSE 0 END), 0)                AS this_year
                    FROM payments
                ');
                $fee = DB::selectOne('
                    SELECT
                        COALESCE(SUM(f.amount), 0)         AS amount,
                        COUNT(DISTINCT f.student_id) AS students
                    FROM fees f
                    WHERE f.status = \'pending\'
                ');
                $exp = DB::selectOne('
                    SELECT
                        COALESCE(SUM(CASE WHEN DATE(expense_date) = CURDATE() THEN amount ELSE 0 END), 0)                    AS today,
                        COALESCE(SUM(CASE WHEN MONTH(expense_date) = MONTH(NOW()) AND YEAR(expense_date) = YEAR(NOW()) THEN amount ELSE 0 END), 0) AS this_month
                    FROM expenses
                ');
                return [
                    'total_revenue' => [
                        'today'      => (float) ($rev->today      ?? 0),
                        'this_month' => (float) ($rev->this_month ?? 0),
                        'this_year'  => (float) ($rev->this_year  ?? 0),
                    ],
                    'pending_fees' => [
                        'amount'   => (float) ($fee->amount   ?? 0),
                        'students' => (int)   ($fee->students ?? 0),
                    ],
                    'expenses' => [
                        'today'      => (float) ($exp->today      ?? 0),
                        'this_month' => (float) ($exp->this_month ?? 0),
                    ],
                ];
            });

            $user = Auth::user();
            return response()->json([
                'user'  => $user,
                'stats' => $stats,
                'role'  => in_array($user->role, ['accountant', 'finance']) ? $user->role : 'finance',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to load finance dashboard', 'message' => $e->getMessage()], 500);
        }
    }

    public function accountant(Request $request): JsonResponse
    {
        return $this->finance($request);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Librarian dashboard
    // ─────────────────────────────────────────────────────────────────────────

    public function librarian(Request $request): JsonResponse
    {
        try {
            $stats = $this->remember('librarian', function () {
                $row = DB::selectOne('
                    SELECT
                        (SELECT COUNT(*) FROM library_books)                                    AS total_books,
                        (SELECT COUNT(*) FROM library_borrows WHERE status = \'borrowed\')      AS borrowed_books,
                        (SELECT COUNT(*) FROM library_borrows
                           WHERE status = \'borrowed\' AND due_date < NOW())                   AS overdue_books,
                        (SELECT COUNT(*) FROM students WHERE status = \'active\')              AS total_members
                ');
                return [
                    'total_books'    => (int) ($row->total_books    ?? 0),
                    'borrowed_books' => (int) ($row->borrowed_books ?? 0),
                    'overdue_books'  => (int) ($row->overdue_books  ?? 0),
                    'total_members'  => (int) ($row->total_members  ?? 0),
                ];
            });

            return response()->json(['user' => Auth::user(), 'stats' => $stats, 'role' => 'librarian']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to load librarian dashboard', 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Principal / Vice Principal dashboard
    // ─────────────────────────────────────────────────────────────────────────

    public function principal(Request $request): JsonResponse
    {
        try {
            $stats = $this->remember('principal', function () {
                $row = DB::selectOne('
                    SELECT
                        (SELECT COUNT(*) FROM students WHERE status = \'active\')          AS total_students,
                        (SELECT COUNT(*) FROM teachers WHERE status = \'active\')          AS total_teachers,
                        (SELECT COUNT(*) FROM staff    WHERE status = \'active\')          AS total_staff,
                        (SELECT COUNT(*) FROM classes  WHERE status = \'active\')          AS total_classes,
                        (SELECT COUNT(*) FROM students WHERE status = \'active\')          AS student_total_for_rate,
                        (SELECT COUNT(*) FROM attendances
                           WHERE attendanceable_type = \'App\\\\Models\\\\Student\'
                             AND DATE(date) = CURDATE()
                             AND status = \'present\')                                    AS students_present_today,
                        (SELECT COUNT(*) FROM teachers WHERE status = \'active\')          AS teacher_total_for_rate,
                        (SELECT COUNT(*) FROM attendances
                           WHERE attendanceable_type = \'App\\\\Models\\\\Teacher\'
                             AND DATE(date) = CURDATE()
                             AND status = \'present\')                                    AS teachers_present_today
                ');
                $sTotal = (int) ($row->total_students          ?? 0);
                $sPresent= (int) ($row->students_present_today ?? 0);
                $tTotal = (int) ($row->total_teachers          ?? 0);
                $tPresent= (int) ($row->teachers_present_today ?? 0);
                return [
                    'school_overview' => [
                        'total_students' => $sTotal,
                        'total_teachers' => $tTotal,
                        'total_staff'    => (int) ($row->total_staff   ?? 0),
                        'total_classes'  => (int) ($row->total_classes ?? 0),
                    ],
                    'attendance_today' => [
                        'students' => $sTotal > 0 ? round(($sPresent / $sTotal) * 100, 1) : 0,
                        'teachers' => $tTotal > 0 ? round(($tPresent / $tTotal) * 100, 1) : 0,
                    ],
                ];
            });

            return response()->json(['user' => Auth::user(), 'stats' => $stats, 'role' => 'principal']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to load principal dashboard', 'message' => $e->getMessage()], 500);
        }
    }

    public function vicePrincipal(Request $request): JsonResponse
    {
        return $this->principal($request);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HOD dashboard
    // ─────────────────────────────────────────────────────────────────────────

    public function hod(Request $request): JsonResponse
    {
        try {
            $user    = Auth::user();
            $teacher = $this->safeDbOperation(fn () =>
                DB::table('teachers')->where('user_id', $user->id)->first()
            );

            if (! $teacher) {
                return response()->json(['error' => 'Teacher profile not found'], 404);
            }

            $did   = $teacher->department_id ?? 0;
            $stats = $this->remember("hod:{$did}", function () use ($did) {
                $row = DB::selectOne('
                    SELECT
                        (SELECT COUNT(*) FROM teachers WHERE department_id = ? AND status = \'active\') AS department_teachers,
                        (SELECT COUNT(*) FROM subjects WHERE department_id = ?)                        AS department_subjects,
                        (SELECT COUNT(*) FROM classes  WHERE department_id = ? AND status = \'active\') AS department_classes
                ', [$did, $did, $did]);
                return [
                    'department_teachers' => (int) ($row->department_teachers ?? 0),
                    'department_subjects' => (int) ($row->department_subjects ?? 0),
                    'department_classes'  => (int) ($row->department_classes  ?? 0),
                ];
            });

            return response()->json(['user' => $user, 'teacher' => $teacher, 'stats' => $stats, 'role' => 'hod']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to load HOD dashboard', 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Driver dashboard
    // ─────────────────────────────────────────────────────────────────────────

    public function driver(Request $request): JsonResponse
    {
        try {
            $user   = Auth::user();
            $driver = $this->safeDbOperation(fn () =>
                DB::table('drivers')->where('user_id', $user->id)->first()
            );

            if (! $driver) {
                return response()->json(['error' => 'Driver profile not found'], 404);
            }

            $did   = $driver->id;
            $rid   = $driver->route_id ?? 0;
            $stats = $this->remember("driver:{$did}", function () use ($did, $rid) {
                $row = DB::selectOne('
                    SELECT
                        (SELECT COUNT(*) FROM transport_trips
                           WHERE driver_id = ? AND DATE(trip_date) = CURDATE())    AS today_trips,
                        (SELECT COUNT(*) FROM transport_students WHERE route_id = ?) AS students_on_route
                ', [$did, $rid]);
                return [
                    'today_trips'       => (int) ($row->today_trips       ?? 0),
                    'students_on_route' => (int) ($row->students_on_route ?? 0),
                ];
            });

            return response()->json(['user' => $user, 'driver' => $driver, 'stats' => $stats, 'role' => 'driver']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to load driver dashboard', 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Nurse dashboard
    // ─────────────────────────────────────────────────────────────────────────

    public function nurse(Request $request): JsonResponse
    {
        try {
            $stats = $this->remember('nurse', function () {
                $row = DB::selectOne('
                    SELECT
                        (SELECT COUNT(*) FROM health_records WHERE DATE(visit_date) = CURDATE())         AS clinic_visits_today,
                        (SELECT COUNT(*) FROM students WHERE medical_info IS NOT NULL
                           AND medical_info != \'[]\' AND medical_info != \'null\'
                           AND medical_info != \'{}\')                                                   AS chronic_conditions,
                        (SELECT COUNT(*) FROM medications
                           WHERE DATE(scheduled_date) = CURDATE() AND status = \'pending\')             AS medications_due_today
                ');
                return [
                    'clinic_visits_today'          => (int) ($row->clinic_visits_today  ?? 0),
                    'students_with_chronic_conditions' => (int) ($row->chronic_conditions  ?? 0),
                    'medications_due_today'         => (int) ($row->medications_due_today ?? 0),
                ];
            });

            return response()->json(['user' => Auth::user(), 'stats' => $stats, 'role' => 'nurse']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to load nurse dashboard', 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Security dashboard
    // ─────────────────────────────────────────────────────────────────────────

    public function security(Request $request): JsonResponse
    {
        try {
            $stats = $this->remember('security', function () {
                $row = DB::selectOne('
                    SELECT
                        (SELECT COUNT(*) FROM visitors WHERE DATE(entry_time) = CURDATE())  AS visitors_today,
                        (SELECT COUNT(*) FROM visitors WHERE exit_time IS NULL)             AS active_visitors,
                        (SELECT COUNT(*) FROM gate_passes WHERE DATE(created_at) = CURDATE()) AS gate_passes_today,
                        (SELECT COUNT(*) FROM security_incidents
                           WHERE reported_time BETWEEN ? AND ?)                            AS incidents_this_week
                ', [now()->startOfWeek(), now()->endOfWeek()]);

                return [
                    'visitors_today'      => (int) ($row->visitors_today      ?? 0),
                    'active_visitors'     => (int) ($row->active_visitors      ?? 0),
                    'gate_passes_today'   => (int) ($row->gate_passes_today   ?? 0),
                    'incidents_this_week' => (int) ($row->incidents_this_week ?? 0),
                ];
            });

            return response()->json(['user' => Auth::user(), 'stats' => $stats, 'role' => 'security']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to load security dashboard', 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    protected function safeDbOperation(callable $cb, mixed $default = null): mixed
    {
        try {
            return $cb();
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
