<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly duty roster. Leadership puts teachers on duty for a week; only the teacher(s) on duty
 * that week (and leadership) can clock other staff in and out. Everyone can still clock themselves.
 */
class StaffDutyController extends Controller
{
    public const LEADERSHIP = ['super_admin', 'school_admin', 'principal', 'vice_principal', 'admin'];

    public static function weekStart($date = null): string
    {
        return Carbon::parse($date ?? now())->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    /** Can this user record attendance for other staff on this date? */
    public static function canMarkStaff($user, $date = null): bool
    {
        if (! $user) {
            return false;
        }
        if (in_array($user->role, self::LEADERSHIP, true) || \App\Support\RoleCapabilityService::userCan($user, 'staff_attendance.manage', \App\Models\School::first()?->id)) {
            return true;
        }

        return Schema::hasTable('staff_duties')
            && DB::table('staff_duties')->where('user_id', $user->id)->where('week_start', self::weekStart($date))->exists();
    }

    /** GET /staff-duties?week=YYYY-MM-DD — who is on duty that week (and the next few). */
    public function index(Request $request): JsonResponse
    {
        $u = $request->user() ?: auth()->user();
        $start = self::weekStart($request->input('week'));
        $rows = DB::table('staff_duties')->leftJoin('users', 'users.id', '=', 'staff_duties.user_id')
            ->where('week_start', '>=', $start)->where('week_start', '<=', Carbon::parse($start)->addWeeks(7)->toDateString())
            ->orderBy('week_start')->orderBy('users.name')
            ->get(['staff_duties.id', 'staff_duties.user_id', 'staff_duties.week_start', 'staff_duties.duty', 'staff_duties.notes', 'users.name']);

        return response()->json([
            'week_start' => $start,
            'duties' => $rows,
            'can_manage' => in_array($u?->role, self::LEADERSHIP, true) || $this->roleCan($request, 'duty_roster.manage'),
            'can_mark_staff' => self::canMarkStaff($u, $request->input('week')),
            'on_duty' => $rows->where('week_start', $start)->values(),
        ]);
    }

    /** POST /staff-duties {user_ids[], week_start, duty?, notes?} */
    public function store(Request $request): JsonResponse
    {
        $u = $request->user() ?: auth()->user();
        if (! in_array($u?->role, self::LEADERSHIP, true) && ! $this->roleCan($request, 'duty_roster.manage')) {
            return $this->forbiddenResponse('Only the school leadership sets the duty roster.');
        }
        $v = $request->validate([
            'user_ids' => 'required|array|min:1', 'user_ids.*' => 'integer|exists:users,id',
            'week_start' => 'required|date', 'duty' => 'nullable|string|max:100', 'notes' => 'nullable|string|max:255',
        ]);
        $week = self::weekStart($v['week_start']);
        foreach ($v['user_ids'] as $id) {
            DB::table('staff_duties')->updateOrInsert(['user_id' => $id, 'week_start' => $week], [
                'duty' => ($v['duty'] ?? null) ?: 'Teacher on duty', 'notes' => $v['notes'] ?? null, 'assigned_by' => $u->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            \App\Models\Notification::create(['user_id' => $id, 'type' => 'info', 'title' => 'You are on duty',
                'message' => (($v['duty'] ?? null) ?: 'Teacher on duty') . ' for the week of ' . Carbon::parse($week)->format('j M Y') . '. You can clock staff in and out that week.']);
        }

        return response()->json(['message' => 'Duty roster updated'], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $u = $request->user() ?: auth()->user();
        if (! in_array($u?->role, self::LEADERSHIP, true) && ! $this->roleCan($request, 'duty_roster.manage')) {
            return $this->forbiddenResponse();
        }
        DB::table('staff_duties')->where('id', $id)->delete();

        return response()->json(['message' => 'Removed']);
    }
}
