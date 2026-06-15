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
                        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = \'confirmed\')           AS total_fees_collected,
                        -- tenant payments table tracks received amount in `amount`
                        (SELECT COALESCE(SUM(amount), 0) FROM payments
                           WHERE MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())
                             AND status = \'confirmed\')                                                        AS fees_collected_this_month,
                        (SELECT COALESCE(SUM(balance), 0) FROM fees WHERE status IN (\'pending\',\'partial\')) AS fees_outstanding,
                        (SELECT COUNT(*) FROM students WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS recent_registrations,
                        (SELECT COUNT(*) FROM attendances
                           WHERE DATE(date) = CURDATE() AND status = \'present\')                              AS attendance_today,
                        (SELECT COUNT(*) FROM attendances WHERE DATE(date) = CURDATE())                        AS attendance_total_today
                ');

                $totalToday = (int) ($row->attendance_total_today ?? 0);
                $presentToday = (int) ($row->attendance_today ?? 0);

                // Monthly revenue for chart (last 6 months)
                $revenueByMonth = DB::select('
                    SELECT DATE_FORMAT(payment_date, \'%b %Y\') AS month,
                           DATE_FORMAT(payment_date, \'%Y-%m\') AS sort_key,
                           COALESCE(SUM(amount), 0)            AS amount
                    FROM payments
                    WHERE status = \'confirmed\'
                      AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                    GROUP BY month, sort_key
                    ORDER BY sort_key ASC
                ');

                // Attendance trend (last 7 days)
                $attendanceTrend = DB::select('
                    SELECT DATE_FORMAT(date, \'%a\') AS day,
                           COUNT(*) AS total,
                           SUM(CASE WHEN status = \'present\' THEN 1 ELSE 0 END) AS present
                    FROM attendances
                    WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    GROUP BY date, day
                    ORDER BY date ASC
                ');

                return [
                    'total_students'           => (int)   ($row->total_students           ?? 0),
                    'total_teachers'           => (int)   ($row->total_teachers           ?? 0),
                    'total_classes'            => (int)   ($row->total_classes            ?? 0),
                    'total_subjects'           => (int)   ($row->total_subjects           ?? 0),
                    'active_exams'             => (int)   ($row->active_exams             ?? 0),
                    'pending_assignments'      => (int)   ($row->pending_assignments      ?? 0),
                    'total_fees_collected'     => (float) ($row->total_fees_collected     ?? 0),
                    'fees_collected_this_month'=> (float) ($row->fees_collected_this_month ?? 0),
                    'fees_outstanding'         => (float) ($row->fees_outstanding         ?? 0),
                    'recent_registrations'     => (int)   ($row->recent_registrations     ?? 0),
                    'attendance_today'         => $presentToday,
                    'attendance_rate_today'    => $totalToday > 0 ? round(($presentToday / $totalToday) * 100) : null,
                    'revenue_by_month'         => array_map(fn($r) => ['month' => $r->month, 'amount' => (float) $r->amount], $revenueByMonth),
                    'attendance_trend'         => array_map(fn($r) => ['day' => $r->day, 'count' => (int) $r->present, 'total' => (int) $r->total], $attendanceTrend),
                ];
            });

            return response()->json(['user' => Auth::user(), 'stats' => $stats, 'role' => 'admin', 'dashboard' => $stats]);
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

            $attendanceMarked = $this->safeDbOperation(fn () =>
                \Illuminate\Support\Facades\Schema::hasTable('attendances')
                    ? DB::table('attendances')
                        ->where('attendanceable_type', 'App\\Models\\Teacher')
                        ->where('attendanceable_id', $tid)
                        ->whereDate('date', today())
                        ->exists()
                    : false,
                false
            );

            $dashboard = array_merge($stats, [
                'attendance_marked_today' => (bool) $attendanceMarked,
                'todays_schedule'         => [],
                'recent_submissions'      => [],
            ]);

            return response()->json(['user' => $user, 'teacher' => $teacher, 'stats' => $stats, 'dashboard' => $dashboard, 'role' => 'teacher']);
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
                                                ->select(['id', 'student_id', 'subject', 'score', 'grade', 'created_at'])
                                                ->orderByDesc('created_at')
                                                ->limit(5)
                                                ->get(),
                ];
            });

            // Class info is cheap and changes rarely — keep it separate so the
            // student card always shows fresh data without busting the stat cache.
            $myClass = $this->safeDbOperation(fn () => DB::table('classes')->find($cid));

            $feesPaid = $this->safeDbOperation(fn () =>
                \Illuminate\Support\Facades\Schema::hasTable('fees')
                    ? ! DB::table('fees')->where('student_id', $sid)->where('status', '!=', 'paid')->exists()
                    : true,
                true
            );

            $pendingAssignmentsList = $this->safeDbOperation(fn () =>
                \Illuminate\Support\Facades\Schema::hasTable('assignments')
                    ? DB::table('assignments')
                        ->where('class_id', $cid)
                        ->where('status', 'active')
                        ->limit(10)
                        ->get(['id', 'title', 'subject', 'due_date'])
                        ->map(fn ($a) => ['title' => $a->title, 'subject' => $a->subject ?? '—', 'due_date' => $a->due_date])
                        ->values()->all()
                    : [],
                []
            );

            $recentResultsList = $this->safeDbOperation(fn () =>
                \Illuminate\Support\Facades\Schema::hasTable('grades')
                    ? DB::table('grades')
                        ->where('student_id', $sid)
                        ->orderByDesc('created_at')
                        ->limit(6)
                        ->get(['subject', 'score', 'grade'])
                        ->map(fn ($g) => ['subject' => $g->subject, 'score' => $g->score, 'grade' => $g->grade])
                        ->values()->all()
                    : [],
                []
            );

            $dashboard = [
                'my_subjects'         => $stats['my_subjects'],
                'attendance_rate'     => $stats['attendance_rate'],
                'fees_paid'           => (bool) $feesPaid,
                'class_position'      => null,
                'todays_classes'      => [],
                'pending_assignments' => $pendingAssignmentsList,
                'recent_results'      => $recentResultsList,
            ];

            return response()->json([
                'user' => $user, 'student' => $student,
                'stats' => array_merge($stats, ['my_class' => $myClass]),
                'dashboard' => $dashboard,
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
                                                 ? DB::table('students')
                                                       ->whereIn('id', $ids)
                                                       ->select(['id', 'first_name', 'last_name', 'admission_number', 'class_id', 'status'])
                                                       ->limit(50)
                                                       ->get()
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

            $dashboard = array_merge((array) $stats, [
                'total_fees_due'      => $stats['pending_fees'] ?? 0,
                'avg_attendance'      => null,
                'unread_notifications'=> 0,
                'recent_performance'  => [],
            ]);

            return response()->json(['user' => $user, 'guardian' => $guardian, 'stats' => $stats, 'dashboard' => $dashboard, 'role' => 'parent']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to load parent dashboard', 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Super Admin dashboard (central DB — no tenant cache needed)
    // ─────────────────────────────────────────────────────────────────────────

    public function superAdmin(Request $request): JsonResponse
    {
        // Force central DB — super admin never runs in a tenant context.
        $db = DB::connection('mysql');

        $overview = Cache::remember('dashboard:central:super_admin', self::TTL, function () use ($db) {
            $row = $db->selectOne('
                SELECT
                    (SELECT COUNT(*) FROM tenants)                              AS total_tenants,
                    (SELECT COUNT(*) FROM tenants WHERE status = \'active\')    AS active_tenants,
                    (SELECT COUNT(*) FROM tenants WHERE status = \'suspended\') AS suspended_tenants,
                    (SELECT COUNT(*) FROM schools)                              AS total_schools,
                    (SELECT COALESCE(SUM(amount),0) FROM subscriptions
                       WHERE status IN (\'active\',\'expired\'))                AS total_revenue
            ');

            return [
                'total_tenants'     => (int)   ($row->total_tenants     ?? 0),
                'active_tenants'    => (int)   ($row->active_tenants    ?? 0),
                'suspended_tenants' => (int)   ($row->suspended_tenants ?? 0),
                'total_schools'     => (int)   ($row->total_schools     ?? 0),
                'total_revenue_ngn' => (float) ($row->total_revenue     ?? 0),
            ];
        });

        $recentTenants = Cache::remember('dashboard:central:recent_tenants', self::TTL, function () use ($db) {
            return $db->table('tenants')
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
        });

        // Revenue grouped by month for the last 12 months.
        $revenueByMonth = Cache::remember('dashboard:central:revenue_by_month', self::TTL, function () use ($db) {
            $rows = $db->select('
                SELECT
                    DATE_FORMAT(start_date, \'%b %Y\')        AS month,
                    DATE_FORMAT(start_date, \'%Y-%m\')        AS sort_key,
                    COALESCE(SUM(amount), 0)                   AS amount
                FROM subscriptions
                WHERE start_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                  AND status IN (\'active\', \'expired\', \'cancelled\')
                GROUP BY DATE_FORMAT(start_date, \'%Y-%m\'), DATE_FORMAT(start_date, \'%b %Y\')
                ORDER BY sort_key ASC
            ');
            return array_map(fn ($r) => [
                'month'  => $r->month,
                'amount' => (float) $r->amount,
            ], $rows);
        });

        // Subscriptions expiring within the next 60 days (widened from 30).
        $expiringSubscriptions = Cache::remember('dashboard:central:expiring', self::TTL, function () use ($db) {
            try {
                return $db->table('subscriptions')
                    ->join('schools', 'subscriptions.school_id', '=', 'schools.id')
                    ->leftJoin('plans', 'subscriptions.plan_id', '=', 'plans.id')
                    ->where('subscriptions.status', 'active')
                    ->where('subscriptions.end_date', '>=', now())
                    ->where('subscriptions.end_date', '<=', now()->addDays(60))
                    ->orderBy('subscriptions.end_date')
                    ->limit(15)
                    ->get([
                        'subscriptions.id   as subscription_id',
                        'schools.name       as school_name',
                        'plans.name         as plan',
                        'subscriptions.end_date',
                        'subscriptions.amount',
                    ])
                    ->map(fn ($s) => [
                        'tenant_id'      => $s->subscription_id,
                        'school_name'    => $s->school_name,
                        'plan'           => $s->plan ?? 'Unknown',
                        'end_date'       => $s->end_date,
                        'days_remaining' => (int) now()->diffInDays($s->end_date, false),
                    ])
                    ->values()->all();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('SuperAdmin dashboard expiring query failed', ['error' => $e->getMessage()]);
                return [];
            }
        });

        return response()->json([
            'overview'               => $overview,
            'recent_tenants'         => $recentTenants,
            'expiring_subscriptions' => $expiringSubscriptions,
            'revenue_by_month'       => $revenueByMonth,
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
        try {
            $stats = $this->remember('accountant', function () {
                $rev = DB::selectOne('
                    SELECT
                        COALESCE(SUM(CASE WHEN MONTH(payment_date) = MONTH(NOW()) AND YEAR(payment_date) = YEAR(NOW()) AND status = \'confirmed\' THEN amount ELSE 0 END), 0) AS fees_this_month,
                        COALESCE(SUM(CASE WHEN MONTH(payment_date) = MONTH(DATE_SUB(NOW(), INTERVAL 1 MONTH)) AND YEAR(payment_date) = YEAR(DATE_SUB(NOW(), INTERVAL 1 MONTH)) AND status = \'confirmed\' THEN amount ELSE 0 END), 0) AS fees_last_month
                    FROM payments
                ');
                $feeOut = DB::selectOne('SELECT COALESCE(SUM(balance),0) AS amount FROM fees WHERE status IN (\'pending\',\'partial\')');
                $expOut = DB::selectOne('SELECT COALESCE(SUM(CASE WHEN MONTH(expense_date)=MONTH(NOW()) AND YEAR(expense_date)=YEAR(NOW()) THEN amount ELSE 0 END),0) AS this_month FROM expenses');

                $collectionTrend = DB::select('
                    SELECT DATE_FORMAT(payment_date, \'%b\') AS month,
                           DATE_FORMAT(payment_date, \'%Y-%m\') AS sort_key,
                           COALESCE(SUM(amount),0) AS collected
                    FROM payments
                    WHERE status=\'confirmed\' AND payment_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                    GROUP BY month, sort_key ORDER BY sort_key ASC
                ');

                $recentPayments = DB::table('payments')
                    ->join('students', 'payments.student_id', '=', 'students.id')
                    ->join('users', 'students.user_id', '=', 'users.id')
                    ->where('payments.status', 'confirmed')
                    ->orderByDesc('payments.created_at')
                    ->limit(8)
                    ->get(['users.name as student_name', 'payments.amount', 'payments.description', 'payments.created_at'])
                    ->map(fn ($p) => ['student_name' => $p->student_name, 'amount' => (float)$p->amount, 'description' => $p->description])
                    ->values()->all();

                $defaulters = DB::table('fees')
                    ->join('students', 'fees.student_id', '=', 'students.id')
                    ->join('classes', 'students.class_id', '=', 'classes.id')
                    ->join('users', 'students.user_id', '=', 'users.id')
                    ->whereIn('fees.status', ['pending', 'partial'])
                    ->orderByDesc('fees.balance')
                    ->limit(8)
                    ->get(['users.name', 'classes.name as class_name', 'fees.balance as amount_due'])
                    ->map(fn ($d) => ['name' => $d->name, 'class_name' => $d->class_name, 'amount_due' => (float)$d->amount_due])
                    ->values()->all();

                return [
                    'fees_collected_this_month' => (float) ($rev->fees_this_month  ?? 0),
                    'fees_outstanding'          => (float) ($feeOut->amount         ?? 0),
                    'expenses_this_month'       => (float) ($expOut->this_month     ?? 0),
                    'payroll_due'               => 0,
                    'collection_trend'          => array_map(fn ($r) => ['month' => $r->month, 'collected' => (float)$r->collected], $collectionTrend),
                    'expense_trend'             => [],
                    'revenue_vs_expense'        => [],
                    'recent_payments'           => $recentPayments,
                    'fee_defaulters'            => $defaulters,
                ];
            });

            $user = Auth::user();
            return response()->json([
                'user'      => $user,
                'stats'     => $stats,
                'dashboard' => $stats,
                'role'      => 'accountant',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to load accountant dashboard', 'message' => $e->getMessage()], 500);
        }
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

                $recentIssues = DB::table('library_borrows')
                    ->join('library_books', 'library_borrows.book_id', '=', 'library_books.id')
                    ->join('users', 'library_borrows.user_id', '=', 'users.id')
                    ->where('library_borrows.status', 'borrowed')
                    ->orderByDesc('library_borrows.created_at')
                    ->limit(8)
                    ->get(['library_books.title as book_title', 'users.name as borrower_name', 'library_borrows.created_at as issued_date'])
                    ->map(fn ($r) => ['book_title' => $r->book_title, 'borrower_name' => $r->borrower_name, 'issued_date' => substr($r->issued_date ?? '', 0, 10)])
                    ->values()->all();

                $overdueList = DB::table('library_borrows')
                    ->join('library_books', 'library_borrows.book_id', '=', 'library_books.id')
                    ->join('users', 'library_borrows.user_id', '=', 'users.id')
                    ->where('library_borrows.status', 'borrowed')
                    ->where('library_borrows.due_date', '<', now())
                    ->orderBy('library_borrows.due_date')
                    ->limit(8)
                    ->get(['library_books.title as book_title', 'users.name as borrower_name', 'library_borrows.due_date'])
                    ->map(fn ($r) => [
                        'book_title' => $r->book_title,
                        'borrower_name' => $r->borrower_name,
                        'days_overdue' => (int) now()->diffInDays($r->due_date),
                    ])
                    ->values()->all();

                $popularBooks = DB::table('library_borrows')
                    ->join('library_books', 'library_borrows.book_id', '=', 'library_books.id')
                    ->selectRaw('library_books.title, library_books.author, COUNT(*) as borrow_count')
                    ->groupBy('library_books.id', 'library_books.title', 'library_books.author')
                    ->orderByDesc('borrow_count')
                    ->limit(6)
                    ->get()
                    ->map(fn ($b) => ['title' => $b->title, 'author' => $b->author, 'borrow_count' => (int)$b->borrow_count])
                    ->values()->all();

                return [
                    'total_books'     => (int) ($row->total_books    ?? 0),
                    'books_issued'    => (int) ($row->borrowed_books  ?? 0),
                    'overdue_returns' => (int) ($row->overdue_books   ?? 0),
                    'active_borrowers'=> (int) ($row->total_members   ?? 0),
                    'recent_issues'   => $recentIssues,
                    'overdue_books'   => $overdueList,
                    'popular_books'   => $popularBooks,
                ];
            });

            return response()->json(['user' => Auth::user(), 'stats' => $stats, 'dashboard' => $stats, 'role' => 'librarian']);
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

            // Principal uses AdminDashboard on the frontend — flatten stats into dashboard key
            $dashboard = array_merge(
                $stats['school_overview'] ?? [],
                [
                    'total_students'        => $stats['school_overview']['total_students'] ?? 0,
                    'total_teachers'        => $stats['school_overview']['total_teachers'] ?? 0,
                    'total_classes'         => $stats['school_overview']['total_classes']  ?? 0,
                    'attendance_rate_today' => $stats['attendance_today']['students'] ?? null,
                    'attendance_trend'      => [],
                    'revenue_by_month'      => [],
                ]
            );
            return response()->json(['user' => Auth::user(), 'stats' => $stats, 'dashboard' => $dashboard, 'role' => 'principal']);
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

            $dashboard = array_merge($stats, [
                'my_classes'          => $stats['department_classes'],
                'my_students'         => 0,
                'attendance_marked_today' => false,
                'pending_assignments' => 0,
                'todays_schedule'     => [],
                'recent_submissions'  => [],
            ]);
            return response()->json(['user' => $user, 'teacher' => $teacher, 'stats' => $stats, 'dashboard' => $dashboard, 'role' => 'hod']);
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

            return response()->json(['user' => $user, 'driver' => $driver, 'stats' => $stats, 'dashboard' => $stats, 'role' => 'driver']);
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

            return response()->json(['user' => Auth::user(), 'stats' => $stats, 'dashboard' => $stats, 'role' => 'nurse']);
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

            return response()->json(['user' => Auth::user(), 'stats' => $stats, 'dashboard' => $stats, 'role' => 'security']);
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
