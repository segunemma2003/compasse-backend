<?php

namespace App\Http\Controllers;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Models\Wellbeing\CareLog;
use App\Models\Wellbeing\SenProfile;
use App\Models\Wellbeing\SupportPlan;
use App\Models\Wellbeing\WellbeingReferral;
use App\Models\Wellbeing\WellbeingSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SEN & Wellbeing: SEN register, support plans, therapy/support/counselling
 * sessions, referrals, and the early-years daily care log.
 *
 * Who sees what:
 * - wellbeing.manage (leadership, SEN facilitators/therapists, counsellors): everything,
 *   except counselling session notes, which only counsellors and the session's author read.
 * - care.log (care assistants): the care log for their assigned classes.
 * - Teachers: refer a child; read the SEN summary/strategies for children in their classes.
 * - Parents: the care-log entries shared with them, for their own children.
 */
class WellbeingController extends Controller
{
    private const TEACHER_ROLES = ['teacher', 'class_teacher', 'subject_teacher', 'year_tutor', 'hod'];

    private function canManage(Request $r): bool
    {
        return $this->roleCan($r, 'wellbeing.manage');
    }

    private function canCare(Request $r): bool
    {
        return $this->roleCan($r, 'care.log') || $this->canManage($r);
    }

    private function isCounsellor(User $u): bool
    {
        return $u->role === 'counsellor' || in_array('counsellor', \App\Support\UserEffectiveRoles::forUser($u), true);
    }

    private function isTeacher(User $u): bool
    {
        return in_array($u->role, self::TEACHER_ROLES, true) || (bool) array_intersect(\App\Support\UserEffectiveRoles::forUser($u), self::TEACHER_ROLES);
    }

    private function studentRow(?Student $s): ?array
    {
        return $s ? [
            'id' => $s->id, 'name' => trim("{$s->first_name} {$s->last_name}"),
            'admission_number' => $s->admission_number, 'class_id' => $s->class_id, 'class' => $s->class?->name,
        ] : null;
    }

    // ─── Overview (role dashboards) ───────────────────────────────────────

    /** GET /wellbeing/overview */
    public function overview(Request $request): JsonResponse
    {
        $u = $request->user();
        $today = Carbon::today();

        $data = [
            'role' => $u->role,
            'can_manage' => $this->canManage($request),
            'can_care' => $this->canCare($request),
        ];

        if ($this->canManage($request)) {
            $data['sen_active'] = SenProfile::where('active', true)->count();
            $data['my_caseload'] = SenProfile::where('active', true)->get(['assigned_user_ids'])
                ->filter(fn ($p) => in_array($u->id, $p->assigned_user_ids ?? [], true))->count();
            $data['open_referrals'] = WellbeingReferral::where('status', '!=', 'closed')
                ->when($this->isCounsellor($u), fn ($q) => $q->where('referred_to', 'counsellor'))
                ->count();
            $data['reviews_due'] = SupportPlan::where('status', 'active')
                ->whereBetween('review_date', [$today->copy()->subYears(5), $today->copy()->addDays(14)])->count();
            $data['my_sessions_week'] = WellbeingSession::where('staff_user_id', $u->id)
                ->where('session_date', '>=', $today->copy()->startOfWeek())->count();
        }
        if ($this->canCare($request)) {
            $classIds = $this->careClassIds($u);
            $data['care_classes'] = \App\Models\ClassModel::when($classIds !== null, fn ($q) => $q->whereIn('id', $classIds))
                ->orderBy('name')->get(['id', 'name']);
            $data['care_logs_today'] = CareLog::whereDate('logged_at', $today)
                ->when($classIds !== null, fn ($q) => $q->whereIn('student_id', Student::whereIn('class_id', $classIds)->pluck('id')))
                ->count();
        }

        return response()->json($data);
    }

    // ─── SEN register ─────────────────────────────────────────────────────

