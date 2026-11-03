<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\ClockNotify;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Staff attendance for every member of staff (teachers are kept on their Teacher record,
 * everyone else — care assistants, nurse, admins… — on their user account), the staff
 * attendance report, and the school's clock-in QR code.
 */
class StaffAttendanceController extends Controller
{
    private const NOT_STAFF = ['guardian', 'parent', 'student', 'super_admin'];

    /** Everyone who works at the school, with what is needed to find their records. */
    private function staffList(): Collection
    {
        return DB::table('users')
            ->leftJoin('teachers', function ($j) { $j->on('teachers.user_id', '=', 'users.id')->whereNull('teachers.deleted_at'); })
            ->leftJoin('staff', function ($j) { $j->on('staff.user_id', '=', 'users.id')->whereNull('staff.deleted_at'); })
            ->leftJoin('departments', 'departments.id', '=', 'teachers.department_id')
            ->whereNull('users.deleted_at')
            ->whereNotIn('users.role', self::NOT_STAFF)
            ->where(fn ($q) => $q->whereNull('users.status')->orWhereNotIn('users.status', ['inactive', 'suspended']))
            ->orderBy('users.name')
            ->get([
                'users.id as user_id', 'users.name', 'users.role',
                'teachers.id as teacher_id',
                DB::raw('COALESCE(departments.name, staff.department) as department'),
                DB::raw('COALESCE(teachers.employee_id, staff.employee_id) as employee_id'),
            ])
            ->unique('user_id')->values();
    }

    /** Attendance rows for these staff between two dates, keyed "userId|Y-m-d". */
    private function recordsFor(Collection $staff, string $from, string $to): Collection
    {
        $byTeacher = $staff->whereNotNull('teacher_id')->pluck('user_id', 'teacher_id');
        $userIds = $staff->whereNull('teacher_id')->pluck('user_id');

        return Attendance::query()
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->where(function ($q) use ($byTeacher, $userIds) {
                $q->where(fn ($w) => $w->where('attendanceable_type', Teacher::class)->whereIn('attendanceable_id', $byTeacher->keys()))
                  ->orWhere(fn ($w) => $w->where('attendanceable_type', User::class)->whereIn('attendanceable_id', $userIds));
            })
            ->get()
            ->keyBy(function (Attendance $a) use ($byTeacher) {
                $uid = $a->attendanceable_type === Teacher::class ? $byTeacher[$a->attendanceable_id] ?? 0 : $a->attendanceable_id;

                return $uid . '|' . substr((string) $a->getRawOriginal('date'), 0, 10);
            });
    }

    /** "HH:MM" from the time column (stored as a bare time). */
    private static function hm($raw): ?string
    {
        if (! $raw) {
            return null;
        }
        $raw = (string) $raw;

        return preg_match('/(\d{2}:\d{2})/', $raw, $m) ? $m[1] : null;
    }

    /** The school's local time (the server runs on UTC; schools default to Lagos time). */
    public static function local(): Carbon
    {
        $tz = DB::table('settings')->where('key', 'timezone')->value('value') ?: 'Africa/Lagos';

        try {
            return Carbon::now($tz);
        } catch (\Throwable) {
            return Carbon::now('Africa/Lagos');
        }
    }

    private static function resumeTime(): string
    {
        $v = DB::table('settings')->where('key', 'staff_resume_time')->value('value');

        return preg_match('/^\d{2}:\d{2}$/', (string) $v) ? $v : '08:00';
    }

    private function present(?Attendance $a): ?array
    {
        if (! $a) {
            return null;
        }
        $in = self::hm($a->getRawOriginal('check_in_time'));
        $out = self::hm($a->getRawOriginal('check_out_time'));
        $late = $in && $a->status !== 'absent' && $in > self::resumeTime();

        return [
            'id' => $a->id,
            'status' => $a->status === 'absent' ? 'absent' : ($late ? 'late' : $a->status),
            'check_in' => $in,
            'check_out' => $out,
            'hours' => $a->total_hours !== null ? (float) $a->total_hours : self::hoursBetween($in, $out),
            'late_minutes' => $late ? self::minutes($in) - self::minutes(self::resumeTime()) : 0,
            'location' => $a->location,
            'notes' => $a->notes,
            'self' => (int) $a->marked_by === (int) ($a->attendanceable_type === User::class ? $a->attendanceable_id : Teacher::where('id', $a->attendanceable_id)->value('user_id')),
        ];
    }

