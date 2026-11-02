<?php

namespace App\Http\Controllers;

use App\Models\Wellbeing\SenProfile;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEN weekly reports. Every child on the SEN register (active) can get a weekly report from
 * any SEN therapist or facilitator — not only the ones on the child's caseload — and from
 * anyone who manages wellbeing (SEN lead, head, admin).
 */
class SenWeeklyReportController extends Controller
{
    private const WRITERS = ['sen_therapist', 'sen_facilitator'];

    private function allowed(Request $r): bool
    {
        $u = $r->user();
        if (! $u) {
            return false;
        }
        $roles = \App\Support\UserEffectiveRoles::forUser($u);

        return (bool) array_intersect($roles, self::WRITERS) || $this->roleCan($r, 'wellbeing.manage');
    }

    private function monday(?string $date): string
    {
        return Carbon::parse($date ?: now())->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    /** GET /wellbeing/sen-weekly?week=YYYY-MM-DD — every SEN child with this week's reports. */
    public function index(Request $request): JsonResponse
    {
        if (! $this->allowed($request)) {
            return $this->forbiddenResponse('SEN weekly reports are for SEN therapists, facilitators and the SEN lead.');
        }
        $week = $this->monday($request->query('week'));
        $reports = DB::table('sen_weekly_reports')->where('week_start', $week)->get()->groupBy('student_id');
        $authors = DB::table('users')->whereIn('id', $reports->flatten()->pluck('author_id')->unique())->pluck('name', 'id');
        $me = $request->user()->id;

        $children = SenProfile::with('student.class')->where('active', true)->get()->filter(fn ($p) => $p->student)->map(function ($p) use ($reports, $authors, $me) {
            $rows = $reports->get($p->student_id, collect());

            return [
                'student_id' => $p->student_id,
                'name' => trim(($p->student->first_name ?? '') . ' ' . ($p->student->last_name ?? '')),
                'class' => $p->student->class?->name,
                'needs' => $p->needs ?? [],
                'support_level' => $p->support_level,
                'mine' => in_array($me, $p->assigned_user_ids ?? [], true),
                'reports' => $rows->map(fn ($r) => ((array) $r) + ['author' => $authors[$r->author_id] ?? 'Staff'])->values(),
                'done_by_me' => $rows->contains(fn ($r) => (int) $r->author_id === (int) $me),
            ];
        })->sortBy([['mine', 'desc'], ['name', 'asc']])->values();

        return response()->json(['week' => $week, 'children' => $children]);
    }

    /** GET /wellbeing/sen-weekly/student/{id} — a child's report history. */
    public function history(Request $request, int $studentId): JsonResponse
    {
        if (! $this->allowed($request)) {
            return $this->forbiddenResponse('Not allowed.');
        }
        $rows = DB::table('sen_weekly_reports')->where('student_id', $studentId)->orderByDesc('week_start')->limit(60)->get();
        $authors = DB::table('users')->whereIn('id', $rows->pluck('author_id')->unique())->pluck('name', 'id');

        return response()->json(['reports' => $rows->map(fn ($r) => ((array) $r) + ['author' => $authors[$r->author_id] ?? 'Staff'])]);
    }

    /** POST /wellbeing/sen-weekly — save (create or update) my report for a child and week. */
    public function save(Request $request): JsonResponse
    {
        if (! $this->allowed($request)) {
            return $this->forbiddenResponse('SEN weekly reports are for SEN therapists, facilitators and the SEN lead.');
        }
        $v = $request->validate([
            'student_id' => 'required|integer',
            'week' => 'nullable|date',
            'goals' => 'nullable|string|max:5000', 'progress' => 'nullable|string|max:5000', 'strategies' => 'nullable|string|max:5000',
            'mood_behaviour' => 'nullable|string|max:5000', 'next_steps' => 'nullable|string|max:5000',
            'rating' => 'nullable|integer|min:1|max:5', 'sessions' => 'nullable|integer|min:0|max:50',
        ]);
        if (! SenProfile::where('student_id', $v['student_id'])->where('active', true)->exists()) {
            return response()->json(['error' => 'This child is not on the SEN register.'], 422);
        }
        $u = $request->user();
        $week = $this->monday($v['week'] ?? null);
        $fields = collect($v)->only(['goals', 'progress', 'strategies', 'mood_behaviour', 'next_steps', 'rating', 'sessions'])->all();
        DB::table('sen_weekly_reports')->updateOrInsert(
            ['student_id' => $v['student_id'], 'week_start' => $week, 'author_id' => $u->id],
            $fields + ['author_role' => $u->role, 'updated_at' => now(), 'created_at' => now()],
        );

        return response()->json(['message' => 'Weekly report saved', 'week' => $week]);
    }
}