    /** GET /wellbeing/sen?search=&mine=1 */
    public function senIndex(Request $request): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->forbiddenResponse('You do not have access to the SEN register.');
        }
        $u = $request->user();

        $profiles = SenProfile::with('student.class')
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('active', true))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->whereHas('student', fn ($sq) => $sq->where('first_name', 'like', "%{$s}%")
                    ->orWhere('last_name', 'like', "%{$s}%")->orWhere('admission_number', 'like', "%{$s}%"));
            })
            ->latest()->get()
            ->when($request->boolean('mine'), fn ($c) => $c->filter(fn ($p) => in_array($u->id, $p->assigned_user_ids ?? [], true)))
            ->values();

        $staff = User::whereIn('id', $profiles->pluck('assigned_user_ids')->flatten()->filter()->unique())->get(['id', 'name'])->keyBy('id');

        return response()->json(['profiles' => $profiles->map(fn ($p) => [
            'id' => $p->id, 'student' => $this->studentRow($p->student), 'needs' => $p->needs ?? [],
            'support_level' => $p->support_level, 'summary' => $p->summary, 'strategies' => $p->strategies,
            'assigned' => collect($p->assigned_user_ids ?? [])->map(fn ($id) => $staff->get($id)?->only(['id', 'name']))->filter()->values(),
            'active' => $p->active, 'updated_at' => $p->updated_at,
        ])]);
    }

    /** POST /wellbeing/sen — create or update the profile for a student. */
    public function senSave(Request $request): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->forbiddenResponse();
        }
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'needs' => 'nullable|array', 'needs.*' => 'string|max:100',
            'support_level' => 'nullable|in:monitoring,school_support,specialist',
            'summary' => 'nullable|string|max:5000',
            'strategies' => 'nullable|string|max:5000',
            'assigned_user_ids' => 'nullable|array', 'assigned_user_ids.*' => 'integer|exists:users,id',
            'active' => 'nullable|boolean',
        ]);

        $profile = SenProfile::updateOrCreate(['student_id' => $data['student_id']], $data + [
            'created_by' => $request->user()->id,
            'support_level' => $data['support_level'] ?? 'monitoring',
        ]);

        return response()->json(['message' => 'SEN profile saved', 'profile' => $profile]);
    }

    /** GET /wellbeing/students/{student} — everything about one child the viewer may see. */
    public function student(Request $request, int $studentId): JsonResponse
    {
        $u = $request->user();
        $student = Student::with('class')->findOrFail($studentId);
        $manage = $this->canManage($request);

        // Teachers: SEN summary and strategies for children in their classes only.
        if (! $manage) {
            $classIds = $this->accessibleClassIds($u);
            if (! $this->isTeacher($u) || ($classIds !== null && ! in_array($student->class_id, $classIds, true))) {
                return $this->forbiddenResponse('You do not have access to this child\'s wellbeing record.');
            }
            $p = SenProfile::where('student_id', $studentId)->where('active', true)->first();

            return response()->json([
                'student' => $this->studentRow($student),
                'sen' => $p ? $p->only(['needs', 'support_level', 'summary', 'strategies']) : null,
                'limited' => true,
            ]);
        }

        $counsellor = $this->isCounsellor($u);
        $sessions = WellbeingSession::with('staff:id,name')->where('student_id', $studentId)->orderByDesc('session_date')->get()
            ->map(function ($s) use ($counsellor, $u) {
                $row = $s->toArray();
                // Counselling is confidential to counsellors (and whoever ran the session).
                if ($s->kind === 'counselling' && ! $counsellor && $s->staff_user_id !== $u->id) {
                    $row['focus'] = $row['notes'] = $row['next_steps'] = null;
                    $row['confidential'] = true;
                }
                return $row;
            });

        return response()->json([
            'student' => $this->studentRow($student),
            'sen' => SenProfile::where('student_id', $studentId)->first(),
            'plans' => SupportPlan::where('student_id', $studentId)->orderByDesc('created_at')->get(),
            'sessions' => $sessions,
            'referrals' => WellbeingReferral::with('referrer:id,name')->where('student_id', $studentId)->latest()->get(),
            'care_logs' => CareLog::with('logger:id,name')->where('student_id', $studentId)->orderByDesc('logged_at')->limit(50)->get(),
            'limited' => false,
        ]);
    }

    // ─── Support plans ────────────────────────────────────────────────────

    /** GET /wellbeing/plans?student_id=&status= */
    public function plans(Request $request): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->forbiddenResponse();
        }

        return response()->json(['plans' => SupportPlan::with('student.class')
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->student_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderBy('review_date')->get()
            ->map(fn ($p) => $p->toArray() + ['student' => $this->studentRow($p->student)])]);
    }

    /** POST /wellbeing/plans, PUT /wellbeing/plans/{id} */
    public function savePlan(Request $request, ?int $id = null): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->forbiddenResponse();
        }
        $data = $request->validate([
            'student_id' => ($id ? 'sometimes' : 'required') . '|exists:students,id',
            'title' => ($id ? 'sometimes' : 'required') . '|string|max:200',
            'goals' => 'nullable|array|max:20',
            'goals.*.goal' => 'required|string|max:500',
            'goals.*.target' => 'nullable|string|max:500',
            'goals.*.progress' => 'nullable|integer|min:0|max:100',
            'goals.*.status' => 'nullable|in:not_started,in_progress,achieved',
            'start_date' => 'nullable|date',
            'review_date' => 'nullable|date',
            'status' => 'nullable|in:active,completed',
        ]);

        $plan = $id ? tap(SupportPlan::findOrFail($id))->update($data)
            : SupportPlan::create($data + ['created_by' => $request->user()->id, 'status' => $data['status'] ?? 'active']);

        return response()->json(['message' => 'Support plan saved', 'plan' => $plan->fresh()]);
    }

    // ─── Sessions ─────────────────────────────────────────────────────────

    /** GET /wellbeing/sessions?kind=&student_id=&mine=1 */
    public function sessions(Request $request): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->forbiddenResponse();
        }
        $u = $request->user();
        $counsellor = $this->isCounsellor($u);

        $rows = WellbeingSession::with(['student.class', 'staff:id,name'])
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->kind))
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->student_id))
            ->when($request->boolean('mine'), fn ($q) => $q->where('staff_user_id', $u->id))
            ->orderByDesc('session_date')->limit(200)->get()
            ->map(function ($s) use ($counsellor, $u) {
                $row = $s->toArray();
                $row['student'] = $this->studentRow($s->student);
                if ($s->kind === 'counselling' && ! $counsellor && $s->staff_user_id !== $u->id) {
                    $row['focus'] = $row['notes'] = $row['next_steps'] = null;
                    $row['confidential'] = true;
                }
                return $row;
            });

        return response()->json(['sessions' => $rows]);
    }

    /** POST /wellbeing/sessions */
    public function storeSession(Request $request): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->forbiddenResponse();
        }
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'kind' => 'required|in:' . implode(',', WellbeingSession::KINDS),
            'session_date' => 'required|date',
            'duration_minutes' => 'nullable|integer|min:1|max:600',
            'focus' => 'nullable|string|max:2000',
            'notes' => 'nullable|string|max:10000',
            'next_steps' => 'nullable|string|max:2000',
        ]);
        if ($data['kind'] === 'counselling' && ! $this->isCounsellor($request->user())) {
            return $this->forbiddenResponse('Only counsellors record counselling sessions.');
        }

        $session = WellbeingSession::create($data + ['staff_user_id' => $request->user()->id]);

        return response()->json(['message' => 'Session recorded', 'session' => $session], 201);
    }

    // ─── Referrals ────────────────────────────────────────────────────────

    /** GET /wellbeing/referrals?status=&referred_to= */
    public function referrals(Request $request): JsonResponse
    {
        $u = $request->user();
        $manage = $this->canManage($request);

        $q = WellbeingReferral::with(['student.class', 'referrer:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('referred_to'), fn ($q) => $q->where('referred_to', $request->referred_to));
        if (! $manage) {
            $q->where('referred_by', $u->id); // teachers see the referrals they made
        } elseif ($this->isCounsellor($u) && ! in_array($u->role, ['school_admin', 'principal', 'vice_principal', 'admin'], true)) {
            $q->where('referred_to', 'counsellor');
        }

        return response()->json(['referrals' => $q->latest()->limit(200)->get()
            ->map(fn ($r) => $r->toArray() + ['student' => $this->studentRow($r->student)])]);
    }

    /** POST /wellbeing/referrals — staff (incl. teachers) refer a child. */
    public function storeReferral(Request $request): JsonResponse
    {
        $u = $request->user();
        if (! $this->canManage($request) && ! $this->isTeacher($u) && ! $this->canCare($request)) {
            return $this->forbiddenResponse();
        }
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'referred_to' => 'required|in:counsellor,sen',
            'reason' => 'required|string|max:5000',
            'urgency' => 'nullable|in:normal,urgent',
        ]);

        $referral = WellbeingReferral::create($data + ['referred_by' => $u->id, 'status' => 'open', 'urgency' => $data['urgency'] ?? 'normal']);

        // Let the people who handle it know.
        $roles = $data['referred_to'] === 'counsellor' ? ['counsellor'] : ['sen_facilitator', 'sen_therapist'];
        $student = Student::find($data['student_id']);
        foreach (User::whereIn('role', $roles)->pluck('id') as $uid) {
            \App\Models\Notification::create([
                'user_id' => $uid, 'type' => ($data['urgency'] ?? 'normal') === 'urgent' ? 'warning' : 'info',
                'title' => 'New referral: ' . trim(($student?->first_name ?? '') . ' ' . ($student?->last_name ?? '')),
                'message' => mb_substr($data['reason'], 0, 300),
            ]);
        }

        return response()->json(['message' => 'Referral sent', 'referral' => $referral], 201);
    }

    /** PUT /wellbeing/referrals/{id} — accept / close / assign / record outcome. */
    public function updateReferral(Request $request, int $id): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->forbiddenResponse();
        }
        $data = $request->validate([
            'status' => 'nullable|in:open,accepted,closed',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'outcome' => 'nullable|string|max:5000',
        ]);
        $referral = WellbeingReferral::findOrFail($id);
        $referral->update(array_filter($data, fn ($v) => $v !== null) + (($data['status'] ?? null) === 'accepted' && ! $referral->assigned_to ? ['assigned_to' => $request->user()->id] : []));

        return response()->json(['message' => 'Referral updated', 'referral' => $referral->fresh()]);
    }

    // ─── Care log ─────────────────────────────────────────────────────────

    /** Classes a care assistant covers (null = all, when none assigned or a manager). */
    private function careClassIds(User $u): ?array
    {
        if (in_array($u->role, ['school_admin', 'principal', 'vice_principal', 'admin'], true)) {
            return null;
        }
        $ids = DB::table('care_assignments')->where('user_id', $u->id)->pluck('class_id')->map(fn ($v) => (int) $v)->all();

        return $ids ?: null;
    }

    /** GET /wellbeing/care-logs?class_id=&date= or ?student_id= */
    public function careLogs(Request $request): JsonResponse
    {
        if (! $this->canCare($request)) {
            return $this->forbiddenResponse();
        }
        $classIds = $this->careClassIds($request->user());
        if ($request->filled('class_id') && $classIds !== null && ! in_array((int) $request->class_id, $classIds, true)) {
            return $this->forbiddenResponse('That class is not assigned to you.');
        }

        $date = $request->filled('date') ? Carbon::parse($request->date) : Carbon::today();
        $studentIds = $request->filled('student_id') ? [(int) $request->student_id]
            : Student::when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->class_id))
                ->when(! $request->filled('class_id') && $classIds !== null, fn ($q) => $q->whereIn('class_id', $classIds))
                ->pluck('id')->all();

        $logs = CareLog::with(['student:id,first_name,last_name', 'logger:id,name'])
            ->whereIn('student_id', $studentIds)
            ->when(! $request->filled('student_id'), fn ($q) => $q->whereDate('logged_at', $date))
            ->orderByDesc('logged_at')->limit(500)->get();

        return response()->json(['date' => $date->toDateString(), 'logs' => $logs]);
    }

    /** POST /wellbeing/care-logs — one entry for one or several children. */
    public function storeCareLog(Request $request): JsonResponse
    {
        if (! $this->canCare($request)) {
            return $this->forbiddenResponse();
        }
        $data = $request->validate([
            'student_ids' => 'required|array|min:1|max:100', 'student_ids.*' => 'integer|exists:students,id',
            'type' => 'required|in:' . implode(',', CareLog::TYPES),
            'details' => 'nullable|string|max:2000',
            'logged_at' => 'nullable|date',
            'shared_with_parent' => 'nullable|boolean',
        ]);

        $classIds = $this->careClassIds($request->user());
        if ($classIds !== null && Student::whereIn('id', $data['student_ids'])->whereNotIn('class_id', $classIds)->exists()) {
            return $this->forbiddenResponse('Some of those children are not in your classes.');
        }

        $at = isset($data['logged_at']) ? Carbon::parse($data['logged_at']) : now();
        foreach ($data['student_ids'] as $sid) {
            CareLog::create([
                'student_id' => $sid, 'type' => $data['type'], 'details' => $data['details'] ?? null,
                'logged_at' => $at, 'logged_by' => $request->user()->id,
                'shared_with_parent' => $data['shared_with_parent'] ?? true,
            ]);
        }

        return response()->json(['message' => 'Logged for ' . count($data['student_ids']) . ' child(ren)'], 201);
    }

    /** DELETE /wellbeing/care-logs/{id} — the person who logged it, same day. */
    public function deleteCareLog(Request $request, int $id): JsonResponse
    {
        $log = CareLog::findOrFail($id);
        if ($log->logged_by !== $request->user()->id && ! $this->canManage($request)) {
            return $this->forbiddenResponse();
        }
        $log->delete();

        return response()->json(['message' => 'Entry removed']);
    }

    /** GET /wellbeing/care-assignments — which care assistant covers which class. */
    public function careAssignments(Request $request): JsonResponse
    {
        $rows = DB::table('care_assignments')->get()->groupBy('user_id')->map(fn ($g) => $g->pluck('class_id')->map(fn ($v) => (int) $v)->values());
        $assistants = User::where('role', 'care_assistant')->orderBy('name')->get(['id', 'name', 'email'])
            ->map(fn ($u) => $u->only(['id', 'name', 'email']) + ['class_ids' => $rows->get($u->id, collect())->all()]);

        return response()->json(['assistants' => $assistants]);
    }

    /** PUT /wellbeing/care-assignments { user_id, class_ids[] } */
    public function saveCareAssignment(Request $request): JsonResponse
    {
        if (! $this->roleCan($request, 'staff.manage') && ! $this->canManage($request)) {
            return $this->forbiddenResponse();
        }
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'class_ids' => 'present|array', 'class_ids.*' => 'integer|exists:classes,id',
        ]);
        DB::transaction(function () use ($data) {
            DB::table('care_assignments')->where('user_id', $data['user_id'])->delete();
            foreach (array_unique($data['class_ids']) as $cid) {
                DB::table('care_assignments')->insert(['user_id' => $data['user_id'], 'class_id' => $cid, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return response()->json(['message' => 'Classes assigned']);
    }

    // ─── Parents ──────────────────────────────────────────────────────────

    /** GET /wellbeing/my-children/care-logs?date= — shared entries for a parent's own children. */
    public function myChildrenCareLogs(Request $request): JsonResponse
    {
        $guardian = Guardian::where('user_id', $request->user()->id)->first();
        if (! $guardian) {
            return response()->json(['logs' => []]);
        }
        $date = $request->filled('date') ? Carbon::parse($request->date) : Carbon::today();

        $logs = CareLog::with('student:id,first_name,last_name')
            ->whereIn('student_id', $guardian->students()->pluck('students.id'))
            ->where('shared_with_parent', true)
            ->whereDate('logged_at', $date)
            ->orderBy('logged_at')->get(['id', 'student_id', 'type', 'details', 'logged_at']);

        return response()->json(['date' => $date->toDateString(), 'logs' => $logs]);
    }
}
