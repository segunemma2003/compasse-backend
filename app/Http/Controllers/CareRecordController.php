<?php

namespace App\Http\Controllers;

use App\Models\CareRecord;
use App\Models\Notification;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\EmailAddress;
use App\Support\UserEffectiveRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The school's paper forms, kept online: ouch / head injury reports, prescribed medicine forms
 * (parents give consent, staff log each dose), pre-school and homework diaries, and class /
 * school / nurse daily reports. Who sees what:
 *  - leadership: everything; only they may delete (logged, with a recycle-bin copy);
 *  - staff: what they filed, plus forms about the students / classes they work with;
 *  - parents: their own children's forms of the parent-facing kinds (and their class's daily report),
 *    which they can reply to; they can also fill in a medicine form for their child.
 */
class CareRecordController extends Controller
{
    private const LEADERSHIP = ['school_admin', 'principal', 'vice_principal', 'admin', 'super_admin'];
    private const NO_ACCESS = ['student', 'driver', 'security', 'cleaner'];

    private function isLeader(User $u): bool
    {
        return in_array($u->role, self::LEADERSHIP, true)
            || array_intersect(UserEffectiveRoles::forUser($u), self::LEADERSHIP) !== [];
    }

    private function isParent(User $u): bool
    {
        return in_array($u->role, ['guardian', 'parent'], true);
    }

    /** Student ids / class ids this staff member works with (null = all). */
    private function staffScope(User $u): array
    {
        $students = $this->accessibleStudentIds($u);
        $classes = $this->accessibleClassIds($u);

        return [$students, $classes];
    }

    private function canSee(User $u, CareRecord $r): bool
    {
        if ($this->isLeader($u) || (int) $r->created_by === (int) $u->id) {
            return true;
        }
        $meta = CareRecord::meta($r->type);
        if ($this->isParent($u)) {
            if (empty($meta['parents']) || $r->status !== 'submitted') {
                return false;
            }
            $kids = $this->accessibleStudentIdsForGuardian($u) ?? [];
            if ($r->student_id) {
                return in_array((int) $r->student_id, array_map('intval', $kids), true);
            }

            return $r->class_id && Student::whereIn('id', $kids)->where('class_id', $r->class_id)->exists();
        }
        if (in_array($u->role, self::NO_ACCESS, true)) {
            return false;
        }
        [$students, $classes] = $this->staffScope($u);
        if (($meta['subject'] ?? '') === 'school') {
            return false;      // school-wide reports: the author and leadership
        }
        if ($r->student_id) {
            return $students === null || in_array((int) $r->student_id, $students, true);
        }

        return $r->class_id && ($classes === null || in_array((int) $r->class_id, $classes, true));
    }

    /** Staff who can see it may also update it (e.g. log a dose on a parent's medicine form); parents only their own medicine form before any dose. */
    private function canEdit(User $u, CareRecord $r): bool
    {
        if ($this->isParent($u)) {
            return (int) $r->created_by === (int) $u->id && $r->type === 'medicine_form' && self::dosesGiven($r) === 0;
        }

        return $this->canSee($u, $r);
    }