    private static function minutes(string $hm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hm));

        return $h * 60 + $m;
    }

    private static function hoursBetween(?string $in, ?string $out): ?float
    {
        return $in && $out && $out > $in ? round((self::minutes($out) - self::minutes($in)) / 60, 2) : null;
    }

    /** GET /attendance/staff?date=Y-m-d — every member of staff and their record that day. */
    public function index(Request $request): JsonResponse
    {
        $date = Carbon::parse($request->input('date', self::local()->toDateString()))->toDateString();
        $staff = $this->staffList();
        $records = $this->recordsFor($staff, $date, $date);

        $rows = $staff->map(fn ($s) => [
            'user_id' => $s->user_id,
            'name' => $s->name,
            'role' => $s->role,
            'department' => $s->department,
            'employee_id' => $s->employee_id,
            'is_teacher' => (bool) $s->teacher_id,
            'record' => $this->present($records->get($s->user_id . '|' . $date)),
        ])->values();

        $count = fn (string $st) => $rows->filter(fn ($r) => ($r['record']['status'] ?? null) === $st)->count();

        return response()->json([
            'date' => $date,
            'resume_time' => self::resumeTime(),
            'staff' => $rows,
            'summary' => [
                'total' => $rows->count(),
                'present' => $count('present') + $count('late'),
                'late' => $count('late'),
                'absent' => $count('absent'),
                'not_marked' => $rows->whereNull('record')->count(),
            ],
            'can_mark' => StaffDutyController::canMarkStaff($request->user() ?: auth()->user(), $date),
        ]);
    }

    /**
     * POST /attendance/staff/mark {user_id, date, action: in|out|absent|clear, time?, notes?}
     * Leadership or the teacher on duty can record anyone; anyone can record themselves.
     */
    public function mark(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer',
            'date' => 'required|date',
            'action' => 'required|in:in,out,absent,clear',
            'time' => ['nullable', 'regex:/^\d{1,2}:\d{2}$/'],
            'notes' => 'nullable|string|max:500',
        ]);
        $me = $request->user() ?: auth()->user();
        $date = Carbon::parse($data['date'])->toDateString();

        if ((int) $data['user_id'] !== (int) $me->id && ! StaffDutyController::canMarkStaff($me, $date)) {
            return $this->forbiddenResponse('Only the teacher on duty this week (or the school leadership) can clock staff in and out.');
        }
        $person = User::find($data['user_id']);
        if (! $person || in_array($person->role, self::NOT_STAFF, true)) {
            return response()->json(['error' => 'That person is not a member of staff.'], 422);
        }

        $time = $data['time'] ?? self::local()->format('H:i');
        $time = str_pad($time, 5, '0', STR_PAD_LEFT);

        return $this->apply($person, $date, $data['action'], $time, $data['notes'] ?? null, $me, $request);
    }

    /** Create / update one staff member's record for a day; shared by mark and the QR scan. */
    private function apply(User $person, string $date, string $action, string $time, ?string $notes, User $by, Request $request): JsonResponse
    {
        $teacherId = Teacher::where('user_id', $person->id)->value('id');
        [$sid, $stype] = $teacherId ? [$teacherId, Teacher::class] : [$person->id, User::class];

        $a = Attendance::where('attendanceable_id', $sid)->where('attendanceable_type', $stype)->whereDate('date', $date)->first();

        if ($action === 'clear') {
            $a?->delete();

            return response()->json(['message' => 'Record removed', 'record' => null]);
        }

        $a ??= new Attendance([
            'school_id' => \App\Models\School::value('id'),
            'attendanceable_id' => $sid,
            'attendanceable_type' => $stype,
            'date' => $date,
        ]);

        $in = self::hm($a->exists ? $a->getRawOriginal('check_in_time') : null);
        $resume = self::resumeTime();
        // Raw attributes: the time columns hold a bare time, which the model's datetime cast would mangle.
        $attrs = $a->getAttributes();

        if ($action === 'in') {
            $isLate = $time > $resume;
            $attrs = array_merge($attrs, [
                'status' => 'present',
                'check_in_time' => $time . ':00',
                'is_late' => $isLate ? 1 : 0,
                'late_minutes' => $isLate ? self::minutes($time) - self::minutes($resume) : 0,
                'is_absent' => 0,
            ]);
            if (! empty($attrs['check_out_time']) && self::hm($attrs['check_out_time']) <= $time) {
                $attrs['check_out_time'] = null;
                $attrs['total_hours'] = null;
            }
        } elseif ($action === 'out') {
            if (! $in) {
                return response()->json(['error' => 'Clock in first — there is no clock-in for that day.'], 422);
            }
            if ($time <= $in) {
                return response()->json(['error' => "Clock-out must be after the clock-in time ({$in})."], 422);
            }
            $attrs['check_out_time'] = $time . ':00';
            $attrs['total_hours'] = self::hoursBetween($in, $time);
        } else {
            $attrs = array_merge($attrs, [
                'status' => 'absent', 'is_absent' => 1, 'is_late' => 0, 'late_minutes' => 0,
                'check_in_time' => null, 'check_out_time' => null, 'total_hours' => null,
            ]);
        }
        if ($notes !== null) {
            $attrs['notes'] = $notes;
        }
        $attrs['marked_by'] = $by->id;
        $attrs['device_info'] = Str::limit((string) $request->header('User-Agent'), 250, '');
        $attrs['ip_address'] = $request->ip();
        $a->setRawAttributes($attrs);
        $a->save();

        if (in_array($action, ['in', 'out'], true) && $date === self::local()->toDateString()) {
            ClockNotify::send($person, $a->fresh(), $action === 'in' ? 'clock_in' : 'clock_out');
        }

        return response()->json([
            'message' => match ($action) { 'in' => "Clocked in at {$time}", 'out' => "Clocked out at {$time}", default => 'Marked absent' },
            'record' => $this->present($a->fresh()),
        ]);
    }

    /**
     * GET /attendance/staff/report?from=&to= — per person: days present, late, absent,
     * not recorded, hours and average arrival, over the school days (Mon–Fri) in the range.
     */
    public function report(Request $request): JsonResponse
    {
        $from = Carbon::parse($request->input('from', self::local()->startOfMonth()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->input('to', self::local()->toDateString()))->startOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 400) {
            return response()->json(['error' => 'Choose a range of a year or less.'], 422);
        }

        $days = [];
        for ($d = $from->copy(); $d->lte($to) && $d->toDateString() <= self::local()->toDateString(); $d->addDay()) {
            if (! $d->isWeekend()) {
                $days[] = $d->toDateString();
            }
        }

        $staff = $this->staffList();
        $records = $this->recordsFor($staff, $from->toDateString(), $to->toDateString());

        $rows = $staff->map(function ($s) use ($days, $records) {
            $present = $late = $absent = 0;
            $hours = 0.0;
            $ins = [];
            $daily = [];
            foreach ($days as $day) {
                $r = $this->present($records->get($s->user_id . '|' . $day));
                $daily[$day] = $r ? ($r['status'] === 'absent' ? 'A' : ($r['status'] === 'late' ? 'L' : 'P')) : '';
                if (! $r) {
                    continue;
                }
                if ($r['status'] === 'absent') {
                    $absent++;
                    continue;
                }
                $present++;
                if ($r['status'] === 'late') {
                    $late++;
                }
                $hours += (float) ($r['hours'] ?? 0);
                if ($r['check_in']) {
                    $ins[] = self::minutes($r['check_in']);
                }
            }
            $avg = $ins ? (int) round(array_sum($ins) / count($ins)) : null;

            return [
                'user_id' => $s->user_id,
                'name' => $s->name,
                'role' => $s->role,
                'department' => $s->department,
                'employee_id' => $s->employee_id,
                'present' => $present,
                'late' => $late,
                'absent' => $absent,
                'not_recorded' => count($days) - $present - $absent,
                'hours' => round($hours, 1),
                'average_arrival' => $avg !== null ? sprintf('%02d:%02d', intdiv($avg, 60), $avg % 60) : null,
                'rate' => count($days) ? (int) round($present / count($days) * 100) : 0,
                'daily' => $daily,
            ];
        })->values();

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'school_days' => $days,
            'resume_time' => self::resumeTime(),
            'staff' => $rows,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Clock-in QR code                                                  */
    /* ------------------------------------------------------------------ */

    private static function qrKey(bool $fresh = false): string
    {
        $key = DB::table('settings')->where('key', 'clock_qr_key')->value('value');
        if ($fresh || ! $key) {
            $key = Str::random(32);
            DB::table('settings')->updateOrInsert(['key' => 'clock_qr_key'], ['value' => $key, 'updated_at' => now(), 'created_at' => now()]);
        }

        return $key;
    }

    /** GET /attendance/qr — the school's code (leadership only). */
    public function qr(Request $request): JsonResponse
    {
        if (! StaffDutyController::canMarkStaff($request->user() ?: auth()->user())) {
            return $this->forbiddenResponse('Only the school leadership can show the clock-in QR code.');
        }

        return response()->json([
            'key' => self::qrKey(),
            'students' => (string) (DB::table('settings')->where('key', 'qr_clock_students')->value('value') ?? '1') !== '0',
            'resume_time' => self::resumeTime(),
        ]);
    }

    /** POST /attendance/qr {rotate?, students?, resume_time?} — new code (old prints stop working) or options. */
    public function qrUpdate(Request $request): JsonResponse
    {
        if (! StaffDutyController::canMarkStaff($request->user() ?: auth()->user())) {
            return $this->forbiddenResponse('Only the school leadership can change the clock-in QR code.');
        }
        $data = $request->validate([
            'rotate' => 'boolean',
            'students' => 'boolean',
            'resume_time' => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
        ]);
        foreach (['students' => 'qr_clock_students', 'resume_time' => 'staff_resume_time'] as $in => $key) {
            if (array_key_exists($in, $data) && $data[$in] !== null) {
                $v = is_bool($data[$in]) ? ($data[$in] ? '1' : '0') : $data[$in];
                DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $v, 'updated_at' => now(), 'created_at' => now()]);
            }
        }
        if (! empty($data['rotate'])) {
            self::qrKey(true);
        }

        return $this->qr($request);
    }

    /**
     * POST /attendance/qr-clock {key} — someone scanned the school's code on their phone:
     * staff are clocked in (or out, on the second scan of the day); students the same,
     * with their parents told by SMS / email.
     */
    public function qrClock(Request $request): JsonResponse
    {
        $request->validate(['key' => 'required|string|max:100']);
        $me = $request->user() ?: auth()->user();

        if (! hash_equals(self::qrKey(), (string) $request->input('key'))) {
            return response()->json(['error' => 'This QR code is no longer valid — ask the school office for the current one.'], 422);
        }

        $today = self::local()->toDateString();
        $now = self::local()->format('H:i');

        if ($me->role === 'student') {
            if ((string) (DB::table('settings')->where('key', 'qr_clock_students')->value('value') ?? '1') === '0') {
                return $this->forbiddenResponse('Students do not clock in with the QR code at this school.');
            }

            return $this->studentScan($me, $today, $now, $request);
        }
        if (in_array($me->role, self::NOT_STAFF, true)) {
            return $this->forbiddenResponse('Only staff and students clock in with this code.');
        }
        if (! \App\Support\RoleCapabilityService::userCan($me, 'attendance.self_clock_in', \App\Models\School::value('id'))) {
            return $this->forbiddenResponse("Self clock-in isn't switched on for your account — ask the school admin (Role Access).");
        }

        $teacherId = Teacher::where('user_id', $me->id)->value('id');
        $a = Attendance::where('attendanceable_id', $teacherId ?: $me->id)
            ->where('attendanceable_type', $teacherId ? Teacher::class : User::class)
            ->whereDate('date', $today)->first();
        $in = $a ? self::hm($a->getRawOriginal('check_in_time')) : null;
        $out = $a ? self::hm($a->getRawOriginal('check_out_time')) : null;

        if ($in && $out) {
            return response()->json(['message' => "You already clocked in at {$in} and out at {$out} today.", 'action' => 'done', 'name' => $me->name, 'record' => $this->present($a)]);
        }
        if ($in && self::minutes($now) - self::minutes($in) < 2) {
            return response()->json(['message' => "You're clocked in (since {$in}). Scan again when you leave to clock out.", 'action' => 'in', 'name' => $me->name, 'record' => $this->present($a)]);
        }

        $res = $this->apply($me, $today, $in ? 'out' : 'in', $now, null, $me, $request);
        $body = $res->getData(true);
        $body['action'] = $in ? 'out' : 'in';
        $body['name'] = $me->name;

        return response()->json($body, $res->getStatusCode());
    }

    /** The dashboard "Clock In / Clock Out" button — same rules and notifications as the QR scan. */
    public function selfClock(Request $request, string $action): JsonResponse
    {
        $me = $request->user() ?: auth()->user();
        if (in_array($me->role, self::NOT_STAFF, true)) {
            return $this->forbiddenResponse('Only staff clock in here.');
        }
        $today = self::local()->toDateString();
        $teacherId = Teacher::where('user_id', $me->id)->value('id');
        $a = Attendance::where('attendanceable_id', $teacherId ?: $me->id)
            ->where('attendanceable_type', $teacherId ? Teacher::class : User::class)
            ->whereDate('date', $today)->first();
        $in = $a ? self::hm($a->getRawOriginal('check_in_time')) : null;
        $out = $a ? self::hm($a->getRawOriginal('check_out_time')) : null;

        if ($action === 'in' && $in) {
            return response()->json(['error' => "Already clocked in today (at {$in})"], 400);
        }
        if ($action === 'out' && ! $in) {
            return response()->json(['error' => 'Must clock in before clocking out'], 400);
        }
        if ($action === 'out' && $out) {
            return response()->json(['error' => "Already clocked out today (at {$out})"], 400);
        }

        return $this->apply($me, $today, $action, self::local()->format('H:i'), $request->input('notes'), $me, $request);
    }

    /** Today's own record for the dashboard card, times as the school's local "HH:MM". */
    public function myToday(Request $request): JsonResponse
    {
        $me = $request->user() ?: auth()->user();
        $teacherId = Teacher::where('user_id', $me->id)->value('id');
        $a = Attendance::where('attendanceable_id', $teacherId ?: $me->id)
            ->where('attendanceable_type', $teacherId ? Teacher::class : User::class)
            ->whereDate('date', self::local()->toDateString())->first();
        $r = $this->present($a);

        return response()->json([
            'clocked_in' => (bool) ($r['check_in'] ?? null),
            'clocked_out' => (bool) ($r['check_out'] ?? null),
            'check_in' => $r['check_in'] ?? null,
            'check_out' => $r['check_out'] ?? null,
            'attendance' => $r,
        ]);
    }

    private function studentScan(User $me, string $today, string $now, Request $request): JsonResponse
    {
        $student = Student::where('user_id', $me->id)->first();
        if (! $student) {
            return response()->json(['error' => 'No student record is linked to this account.'], 422);
        }

        $a = Attendance::where('attendanceable_id', $student->id)->where('attendanceable_type', Student::class)->whereDate('date', $today)->first();
        $in = $a ? self::hm($a->getRawOriginal('check_in_time')) : null;
        $out = $a ? self::hm($a->getRawOriginal('check_out_time')) : null;
        $name = trim($student->first_name . ' ' . $student->last_name) ?: $me->name;

        if ($in && $out) {
            return response()->json(['message' => "You already arrived at {$in} and left at {$out} today.", 'action' => 'done', 'name' => $name]);
        }
        if ($in && self::minutes($now) - self::minutes($in) < 2) {
            return response()->json(['message' => "You're signed in (since {$in}). Scan again when you go home.", 'action' => 'in', 'name' => $name]);
        }

        $a ??= new Attendance([
            'school_id' => \App\Models\School::value('id'),
            'attendanceable_id' => $student->id,
            'attendanceable_type' => Student::class,
            'date' => $today,
        ]);
        $attrs = $a->getAttributes();
        $attrs['marked_by'] = $me->id;
        $attrs['ip_address'] = $request->ip();
        $attrs['device_info'] = Str::limit((string) $request->header('User-Agent'), 250, '');
        if ($in) {
            $attrs['check_out_time'] = $now . ':00';
        } else {
            $attrs['status'] = 'present';
            $attrs['check_in_time'] = $now . ':00';
            $attrs['is_absent'] = 0;
        }
        $a->setRawAttributes($attrs);
        $a->save();

        ClockNotify::sendStudent($student, $in ? 'clock_out' : 'clock_in', $now);

        return response()->json([
            'message' => $in ? "Signed out at {$now}. See you tomorrow!" : "Signed in at {$now}. Have a good day!",
            'action' => $in ? 'out' : 'in',
            'name' => $name,
        ]);
    }
}
