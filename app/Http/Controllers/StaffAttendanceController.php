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
                \Illuminate\Support\Facades\Schema::hasColumn('users', 'attendance_shift') ? 'users.attendance_shift as shift' : DB::raw('NULL as shift'),
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

    /** Read once per request (and per school): a month's report looks these up for every record. */
    private static array $memo = [];
    private static ?string $memoFor = null;

    private static function memo(string $key, callable $load): mixed
    {
        $scope = spl_object_id(request()) . ':' . (function_exists('tenant') && tenant() ? tenant('id') : '');
        if (self::$memoFor !== $scope) {
            self::$memo = [];
            self::$memoFor = $scope;
        }
        if (! array_key_exists($key, self::$memo)) {
            self::$memo[$key] = $load();
        }

        return self::$memo[$key];
    }

    private static function forgetMemo(): void
    {
        self::$memo = [];
    }

    private static function resumeTime(): string
    {
        return self::memo('resume', fn () => self::loadResumeTime());
    }

    private static function loadResumeTime(): string
    {
        $v = DB::table('settings')->where('key', 'staff_resume_time')->value('value');

        return preg_match('/^\d{2}:\d{2}$/', (string) $v) ? $v : '08:00';
    }

    /**
     * Shifts for schools that resume at different times (e.g. Morning 07:00, Afternoon 13:00):
     * settings key attendance_shifts = [{"name","start"}]. Empty: one resumption time for everyone.
     *
     * @return array<int, array{name: string, start: string}>
     */
    public static function shifts(): array
    {
        return self::memo('shifts', fn () => self::loadShifts());
    }

    private static function loadShifts(): array
    {
        $raw = json_decode((string) DB::table('settings')->where('key', 'attendance_shifts')->value('value'), true);

        return collect(is_array($raw) ? $raw : [])
            ->filter(fn ($x) => is_array($x) && trim((string) ($x['name'] ?? '')) !== '' && preg_match('/^\d{2}:\d{2}$/', (string) ($x['start'] ?? '')))
            ->map(fn ($x) => ['name' => trim((string) $x['name']), 'start' => (string) $x['start']])
            ->sortBy('start')->values()->all();
    }

    /**
     * When this person should have arrived: their shift's start (staff put on a shift), else the
     * shift that starts nearest the time they clocked in, else the school's resumption time.
     */
    public static function resumeFor(?int $userId, ?string $clockIn = null): string
    {
        $shifts = self::shifts();
        if (! $shifts) {
            return self::resumeTime();
        }
        $assigned = self::memo('user_shifts', fn () => \Illuminate\Support\Facades\Schema::hasColumn('users', 'attendance_shift')
            ? DB::table('users')->whereNotNull('attendance_shift')->pluck('attendance_shift', 'id')->all() : []);
        $mine = $userId ? ($assigned[$userId] ?? null) : null;
        if ($mine && ($s = collect($shifts)->firstWhere('name', $mine))) {
            return $s['start'];
        }
        if ($clockIn) {
            return collect($shifts)->sortBy(fn ($s) => abs(self::minutes($s['start']) - self::minutes($clockIn)))->first()['start'];
        }

        return $shifts[0]['start'];
    }

    private function present(?Attendance $a): ?array
    {
        if (! $a) {
            return null;
        }
        $in = self::hm($a->getRawOriginal('check_in_time'));
        $out = self::hm($a->getRawOriginal('check_out_time'));
        $resume = self::resumeTime();
        if (self::shifts()) {
            $teacherUsers = self::memo('teacher_users', fn () => Teacher::pluck('user_id', 'id')->all());
            $uid = $a->attendanceable_type === User::class ? (int) $a->attendanceable_id
                : (int) ($teacherUsers[$a->attendanceable_id] ?? 0);
            $resume = self::resumeFor($uid ?: null, $in);
        }
        $late = $in && $a->status !== 'absent' && $in > $resume;

        return [
            'id' => $a->id,
            'status' => $a->status === 'absent' ? 'absent' : ($late ? 'late' : $a->status),
            'check_in' => $in,
            'check_out' => $out,
            'hours' => $a->total_hours !== null ? (float) $a->total_hours : self::hoursBetween($in, $out),
            'late_minutes' => $late ? self::minutes($in) - self::minutes($resume) : 0,
            'resume_time' => $resume,
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
        // Everyone's attendance is for the leadership (and the teacher on duty): other staff see their own.
        $me = $request->user() ?: auth()->user();
        if (! StaffDutyController::canMarkStaff($me, $date)) {
            $staff = $staff->where('user_id', $me?->id)->values();
        }
        $records = $this->recordsFor($staff, $date, $date);

        $rows = $staff->map(fn ($s) => [
            'user_id' => $s->user_id,
            'name' => $s->name,
            'role' => $s->role,
            'department' => $s->department,
            'employee_id' => $s->employee_id,
            'is_teacher' => (bool) $s->teacher_id,
            'shift' => $s->shift,
            'record' => $this->present($records->get($s->user_id . '|' . $date)),
        ])->values();

        $count = fn (string $st) => $rows->filter(fn ($r) => ($r['record']['status'] ?? null) === $st)->count();

        return response()->json([
            'date' => $date,
            'resume_time' => self::resumeTime(),
            'shifts' => self::shifts(),
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
        $resume = self::resumeFor($person->id, $action === 'in' ? $time : null);
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
        $me = $request->user() ?: auth()->user();
        if (! StaffDutyController::canMarkStaff($me)) {
            $staff = $staff->where('user_id', $me?->id)->values();
        }
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
    /** POST /attendance/staff/shift {user_ids: [], shift: name|null} — put staff on a shift (null: nearest to their clock-in). */
    public function assignShift(Request $request): JsonResponse
    {
        if (! StaffDutyController::canMarkStaff($request->user() ?: auth()->user())) {
            return $this->forbiddenResponse('Only the school leadership can set shifts.');
        }
        $data = $request->validate(['user_ids' => 'required|array|min:1', 'user_ids.*' => 'integer', 'shift' => 'nullable|string|max:40']);
        if ($data['shift'] !== null && ! collect(self::shifts())->firstWhere('name', $data['shift'])) {
            return response()->json(['error' => 'Add that shift under Clock-in settings first.'], 422);
        }
        if (! \Illuminate\Support\Facades\Schema::hasColumn('users', 'attendance_shift')) {
            return response()->json(['error' => 'Shifts are being set up — try again in a minute.'], 503);
        }
        $n = DB::table('users')->whereIn('id', $data['user_ids'])->update(['attendance_shift' => $data['shift'], 'updated_at' => now()]);
        self::forgetMemo();

        return response()->json(['message' => $data['shift'] ? "{$n} on the {$data['shift']} shift" : "{$n} follow the shift nearest their clock-in", 'updated' => $n]);
    }

    public function qr(Request $request): JsonResponse
    {
        if (! StaffDutyController::canMarkStaff($request->user() ?: auth()->user())) {
            return $this->forbiddenResponse('Only the school leadership can show the clock-in QR code.');
        }

        return response()->json([
            'key' => self::qrKey(),
            'students' => (string) (DB::table('settings')->where('key', 'qr_clock_students')->value('value') ?? '1') !== '0',
            'resume_time' => self::resumeTime(),
            'shifts' => self::shifts(),
            'qr_only' => self::qrOnly(),
        ]);
    }

    /** Clock in only with the QR code + PIN, a card or a fingerprint device: no in-app button (default). */
    public static function qrOnly(): bool
    {
        return (string) (DB::table('settings')->where('key', 'clock_qr_only')->value('value') ?? '1') !== '0';
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
            'qr_only' => 'boolean',
            'shifts' => 'nullable|array|max:6',
            'shifts.*.name' => 'required|string|max:40',
            'shifts.*.start' => ['required', 'regex:/^\d{2}:\d{2}$/'],
        ]);
        self::forgetMemo();
        if (array_key_exists('shifts', $data)) {
            $shifts = collect($data['shifts'] ?? [])->map(fn ($x) => ['name' => trim($x['name']), 'start' => $x['start']])
                ->unique('name')->sortBy('start')->values()->all();
            DB::table('settings')->updateOrInsert(['key' => 'attendance_shifts'], ['value' => json_encode($shifts), 'updated_at' => now(), 'created_at' => now()]);
        }
        foreach (['students' => 'qr_clock_students', 'resume_time' => 'staff_resume_time', 'qr_only' => 'clock_qr_only'] as $in => $key) {
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

        return $this->clockPerson($me, $request);
    }

    /**
     * POST /attendance/pin-clock {key, pin} — no sign-in: scan the QR code (or use the gate kiosk),
     * type your clock-in PIN or swipe your card, and you're clocked in or out.
     */
    public function pinClock(Request $request): JsonResponse
    {
        $request->validate(['key' => 'required|string|max:100', 'pin' => 'required|string|max:60']);
        if (! hash_equals(self::qrKey(), (string) $request->input('key'))) {
            return response()->json(['error' => 'This QR code is no longer valid — ask the school office for the current one.'], 422);
        }
        $me = \App\Support\ClockPin::find((string) $request->input('pin'));
        if (! $me || $me->deleted_at || ($me->status ?? 'active') !== 'active') {
            return response()->json(['error' => 'PIN not recognised. Check it and try again, or ask the school office.'], 422);
        }

        return $this->clockPerson($me, $request);
    }

    /** Clock this person in or out now (staff or student), with the usual notifications. */
    private function clockPerson(User $me, Request $request): JsonResponse
    {
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
        if (self::qrOnly()) {
            return response()->json(['error' => 'Clock in with the school\'s QR code and your PIN (or your card) at the gate.'], 403);
        }
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

    /** GET /attendance/clock-pins — everyone's clock-in PIN, for the office to print and hand out. */
    public function pins(Request $request): JsonResponse
    {
        if (! StaffDutyController::canMarkStaff($request->user() ?: auth()->user())) {
            return $this->forbiddenResponse('Only the school leadership can see clock-in PINs.');
        }
        if (! \App\Support\ClockPin::ready()) {
            return response()->json(['people' => []]);
        }
        $classes = \Illuminate\Support\Facades\Schema::hasTable('classes')
            ? DB::table('students')->join('classes', 'classes.id', '=', 'students.class_id')->whereNull('students.deleted_at')->pluck('classes.name', 'students.user_id')
            : collect();
        $people = User::whereNotIn('role', ['guardian', 'parent', 'super_admin'])->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
            ->orderByRaw("role = 'student'")->orderBy('name')->get(['id', 'name', 'role', 'clock_pin'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role, 'class' => $classes[$u->id] ?? null, 'pin' => \App\Support\ClockPin::show($u->clock_pin)]);
        $fingerprints = \App\Support\Passkeys::ready() ? DB::table('passkeys')->select('user_id', DB::raw('count(*) c'))->groupBy('user_id')->pluck('c', 'user_id') : collect();
        $people = $people->map(fn ($p) => $p + ['fingerprints' => (int) ($fingerprints[$p['id']] ?? 0)]);

        return response()->json(['people' => $people, 'missing' => $people->whereNull('pin')->count()]);
    }

    /* ------------------------------------------------------------------ */
    /*  Fingerprint / face clock-in (passkey on the person's own phone)   */
    /* ------------------------------------------------------------------ */

    /** GET /users/me/passkeys — the phones set up for my fingerprint clock-in. */
    public function myPasskeys(Request $request): JsonResponse
    {
        if (! \App\Support\Passkeys::ready()) {
            return response()->json(['passkeys' => []]);
        }

        return response()->json(['passkeys' => DB::table('passkeys')->where('user_id', $request->user()->id)->orderBy('id')
            ->get(['id', 'name', 'created_at', 'last_used_at'])]);
    }

    /** POST /users/me/passkeys/options — start setting up fingerprint clock-in on this phone. */
    public function passkeyRegisterOptions(Request $request): JsonResponse
    {
        $me = $request->user();
        if (in_array($me->role, self::NOT_STAFF, true) && $me->role !== 'student') {
            return $this->forbiddenResponse('Only staff and students clock in.');
        }
        $rp = \App\Support\Passkeys::rpIdFor($request);
        if (! $rp || ! \App\Support\Passkeys::ready()) {
            return response()->json(['error' => 'Open Compasse on your phone (the school\'s web address) to set this up.'], 422);
        }

        return response()->json(\App\Support\Passkeys::registrationOptions($me, $rp));
    }

    /** POST /users/me/passkeys {client_data_json, attestation_object, name?} — finish setting it up. */
    public function passkeyRegister(Request $request): JsonResponse
    {
        $data = $request->validate(['client_data_json' => 'required|string|max:5000', 'attestation_object' => 'required|string|max:20000', 'name' => 'nullable|string|max:100']);
        $rp = \App\Support\Passkeys::rpIdFor($request);
        if (! $rp) {
            return response()->json(['error' => 'Open Compasse on your phone to set this up.'], 422);
        }
        try {
            \App\Support\Passkeys::register($request->user(), $rp, $data['client_data_json'], $data['attestation_object'], $data['name'] ?? null);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not set up fingerprint clock-in: ' . $e->getMessage()], 422);
        }

        return $this->myPasskeys($request)->setStatusCode(201);
    }

    /** DELETE /users/me/passkeys/{id} — stop using that phone (lost or changed). */
    public function passkeyDestroy(Request $request, int $id): JsonResponse
    {
        DB::table('passkeys')->where('id', $id)->where('user_id', $request->user()->id)->delete();

        return $this->myPasskeys($request);
    }

    /** DELETE /attendance/clock-pins/{userId}/fingerprints — the office removes someone's phones (a lost phone). */
    public function passkeyRemoveFor(Request $request, int $userId): JsonResponse
    {
        if (! StaffDutyController::canMarkStaff($request->user() ?: auth()->user())) {
            return $this->forbiddenResponse('Only the school leadership can do this.');
        }
        $n = \App\Support\Passkeys::ready() ? DB::table('passkeys')->where('user_id', $userId)->delete() : 0;

        return response()->json(['message' => $n ? "Fingerprint clock-in removed from {$n} phone(s)." : 'No fingerprint was set up.', 'removed' => $n]);
    }

    /** POST /attendance/passkey-clock/options {key} — the clock page asks the phone for a fingerprint. */
    public function passkeyClockOptions(Request $request): JsonResponse
    {
        $request->validate(['key' => 'required|string|max:100']);
        if (! hash_equals(self::qrKey(), (string) $request->input('key'))) {
            return response()->json(['error' => 'This QR code is no longer valid — ask the school office for the current one.'], 422);
        }
        $rp = \App\Support\Passkeys::rpIdFor($request);
        if (! $rp || ! \App\Support\Passkeys::ready()) {
            return response()->json(['error' => 'Fingerprint clock-in is not available here — use your PIN.'], 422);
        }

        return response()->json(\App\Support\Passkeys::clockOptions($rp));
    }

    /** POST /attendance/passkey-clock {key, challenge_id, id, client_data_json, authenticator_data, signature} */
    public function passkeyClock(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => 'required|string|max:100', 'challenge_id' => 'required|string|max:64', 'id' => 'required|string|max:512',
            'client_data_json' => 'required|string|max:5000', 'authenticator_data' => 'required|string|max:5000', 'signature' => 'required|string|max:2000',
        ]);
        if (! hash_equals(self::qrKey(), (string) $data['key'])) {
            return response()->json(['error' => 'This QR code is no longer valid — ask the school office for the current one.'], 422);
        }
        $rp = \App\Support\Passkeys::rpIdFor($request);
        if (! $rp) {
            return response()->json(['error' => 'Fingerprint clock-in is not available here — use your PIN.'], 422);
        }
        try {
            $me = \App\Support\Passkeys::verifyClock($rp, $data['challenge_id'], $data['id'], $data['client_data_json'], $data['authenticator_data'], $data['signature']);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e instanceof \RuntimeException && ! $e instanceof \lbuchs\WebAuthn\WebAuthnException
                ? $e->getMessage() : 'Fingerprint not accepted — try again or use your PIN.'], 422);
        }

        return $this->clockPerson($me, $request);
    }

    /** POST /attendance/clock-pins {user_ids?, all?} — PINs for everyone without one (or new PINs for the people chosen). */
    public function generatePins(Request $request): JsonResponse
    {
        if (! StaffDutyController::canMarkStaff($request->user() ?: auth()->user())) {
            return $this->forbiddenResponse('Only the school leadership can create clock-in PINs.');
        }
        if (! \App\Support\ClockPin::ready()) {
            return response()->json(['message' => 'Not available yet.'], 422);
        }
        $ids = (array) $request->input('user_ids', []);
        $q = User::whereNotIn('role', ['guardian', 'parent', 'super_admin'])->where(fn ($x) => $x->whereNull('status')->orWhere('status', 'active'));
        $q = $ids ? $q->whereIn('id', $ids) : ($request->boolean('all') ? $q : $q->whereNull('clock_pin_hash'));
        $n = 0;
        foreach ($q->get() as $u) {
            \App\Support\ClockPin::set($u);
            $n++;
        }

        return response()->json(['message' => "{$n} PIN(s) created."] + $this->pins($request)->getData(true));
    }

    /** GET / PUT /users/me/clock-pin {pin?} — see or change your own clock-in PIN (blank = a new random one). */
    /**
     * GET /users/me/children-pins — a parent's children and their clock-in PINs;
     * PUT {student_id, pin?} — a new PIN for one child (random when none is given).
     */
    public function childrenPins(Request $request): JsonResponse
    {
        $me = $request->user();
        $guardianId = DB::table('guardians')->where('user_id', $me->id)->value('id');
        if (! $guardianId || ! \App\Support\ClockPin::ready()) {
            return response()->json(['children' => []]);
        }
        $kids = Student::whereIn('id', DB::table('guardian_students')->where('guardian_id', $guardianId)->pluck('student_id'))
            ->whereNotNull('user_id')->get(['id', 'first_name', 'last_name', 'user_id', 'class_id']);

        if ($request->isMethod('put')) {
            $data = $request->validate(['student_id' => 'required|integer', 'pin' => ['nullable', 'regex:/^\d{4,8}$/']]);
            $kid = $kids->firstWhere('id', (int) $data['student_id']);
            if (! $kid) {
                return $this->forbiddenResponse('That is not one of your children.');
            }
            if (! empty($data['pin']) && \App\Support\ClockPin::taken($data['pin'], (int) $kid->user_id)) {
                return response()->json(['message' => 'That PIN is taken — choose another.'], 422);
            }
            \App\Support\ClockPin::set(User::findOrFail($kid->user_id), $data['pin'] ?? null);
        }

        $pins = User::whereIn('id', $kids->pluck('user_id'))->pluck('clock_pin', 'id');
        $classes = DB::table('classes')->whereIn('id', $kids->pluck('class_id')->filter())->pluck('name', 'id');

        return response()->json(['children' => $kids->map(fn ($k) => [
            'student_id' => $k->id, 'name' => trim("{$k->first_name} {$k->last_name}"), 'class' => $classes[$k->class_id] ?? null,
            'pin' => \App\Support\ClockPin::show($pins[$k->user_id] ?? null),
        ])->values()]);
    }

    public function myPin(Request $request): JsonResponse
    {
        $me = $request->user();
        if (! \App\Support\ClockPin::ready()) {
            return response()->json(['pin' => null]);
        }
        if ($request->isMethod('put')) {
            $data = $request->validate(['pin' => ['nullable', 'regex:/^\d{4,8}$/']]);
            if (! empty($data['pin']) && \App\Support\ClockPin::taken($data['pin'], $me->id)) {
                return response()->json(['message' => 'That PIN is taken — choose another.'], 422);
            }
            \App\Support\ClockPin::set($me, $data['pin'] ?? null);
        } elseif (! $me->clock_pin_hash && ! in_array($me->role, ['guardian', 'parent', 'super_admin'], true)) {
            \App\Support\ClockPin::set($me);
        }

        return response()->json(['pin' => \App\Support\ClockPin::show(User::whereKey($me->id)->value('clock_pin'))]);
    }
}