    /** Dose rows actually filled in on a medicine form. */
    private static function dosesGiven(CareRecord $r): int
    {
        return count(array_filter((array) ($r->data['doses'] ?? []), fn ($row) => is_array($row) && implode('', array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $row)) !== ''));
    }

    /** GET /care-records/types */
    public function types(): JsonResponse
    {
        return response()->json(['types' => collect(CareRecord::TYPES)->map(fn ($m, $k) => ['type' => $k] + $m)->values()]);
    }

    /** GET /care-records?type=&student_id=&class_id=&from=&to=&q= */
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        if (in_array($u->role, self::NO_ACCESS, true)) {
            return $this->forbiddenResponse();
        }
        $q = CareRecord::query()->with(['student:id,first_name,last_name,class_id', 'schoolClass:id,name', 'author:id,name']);

        if ($this->isParent($u)) {
            $kids = $this->accessibleStudentIdsForGuardian($u) ?? [];
            $kidClasses = Student::whereIn('id', $kids)->pluck('class_id')->filter()->all();
            $parentTypes = array_keys(array_filter(CareRecord::TYPES, fn ($m) => $m['parents']));
            $q->whereIn('type', $parentTypes)->where(fn ($w) => $w->where('status', 'submitted')->orWhere('created_by', $u->id))
                ->where(fn ($w) => $w->whereIn('student_id', $kids)->orWhere(fn ($c) => $c->whereNull('student_id')->whereIn('class_id', $kidClasses)));
        } elseif (! $this->isLeader($u)) {
            [$students, $classes] = $this->staffScope($u);
            $schoolTypes = array_keys(array_filter(CareRecord::TYPES, fn ($m) => $m['subject'] === 'school'));
            $q->where(function ($w) use ($u, $students, $classes, $schoolTypes) {
                $w->where('created_by', $u->id)->orWhere(function ($x) use ($students, $classes, $schoolTypes) {
                    $x->whereNotIn('type', $schoolTypes)->where(function ($y) use ($students, $classes) {
                        if ($students === null) {
                            $y->whereNotNull('student_id');
                        } else {
                            $y->whereIn('student_id', $students ?: [0]);
                        }
                        $y->orWhere(fn ($c) => $classes === null ? $c->whereNull('student_id')->whereNotNull('class_id')
                            : $c->whereNull('student_id')->whereIn('class_id', $classes ?: [0]));
                    });
                });
            });
        }

        if ($request->filled('type')) {
            $q->whereIn('type', (array) explode(',', (string) $request->type));
        }
        if ($request->filled('student_id')) {
            $q->where('student_id', (int) $request->student_id);
        }
        if ($request->filled('class_id')) {
            $classId = (int) $request->class_id;
            $q->where(fn ($w) => $w->where('class_id', $classId)->orWhereIn('student_id', Student::where('class_id', $classId)->pluck('id')));
        }
        if ($request->filled('from')) {
            $q->whereDate('record_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $q->whereDate('record_date', '<=', $request->to);
        }
        if ($request->filled('q')) {
            $term = '%' . $request->q . '%';
            $q->where(fn ($w) => $w->whereHas('student', fn ($s) => $s->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term))
                ->orWhere('data', 'like', $term));
        }

        $page = $q->orderByDesc('record_date')->orderByDesc('id')->paginate(min((int) $request->get('per_page', 30), 100));
        $page->getCollection()->transform(fn ($r) => $this->present($r, false));

        return response()->json($page);
    }

    /** GET /care-records/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $r = CareRecord::with(['student', 'schoolClass:id,name', 'author:id,name,role', 'editor:id,name'])->findOrFail($id);
        if (! $this->canSee($request->user(), $r)) {
            return $this->forbiddenResponse('You cannot view this record.');
        }

        return response()->json(['record' => $this->present($r, true) + [
            'can_edit' => $this->canEdit($request->user(), $r),
            'can_delete' => $this->isLeader($request->user()),
            'can_respond' => $this->isParent($request->user()) && ! empty(CareRecord::meta($r->type)['parents']),
        ]]);
    }

    private function present(CareRecord $r, bool $full): array
    {
        $s = $r->student;
        $out = [
            'id' => $r->id, 'type' => $r->type, 'type_label' => CareRecord::typeLabel($r->type), 'number' => $r->number,
            'record_date' => $r->record_date?->toDateString(), 'period_end' => $r->period_end?->toDateString(), 'status' => $r->status,
            'student' => $s ? ['id' => $s->id, 'name' => trim("{$s->first_name} {$s->last_name}"), 'class_id' => $s->class_id] : null,
            'class' => $r->schoolClass ? ['id' => $r->schoolClass->id, 'name' => $r->schoolClass->name] : null,
            'author' => $r->author?->name, 'created_by' => $r->created_by, 'created_at' => $r->created_at, 'updated_at' => $r->updated_at,
            'has_response' => ! empty($r->parent_response), 'sent_at' => $r->sent_at,
        ];
        if (! $full) {
            $out['preview'] = $this->preview($r);

            return $out;
        }
        $dob = $s?->date_of_birth;
        $out['student'] = $s ? $out['student'] + [
            'gender' => $s->gender, 'date_of_birth' => $dob?->toDateString(),
            'age' => $dob ? $dob->diff($r->record_date ?? now())->y : null,
            'admission_number' => $s->admission_number, 'class_name' => $s->class_id ? DB::table('classes')->where('id', $s->class_id)->value('name') : null,
        ] : null;
        $out['data'] = $r->data ?? [];
        $out['parent_response'] = $r->parent_response;
        $out['author_role'] = $r->author?->role;
        $out['edited_by'] = $r->editor?->name;
        $out['history'] = AuditLog::for('care_record', $r->id, 50);

        return $out;
    }

    /** A one-line summary for lists. */
    private function preview(CareRecord $r): string
    {
        $d = $r->data ?? [];
        $first = fn (array $keys) => collect($keys)->map(fn ($k) => is_string($d[$k] ?? null) ? trim($d[$k]) : '')->first(fn ($v) => $v !== '') ?? '';

        return mb_substr(match ($r->type) {
            'ouch_report' => $first(['injury', 'cause']),
            'head_injury' => $first(['description']),
            'medicine_form' => trim(($d['medication'] ?? '') . ' ' . ($d['dosage'] ?? '')) . (($n = self::dosesGiven($r)) ? " · {$n} dose(s) given" : ''),
            'preschool_diary' => implode(', ', (array) ($d['generally'] ?? $d['mood'] ?? [])),
            'homework_diary' => $first(['homework', 'today_i', 'teacher_comment']),
            'class_daily_report', 'class_weekly_report', 'school_daily_report' => $first(['highlights', 'activities', 'topics_covered', 'incidents', 'notes']),
            'nurse_daily_report' => count((array) ($d['visits'] ?? [])) . ' visit(s)' . (($n = $first(['notes'])) ? " · {$n}" : ''),
            default => '',
        }, 0, 160);
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'type' => ($creating ? 'required' : 'sometimes') . '|in:' . implode(',', array_keys(CareRecord::TYPES)),
            'student_id' => 'nullable|integer|exists:students,id',
            'class_id' => 'nullable|integer|exists:classes,id',
            'record_date' => 'nullable|date',
            'period_end' => 'nullable|date',
            'data' => 'nullable|array',
            'status' => 'nullable|in:draft,submitted',
            'email_parents' => 'nullable|boolean',
        ]);
    }

    /** POST /care-records */
    public function store(Request $request): JsonResponse
    {
        $u = $request->user();
        $v = $this->validated($request, true);
        $meta = CareRecord::meta($v['type']);
        if (in_array($u->role, self::NO_ACCESS, true)) {
            return $this->forbiddenResponse();
        }
        if ($this->isParent($u) && $v['type'] !== 'medicine_form') {
            return $this->forbiddenResponse('Parents can fill in the medicine form; the other forms are filled in by the school.');
        }

        $studentId = $v['student_id'] ?? null;
        $classId = $v['class_id'] ?? null;
        if ($meta['subject'] === 'student') {
            if (! $studentId) {
                return response()->json(['message' => 'Choose the child this form is about.'], 422);
            }
            if (! $this->studentWithinScope($u, (int) $studentId)) {
                return $this->forbiddenResponse('That child is not in your class.');
            }
            $classId = Student::where('id', $studentId)->value('class_id');
        } elseif ($meta['subject'] === 'class') {
            if (! $classId) {
                return response()->json(['message' => 'Choose the class.'], 422);
            }
            if (! $this->isLeader($u) && ! $this->classWithinScope($u, (int) $classId)) {
                return $this->forbiddenResponse('That is not one of your classes.');
            }
            $studentId = null;
        } else {
            $studentId = $classId = null;
        }

        $r = DB::transaction(function () use ($v, $u, $studentId, $classId) {
            $serial = (int) CareRecord::where('type', $v['type'])->lockForUpdate()->max('serial') + 1;

            return CareRecord::create([
                'type' => $v['type'], 'serial' => $serial, 'student_id' => $studentId, 'class_id' => $classId,
                'record_date' => $v['record_date'] ?? now()->toDateString(), 'period_end' => $v['period_end'] ?? null,
                'data' => $v['data'] ?? [], 'status' => $v['status'] ?? 'submitted', 'created_by' => $u->id,
            ]);
        });
        AuditLog::record('care_record', $r->id, 'created', 'Filed ' . $this->describe($r));

        $sent = $r->status === 'submitted' ? $this->send($r, $u, $request->has('email_parents') ? $request->boolean('email_parents') : (bool) $meta['email']) : [];

        return response()->json(['message' => CareRecord::typeLabel($r->type) . ' saved' . $this->sentText($sent), 'record' => ['id' => $r->id, 'number' => $r->number], 'sent' => $sent], 201);
    }

    /** PUT /care-records/{id} — the change is logged with who made it. */
    public function update(Request $request, int $id): JsonResponse
    {
        $u = $request->user();
        $r = CareRecord::findOrFail($id);
        if (! $this->canEdit($u, $r)) {
            return $this->forbiddenResponse('You cannot edit this record.');
        }
        $v = $this->validated($request, false);
        $before = ['record_date' => $r->record_date?->toDateString(), 'period_end' => $r->period_end?->toDateString(), 'status' => $r->status] + ($r->data ?? []);
        $wasDraft = $r->status === 'draft';

        $fill = array_intersect_key($v, array_flip(['record_date', 'period_end', 'status']));
        if (array_key_exists('data', $v)) {
            $fill['data'] = $v['data'] ?? [];
        }
        if (! empty($v['student_id']) && CareRecord::meta($r->type)['subject'] === 'student' && (int) $v['student_id'] !== (int) $r->student_id) {
            if (! $this->studentWithinScope($u, (int) $v['student_id'])) {
                return $this->forbiddenResponse('That child is not in your class.');
            }
            $fill['student_id'] = (int) $v['student_id'];
            $fill['class_id'] = Student::where('id', $v['student_id'])->value('class_id');
        }
        $fill['updated_by'] = $u->id;
        $r->update($fill);

        $after = ['record_date' => $r->record_date?->toDateString(), 'period_end' => $r->period_end?->toDateString(), 'status' => $r->status] + ($r->data ?? []);
        $changes = AuditLog::diff($before, $after) + array_map(fn ($old) => [$old, null], array_diff_key($before, $after));
        if ($changes) {
            AuditLog::record('care_record', $r->id, 'updated', 'Edited ' . $this->describe($r) . ' (' . implode(', ', array_map(fn ($k) => str_replace('_', ' ', $k), array_keys($changes))) . ')', $changes);
        }
        $sent = $wasDraft && $r->status === 'submitted' ? $this->send($r, $u, $request->has('email_parents') ? $request->boolean('email_parents') : (bool) CareRecord::meta($r->type)['email']) : [];

        return response()->json(['message' => 'Saved' . $this->sentText($sent), 'sent' => $sent]);
    }

    /** DELETE /care-records/{id} — leadership only; logged and kept in the recycle bin. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $u = $request->user();
        if (! $this->isLeader($u)) {
            return $this->forbiddenResponse('Only the school admin can delete records.');
        }
        $r = CareRecord::findOrFail($id);
        $reason = trim((string) $request->input('reason', ''));
        AuditLog::record('care_record', $r->id, 'deleted', 'Deleted ' . $this->describe($r) . ($reason ? " — {$reason}" : ''));
        $r->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /** POST /care-records/{id}/respond — a parent's comment / signature. */
    public function respond(Request $request, int $id): JsonResponse
    {
        $u = $request->user();
        $r = CareRecord::findOrFail($id);
        if (! $this->isParent($u) || ! $this->canSee($u, $r)) {
            return $this->forbiddenResponse();
        }
        $v = $request->validate([
            'comment' => 'nullable|string|max:5000',
            'name' => 'nullable|string|max:150',
            'relationship' => 'nullable|string|max:60',
            'days' => 'nullable|array',
            'days.*' => 'nullable|string|max:1000',
            'acknowledged' => 'nullable|boolean',
        ]);
        $r->parent_response = array_filter([
            'comment' => $v['comment'] ?? null, 'name' => $v['name'] ?? $u->name, 'relationship' => $v['relationship'] ?? null,
            'days' => $v['days'] ?? null, 'acknowledged' => (bool) ($v['acknowledged'] ?? true),
            'user_id' => $u->id, 'signed_at' => now()->toIso8601String(),
        ], fn ($x) => $x !== null && $x !== []);
        $r->save();
        AuditLog::record('care_record', $r->id, 'parent_response', $u->name . ' replied to ' . $this->describe($r));
        if ($r->created_by && (int) $r->created_by !== (int) $u->id) {
            Notification::create(['user_id' => $r->created_by, 'type' => 'info', 'title' => 'Parent replied: ' . CareRecord::typeLabel($r->type),
                'message' => mb_substr($u->name . ': ' . ($v['comment'] ?? 'Seen and signed'), 0, 250)]);
        }

        return response()->json(['message' => 'Thank you — your reply was sent to the school.']);
    }

    /** GET /care-records/{id}/history */
    public function history(Request $request, int $id): JsonResponse
    {
        $r = CareRecord::find($id);
        if ($r ? ! $this->canSee($request->user(), $r) : ! $this->isLeader($request->user())) {
            return $this->forbiddenResponse();
        }

        return response()->json(['history' => AuditLog::for('care_record', $id)]);
    }

    /**
     * GET /care-records/prefill?type=&class_id=&student_id=&date= — what the school already knows
     * for that day: attendance for a class / the school, staff clocked in, a child's arrival time.
     */
    public function prefill(Request $request): JsonResponse
    {
        $date = $request->input('date', now()->toDateString());
        $out = [];
        $studentType = [Student::class, 'App\\Models\\Student', 'student'];
        $att = fn () => DB::table('attendances')->whereDate('date', $date)->whereIn('attendanceable_type', $studentType);
        if (! \Illuminate\Support\Facades\Schema::hasTable('attendances')) {
            return response()->json(['prefill' => $out]);
        }

        if ($request->filled('student_id') && $this->studentWithinScope($request->user(), (int) $request->student_id)) {
            $row = $att()->where('attendanceable_id', (int) $request->student_id)->first();
            if ($row) {
                $out['arrival_time'] = $row->check_in_time ? substr($row->check_in_time, 0, 5) : null;
                $out['sent_home'] = $row->check_out_time ? substr($row->check_out_time, 0, 5) : null;
                $out['attendance'] = $row->status;
            }
        }
        if ($request->filled('class_id') || in_array($request->type, ['school_daily_report'], true)) {
            $students = Student::query()->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'));
            if ($request->filled('class_id')) {
                $students->where('class_id', (int) $request->class_id);
            }
            $students = $students->get(['id', 'first_name', 'last_name']);
            $rows = $att()->whereIn('attendanceable_id', $students->pluck('id'))->get()->keyBy('attendanceable_id');
            $absent = $students->filter(fn ($s) => in_array($rows[$s->id]->status ?? null, ['absent', 'excused'], true));
            $out['enrolled'] = $students->count();
            $out['present'] = $rows->whereIn('status', ['present', 'late'])->count();
            $out['absent'] = $absent->count();
            $out['late'] = $rows->where('status', 'late')->count();
            $out['not_marked'] = $students->count() - $rows->count();
            $out['absentees'] = $absent->map(fn ($s) => trim("{$s->first_name} {$s->last_name}"))->values()->implode(', ');
        }
        if ($request->type === 'school_daily_report') {
            $out['staff_present'] = DB::table('attendances')->whereDate('date', $date)
                ->whereIn('attendanceable_type', ['App\\Models\\Teacher', 'App\\Models\\User', 'App\\Models\\Staff', 'teacher', 'staff', 'user'])
                ->whereIn('status', ['present', 'late'])->count();
        }

        return response()->json(['prefill' => $out]);
    }

    private function describe(CareRecord $r): string
    {
        $r->loadMissing(['student:id,first_name,last_name', 'schoolClass:id,name']);
        $who = $r->student ? ' for ' . trim("{$r->student->first_name} {$r->student->last_name}") : ($r->schoolClass ? ' for ' . $r->schoolClass->name : '');

        return CareRecord::typeLabel($r->type) . ' No. ' . $r->number . $who . ' (' . $r->record_date?->format('d M Y') . ')';
    }

    private function sentText(array $sent): string
    {
        $bits = [];
        if (! empty($sent['admins'])) {
            $bits[] = 'sent to the admin';
        }
        if (! empty($sent['parents'])) {
            $bits[] = "sent to {$sent['parents']} parent(s)";
        }

        return $bits ? ' and ' . implode(' and ', $bits) : '';
    }

    /** Notify leadership (in-app + email) and the child's / class's parents, as the form type says. */
    private function send(CareRecord $r, User $by, bool $emailParents): array
    {
        $meta = CareRecord::meta($r->type);
        $sent = ['admins' => 0, 'parents' => 0];
        try {
            $label = CareRecord::typeLabel($r->type);
            $what = $this->describe($r);
            $school = School::first();
            $link = $this->link($r);

            if (! empty($meta['admin'])) {
                $admins = User::whereIn('role', ['school_admin', 'principal', 'vice_principal', 'admin'])->where('id', '!=', $by->id)->get(['id', 'email', 'email_generated']);
                foreach ($admins as $a) {
                    Notification::create(['user_id' => $a->id, 'type' => in_array($r->type, ['ouch_report', 'head_injury'], true) ? 'warning' : 'info',
                        'title' => $label . ' from ' . $by->name, 'message' => mb_substr($what . ($this->preview($r) ? ' — ' . $this->preview($r) : ''), 0, 250)]);
                    if (EmailAddress::isReal($a->email) && ! EmailAddress::isPlaceholder($a->email) && ! $a->email_generated) {
                        \App\Jobs\SendEmailJob::dispatch($a->email, $label . ' — ' . ($school?->name ?? 'school'),
                            "{$by->name} filed {$what}.\n\n" . $this->summary($r) . ($link ? "\n\nOpen it: {$link}" : ''), [], [],
                            $school ? (string) $school->id : null, false, 'care_record');
                    }
                    $sent['admins']++;
                }
            }

            if (! empty($meta['parents'])) {
                $studentIds = $r->student_id ? [$r->student_id] : Student::where('class_id', $r->class_id)->pluck('id')->all();
                $parents = User::whereIn('id', DB::table('guardians')->join('guardian_students', 'guardian_students.guardian_id', '=', 'guardians.id')
                    ->whereIn('guardian_students.student_id', $studentIds)->whereNotNull('guardians.user_id')->distinct()->pluck('guardians.user_id'))
                    ->get(['id', 'email', 'email_generated']);
                foreach ($parents as $p) {
                    Notification::create(['user_id' => $p->id, 'type' => in_array($r->type, ['ouch_report', 'head_injury'], true) ? 'warning' : 'info',
                        'title' => $label, 'message' => mb_substr($what, 0, 250)]);
                    if ($emailParents && EmailAddress::isReal($p->email) && ! EmailAddress::isPlaceholder($p->email) && ! $p->email_generated) {
                        \App\Jobs\SendEmailJob::dispatch($p->email, $label . ' — ' . ($school?->name ?? 'school'),
                            "Dear Parent/Guardian,\n\n{$what}, from {$by->name}.\n\n" . $this->summary($r)
                            . ($link ? "\n\nSee the full form and reply on the parent portal: {$link}" : '') . "\n\n" . ($school?->name ?? ''), [], [],
                            $school ? (string) $school->id : null, false, 'care_record');
                        $sent['parents']++;
                    }
                }
            }
            $r->forceFill(['sent_at' => now()])->save();
        } catch (\Throwable $e) {
            report($e);
        }

        return $sent;
    }

    /** The key lines of a form, for emails. */
    private function summary(CareRecord $r): string
    {
        $d = $r->data ?? [];
        $fields = match ($r->type) {
            'ouch_report' => ['time' => 'Time', 'cause' => 'Cause of accident', 'injury' => 'Nature of injury', 'first_aid' => 'First aid / treatment', 'medication' => 'Medication'],
            'head_injury' => ['time' => 'Time', 'description' => 'Description of injury', 'first_aid' => 'First aid administered', 'medication' => 'Medication', 'advice' => 'Advice'],
            'class_daily_report' => ['activities' => 'Activities', 'highlights' => 'Highlights', 'homework' => 'Homework', 'notes' => 'Notes'],
            default => ['notes' => 'Notes', 'highlights' => 'Highlights', 'incidents' => 'Incidents'],
        };
        $lines = [];
        foreach ($fields as $k => $label) {
            if (is_string($d[$k] ?? null) && trim($d[$k]) !== '') {
                $lines[] = "{$label}: " . trim($d[$k]);
            }
        }

        return implode("\n", $lines);
    }

    private function link(CareRecord $r): string
    {
        try {
            $sub = tenant()?->subdomain;
        } catch (\Throwable) {
            $sub = null;
        }

        return $sub ? 'https://' . $sub . '.' . config('app.root_domain', 'compasse.net') . '/school/records?open=' . $r->id : '';
    }
}
