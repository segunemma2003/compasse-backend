<?php

namespace App\Http\Controllers;

use App\Support\AiWriter;
use App\Support\SchoolColor;
use App\Support\FinanceDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Lesson notes: teachers write them (or draft one with the school's AI key) and submit them to their
 * line manager or the subject's HOD, who approves them or sends them back with a comment. Approved
 * notes can be shared with a class straight away or from a set date. Printable on the letterhead.
 */
class LessonNoteController extends Controller
{
    /** Leadership can vet any note; anyone else only the notes sent to them. */
    private const LEADERSHIP = ['super_admin', 'school_admin', 'principal', 'vice_principal', 'admin'];
    private const STAFF = ['super_admin', 'school_admin', 'principal', 'vice_principal', 'admin', 'teacher', 'class_teacher', 'subject_teacher', 'year_tutor', 'hod'];
    private const AI_DAILY_LIMIT = 30;

    private function user(Request $request)
    {
        return $request->user() ?: auth()->user();
    }

    private function leadership($u): bool
    {
        return $u && in_array($u->role, self::LEADERSHIP, true);
    }

    private function canReview($u, $n): bool
    {
        return $this->leadership($u) || ($u && $n && (int) ($n->reviewer_id ?? 0) === (int) $u->id);
    }

    private function canSee($u, $n): bool
    {
        return $n && ($this->canReview($u, $n) || (int) $n->user_id === (int) $u?->id);
    }

    /** Who vets this teacher's note: their line manager, else the HOD of the subject's (or their) department. */
    public static function suggestedReviewer(int $teacherUserId, ?int $subjectId): ?int
    {
        $teacher = DB::table('teachers')->where('user_id', $teacherUserId)->first();
        if ($teacher && ! empty($teacher->line_manager_id) && (int) $teacher->line_manager_id !== $teacherUserId) {
            return (int) $teacher->line_manager_id;
        }
        $deptIds = array_filter([
            $subjectId ? DB::table('subjects')->where('id', $subjectId)->value('department_id') : null,
            $teacher->department_id ?? null,
        ]);
        foreach ($deptIds as $d) {
            $headUser = DB::table('departments')->join('teachers', 'teachers.id', '=', 'departments.head_id')->where('departments.id', $d)->value('teachers.user_id');
            if ($headUser && (int) $headUser !== $teacherUserId) {
                return (int) $headUser;
            }
        }

        return null;
    }

    private function present($n): array
    {
        $n->content = $n->content ? json_decode($n->content, true) : [];

        return (array) $n;
    }

    private function base()
    {
        return DB::table('lesson_notes')->leftJoin('users', 'users.id', '=', 'lesson_notes.user_id')
            ->leftJoin('classes', 'classes.id', '=', 'lesson_notes.class_id')->leftJoin('subjects', 'subjects.id', '=', 'lesson_notes.subject_id')
            ->leftJoin('terms', 'terms.id', '=', 'lesson_notes.term_id')
            ->leftJoin('users as rv', 'rv.id', '=', 'lesson_notes.reviewer_id')->leftJoin('classes as sc', 'sc.id', '=', 'lesson_notes.shared_class_id')
            ->select('lesson_notes.*', 'users.name as teacher', 'classes.name as class_name', 'subjects.name as subject_name', 'terms.name as term_name',
                'rv.name as reviewer_name', 'sc.name as shared_class_name');
    }

    /** GET /lesson-notes?status=&class_id=&subject_id=&term_id=&week=&mine= */
    public function index(Request $request): JsonResponse
    {
        $u = $this->user($request);
        if (! $u || ! in_array($u->role, self::STAFF, true)) {
            return $this->forbiddenResponse();
        }
        $q = $this->base();
        if ($request->boolean('mine')) {
            $q->where('lesson_notes.user_id', $u->id);
        } elseif ($request->boolean('to_vet')) {
            $q->where('lesson_notes.status', 'submitted');
            if (! $this->leadership($u)) {
                $q->where('lesson_notes.reviewer_id', $u->id);
            }
        } elseif (! $this->leadership($u)) {
            $q->where(fn ($w) => $w->where('lesson_notes.user_id', $u->id)->orWhere('lesson_notes.reviewer_id', $u->id));
        }
        foreach (['status', 'class_id', 'subject_id', 'term_id', 'week'] as $f) {
            if ($request->filled($f)) {
                $q->where('lesson_notes.' . $f, $request->input($f));
            }
        }
        $rows = $q->orderByDesc('lesson_notes.updated_at')->limit(300)->get()
            ->map(fn ($n) => $this->present($n) + ['can_review' => $this->canReview($u, $n)]);
        $ai = AiWriter::config();
        $toVet = DB::table('lesson_notes')->where('status', 'submitted')
            ->when(! $this->leadership($u), fn ($w) => $w->where('reviewer_id', $u->id))->count();

        return response()->json([
            'notes' => $rows, 'can_review' => $this->leadership($u) || $toVet > 0, 'is_leadership' => $this->leadership($u), 'to_vet' => $toVet,
            'ai' => ['ready' => (bool) ($ai['provider'] && $ai['key']), 'provider' => $ai['provider'] ? AiWriter::PROVIDERS[$ai['provider']]['name'] : null],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $n = $this->base()->where('lesson_notes.id', $id)->first();
        $u = $this->user($request);
        if (! $this->canSee($u, $n)) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json(['note' => $this->present($n) + ['can_review' => $this->canReview($u, $n)]]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'class_id' => 'nullable|integer', 'subject_id' => 'nullable|integer', 'term_id' => 'nullable|integer', 'academic_year_id' => 'nullable|integer',
            'week' => 'nullable|integer|min:1|max:20', 'lesson_date' => 'nullable|date', 'topic' => 'required|string|max:255', 'sub_topic' => 'nullable|string|max:255',
            'duration_minutes' => 'nullable|integer|min:5|max:600', 'content' => 'nullable|array', 'ai_generated' => 'nullable|boolean',
        ]);
    }

    /** POST /lesson-notes */
    public function store(Request $request): JsonResponse
    {
        $u = $this->user($request);
        if (! $u || ! in_array($u->role, self::STAFF, true)) {
            return $this->forbiddenResponse();
        }
        $v = $this->validated($request);
        $v['term_id'] ??= DB::table('terms')->where('is_current', true)->value('id');
        $v['academic_year_id'] ??= DB::table('academic_years')->where('is_current', true)->value('id');
        $id = DB::table('lesson_notes')->insertGetId(array_merge($v, [
            'content' => json_encode($v['content'] ?? []), 'user_id' => $u->id, 'status' => 'draft', 'ai_generated' => (bool) ($v['ai_generated'] ?? false),
            'created_at' => now(), 'updated_at' => now(),
        ]));

        return response()->json(['message' => 'Lesson note saved', 'id' => $id], 201);
    }

    /** PUT /lesson-notes/{id} — the teacher while it's a draft or sent back; reviewers any time. */
    public function update(Request $request, int $id): JsonResponse
    {
        $u = $this->user($request);
        $n = DB::table('lesson_notes')->find($id);
        if (! $n) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $own = (int) $n->user_id === (int) $u?->id;
        if (! $this->canReview($u, $n) && ! ($own && in_array($n->status, ['draft', 'returned'], true))) {
            return $this->forbiddenResponse('A submitted lesson note can be changed once it is sent back.');
        }
        $v = $this->validated($request);
        if (array_key_exists('content', $v)) {
            $v['content'] = json_encode($v['content'] ?? []);
        }
        DB::table('lesson_notes')->where('id', $id)->update($v + ['updated_at' => now()]);

        return response()->json(['message' => 'Saved']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $u = $this->user($request);
        $n = DB::table('lesson_notes')->find($id);
        if (! $n) {
            return response()->json(['error' => 'Not found'], 404);
        }
        if (! $this->leadership($u) && ! ((int) $n->user_id === (int) $u?->id && $n->status !== 'approved')) {
            return $this->forbiddenResponse();
        }
        DB::table('lesson_notes')->where('id', $id)->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /** POST /lesson-notes/{id}/submit {reviewer_id?} — to the line manager / HOD (or the one chosen). */
    public function submit(Request $request, int $id): JsonResponse
    {
        $u = $this->user($request);
        $n = DB::table('lesson_notes')->find($id);
        if (! $n || (int) $n->user_id !== (int) $u?->id) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $v = $request->validate(['reviewer_id' => 'nullable|integer|exists:users,id']);
        $reviewer = isset($v['reviewer_id']) && (int) $v['reviewer_id'] !== (int) $u->id
            ? (int) $v['reviewer_id'] : self::suggestedReviewer((int) $u->id, $n->subject_id ? (int) $n->subject_id : null);
        DB::table('lesson_notes')->where('id', $id)->update(['status' => 'submitted', 'reviewer_id' => $reviewer, 'submitted_at' => now(), 'updated_at' => now()]);
        // No line manager / HOD: the school leadership vets it.
        $to = $reviewer ? [$reviewer] : DB::table('users')->whereIn('role', ['principal', 'vice_principal', 'school_admin'])->pluck('id')->all();
        foreach ($to as $rid) {
            \App\Models\Notification::create(['user_id' => $rid, 'type' => 'info', 'title' => 'Lesson note to vet', 'message' => mb_substr("{$u->name}: {$n->topic}", 0, 250)]);
        }
        $name = $reviewer ? DB::table('users')->where('id', $reviewer)->value('name') : 'the school leadership';

        return response()->json(['message' => "Sent to {$name} for vetting", 'reviewer_id' => $reviewer]);
    }

    /** GET /lesson-notes/reviewers?subject_id= — who a note can be sent to, and the suggested one. */
    public function reviewers(Request $request): JsonResponse
    {
        $u = $this->user($request);
        $hodUserIds = DB::table('departments')->join('teachers', 'teachers.id', '=', 'departments.head_id')->pluck('teachers.user_id')->all();
        $managerIds = DB::table('teachers')->whereNotNull('line_manager_id')->pluck('line_manager_id')->all();
        $people = DB::table('users')->where('id', '!=', $u?->id)
            ->where(fn ($w) => $w->whereIn('role', array_merge(self::LEADERSHIP, ['hod']))->orWhereIn('id', array_merge($hodUserIds, $managerIds)))
            ->whereNotIn('role', ['super_admin'])->orderBy('name')->get(['id', 'name', 'role']);

        return response()->json([
            'reviewers' => $people,
            'suggested' => $u ? self::suggestedReviewer((int) $u->id, $request->integer('subject_id') ?: null) : null,
        ]);
    }

    /** GET /lesson-notes/line-managers — each teacher and who vets their notes (leadership). */
    public function lineManagers(Request $request): JsonResponse
    {
        if (! $this->leadership($this->user($request))) {
            return $this->forbiddenResponse();
        }
        $rows = DB::table('teachers')->join('users', 'users.id', '=', 'teachers.user_id')->leftJoin('users as m', 'm.id', '=', 'teachers.line_manager_id')
            ->orderBy('users.name')->get(['teachers.id', 'teachers.user_id', 'users.name', 'teachers.line_manager_id', 'm.name as line_manager']);

        return response()->json(['teachers' => $rows->map(fn ($t) => (array) $t + ['suggested' => self::suggestedReviewer((int) $t->user_id, null)])]);
    }

    /** PUT /lesson-notes/line-managers {teacher_id, line_manager_id|null} */
    public function setLineManager(Request $request): JsonResponse
    {
        if (! $this->leadership($this->user($request))) {
            return $this->forbiddenResponse();
        }
        $v = $request->validate(['teacher_id' => 'required|integer|exists:teachers,id', 'line_manager_id' => 'nullable|integer|exists:users,id']);
        DB::table('teachers')->where('id', $v['teacher_id'])->update(['line_manager_id' => $v['line_manager_id'] ?? null]);

        return response()->json(['message' => 'Line manager saved']);
    }

    /**
     * POST /lesson-notes/{id}/share {class_id|null, share_at?} — an approved note to a class's
     * students, now or from a date/time. class_id null stops sharing.
     */
    public function share(Request $request, int $id): JsonResponse
    {
        $u = $this->user($request);
        $n = DB::table('lesson_notes')->find($id);
        if (! $n || ((int) $n->user_id !== (int) $u?->id && ! $this->leadership($u))) {
            return response()->json(['error' => 'Not found'], 404);
        }
        if ($n->status !== 'approved') {
            return response()->json(['error' => 'The lesson note must be approved before it is shared with students.'], 422);
        }
        $v = $request->validate(['class_id' => 'nullable|integer|exists:classes,id', 'share_at' => 'nullable|date']);
        $classId = $v['class_id'] ?? null;
        $at = $classId ? (! empty($v['share_at']) ? \Carbon\Carbon::parse($v['share_at']) : now()) : null;
        DB::table('lesson_notes')->where('id', $id)->update(['shared_class_id' => $classId, 'share_at' => $at, 'shared_by' => $classId ? $u->id : null, 'updated_at' => now()]);
        if ($classId && $at->lte(now())) {
            foreach (DB::table('students')->where('class_id', $classId)->whereNotNull('user_id')->pluck('user_id') as $sid) {
                \App\Models\Notification::create(['user_id' => $sid, 'type' => 'info', 'title' => 'New lesson note', 'message' => mb_substr($n->topic, 0, 250)]);
            }
        }

        return response()->json(['message' => ! $classId ? 'No longer shared' : ($at->lte(now()) ? 'Shared with the class' : 'Students will see it from ' . $at->format('j M Y, g:ia'))]);
    }

    /** GET /lesson-notes/shared — a student's class notes that are shared and due. */
    public function shared(Request $request): JsonResponse
    {
        $u = $this->user($request);
        $classIds = DB::table('students')->where('user_id', $u?->id)->pluck('class_id')->filter()->all();
        if (! $classIds) {
            return response()->json(['notes' => []]);
        }
        $rows = $this->base()->where('lesson_notes.status', 'approved')->whereIn('lesson_notes.shared_class_id', $classIds)
            ->where('lesson_notes.share_at', '<=', now())->orderByDesc('lesson_notes.share_at')->limit(200)->get()
            ->map(function ($n) {
                $n = $this->present($n);
                unset($n['review_comment'], $n['reviewer_id'], $n['reviewer_name'], $n['reviewed_by']);

                return $n;
            });

        return response()->json(['notes' => $rows]);
    }

    /** POST /lesson-notes/{id}/review {decision: approve|return, comment?} */
    public function review(Request $request, int $id): JsonResponse
    {
        $u = $this->user($request);
        $v = $request->validate(['decision' => 'required|in:approve,return', 'comment' => 'nullable|string|max:3000']);
        $n = DB::table('lesson_notes')->find($id);
        if (! $n) {
            return response()->json(['error' => 'Not found'], 404);
        }
        if (! $this->canReview($u, $n)) {
            return $this->forbiddenResponse('Only the line manager / HOD it was sent to (or the school leadership) vets this lesson note.');
        }
        $status = $v['decision'] === 'approve' ? 'approved' : 'returned';
        DB::table('lesson_notes')->where('id', $id)->update(['status' => $status, 'review_comment' => $v['comment'] ?? null, 'reviewed_by' => $u->id, 'reviewed_at' => now(), 'updated_at' => now()]);
        \App\Models\Notification::create(['user_id' => $n->user_id, 'type' => $status === 'approved' ? 'success' : 'warning',
            'title' => $status === 'approved' ? 'Lesson note approved' : 'Lesson note sent back', 'message' => mb_substr($n->topic . (! empty($v['comment']) ? ' — ' . $v['comment'] : ''), 0, 250)]);

        return response()->json(['message' => $status === 'approved' ? 'Approved' : 'Sent back to the teacher']);
    }

    /**
     * POST /lesson-notes/generate {class_id, subject_id, topic, sub_topic?, week?, duration_minutes?, notes?}
     * A draft lesson note from the school's AI key (not saved — the teacher reviews and edits it).
     */
    public function generate(Request $request): JsonResponse
    {
        $u = $this->user($request);
        if (! $u || ! in_array($u->role, self::STAFF, true)) {
            return $this->forbiddenResponse();
        }
        $v = $request->validate([
            'class_id' => 'nullable|integer', 'subject_id' => 'nullable|integer', 'topic' => 'required|string|max:255', 'sub_topic' => 'nullable|string|max:255',
            'week' => 'nullable|integer', 'duration_minutes' => 'nullable|integer|min:5|max:600', 'notes' => 'nullable|string|max:2000',
        ]);
        if (! AiWriter::ready()) {
            return response()->json(['error' => 'Your school has not added an AI key yet. An admin can add one in Settings → Integrations → AI.'], 422);
        }
        $key = 'ai-lesson:' . $u->id . ':' . now()->toDateString();
        if ((int) Cache::get($key, 0) >= self::AI_DAILY_LIMIT) {
            return response()->json(['error' => 'You have used today\'s ' . self::AI_DAILY_LIMIT . ' AI drafts. Try again tomorrow.'], 429);
        }

        $class = ! empty($v['class_id']) ? DB::table('classes')->where('id', $v['class_id'])->value('name') : null;
        $subject = ! empty($v['subject_id']) ? DB::table('subjects')->where('id', $v['subject_id'])->value('name') : null;
        $school = \App\Models\School::first();
        $system = 'You are an experienced Nigerian teacher writing lesson notes that follow the NERDC curriculum and the format used in Nigerian schools. '
            . 'Write clearly for the class level, with practical, local examples (Nigerian names, places and naira where relevant). Reply with JSON only.';
        $prompt = "Write a lesson note.\n"
            . 'School: ' . ($school?->name ?? '') . "\nClass: " . ($class ?? 'not given') . "\nSubject: " . ($subject ?? 'not given')
            . "\nTopic: {$v['topic']}" . (! empty($v['sub_topic']) ? "\nSub-topic: {$v['sub_topic']}" : '')
            . (! empty($v['week']) ? "\nWeek: {$v['week']}" : '') . "\nDuration: " . ($v['duration_minutes'] ?? 40) . ' minutes'
            . (! empty($v['notes']) ? "\nTeacher's notes: {$v['notes']}" : '')
            . "\n\nReturn exactly this JSON shape:\n"
            . '{"sub_topic": "", "behavioural_objectives": ["By the end of the lesson, pupils should be able to …"], "instructional_materials": [""], '
            . '"reference_materials": [""], "previous_knowledge": "", "set_induction": "", '
            . '"presentation": [{"step": "Step 1", "teacher_activity": "", "pupils_activity": "", "learning_point": ""}], '
            . '"evaluation": ["question"], "summary": "", "assignment": ["question"]}'
            . "\nUse 4–6 presentation steps and 4–6 evaluation questions.";

        try {
            $reply = AiWriter::complete($system, $prompt);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
        $data = AiWriter::json($reply);
        if (! $data) {
            return response()->json(['error' => 'The AI reply could not be read. Please try again.'], 502);
        }
        Cache::put($key, (int) Cache::get($key, 0) + 1, now()->endOfDay());

        $list = fn ($x) => array_values(array_filter(array_map(fn ($i) => is_string($i) ? trim($i) : '', (array) $x)));
        $content = [
            'behavioural_objectives' => $list($data['behavioural_objectives'] ?? []),
            'instructional_materials' => $list($data['instructional_materials'] ?? []),
            'reference_materials' => $list($data['reference_materials'] ?? []),
            'previous_knowledge' => (string) ($data['previous_knowledge'] ?? ''),
            'set_induction' => (string) ($data['set_induction'] ?? ''),
            'presentation' => array_values(array_map(fn ($s) => [
                'step' => (string) ($s['step'] ?? ''), 'teacher_activity' => (string) ($s['teacher_activity'] ?? ''),
                'pupils_activity' => (string) ($s['pupils_activity'] ?? ''), 'learning_point' => (string) ($s['learning_point'] ?? ''),
            ], array_filter((array) ($data['presentation'] ?? []), 'is_array'))),
            'evaluation' => $list($data['evaluation'] ?? []),
            'summary' => (string) ($data['summary'] ?? ''),
            'assignment' => $list($data['assignment'] ?? []),
        ];

        return response()->json(['sub_topic' => ($v['sub_topic'] ?? null) ?: ($data['sub_topic'] ?? null), 'content' => $content,
            'remaining_today' => self::AI_DAILY_LIMIT - (int) Cache::get($key, 0)]);
    }

    /** GET /lesson-notes/{id}/print?format=pdf — on the school's letterhead. */
    public function print(Request $request, int $id)
    {
        $n = $this->base()->where('lesson_notes.id', $id)->first();
        $u = $this->user($request);
        $studentCanSee = $n && $n->status === 'approved' && $n->shared_class_id && $n->share_at && \Carbon\Carbon::parse($n->share_at)->lte(now())
            && DB::table('students')->where('user_id', $u?->id)->where('class_id', $n->shared_class_id)->exists();
        if (! $this->canSee($u, $n) && ! $studentCanSee) {
            return response('<h2>Not found</h2>', 404)->header('Content-Type', 'text/html');
        }
        $c = $n->content ? json_decode($n->content, true) : [];
        $school = \App\Models\School::first();
        $color = SchoolColor::primary();
        $li = fn ($items) => $items ? '<ol>' . implode('', array_map(fn ($i) => '<li>' . e($i) . '</li>', (array) $items)) . '</ol>' : '<p>—</p>';
        $p = fn ($t) => '<p>' . nl2br(e((string) ($t ?: '—'))) . '</p>';
        $steps = '';
        foreach ((array) ($c['presentation'] ?? []) as $s) {
            $steps .= '<tr><td><b>' . e($s['step'] ?? '') . '</b></td><td>' . nl2br(e($s['teacher_activity'] ?? '')) . '</td><td>' . nl2br(e($s['pupils_activity'] ?? '')) . '</td><td>' . nl2br(e($s['learning_point'] ?? '')) . '</td></tr>';
        }
        $row = fn ($l, $v) => '<tr><td class="l">' . e($l) . '</td><td>' . e((string) ($v ?: '—')) . '</td></tr>';
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Lesson note — ' . e($n->topic) . '</title><style>'
            . 'body{font-family:"DejaVu Sans",Arial,sans-serif;font-size:12px;color:#222;padding:20px 26px;line-height:1.45}'
            . 'h1{font-size:18px;color:' . $color . ';margin:0}h2{font-size:12.5px;text-transform:uppercase;letter-spacing:.5px;color:' . $color . ';border-bottom:1px solid #ddd;margin:14px 0 6px;padding-bottom:2px}'
            . 'table{width:100%;border-collapse:collapse}table.k td{border:1px solid #ccc;padding:4px 6px}table.k td.l{width:28%;background:#f5f5f7;font-weight:bold}'
            . 'table.s th,table.s td{border:1px solid #ccc;padding:5px;vertical-align:top;text-align:left}table.s th{background:#f5f5f7}ol{margin:4px 0 4px 18px;padding:0}p{margin:4px 0}'
            . '.head td{vertical-align:middle;border:none}.head img{height:56px}.stamp{margin-top:18px;font-size:11px;color:#555}</style></head><body>'
            . '<table class="head"><tr><td style="width:70px"><img src="' . e(FinanceDocument::logoSrc($school)) . '"></td><td><h1>' . e($school?->name ?? '') . '</h1><div>LESSON NOTE</div></td></tr></table>'
            . '<table class="k" style="margin-top:10px">' . $row('Teacher', $n->teacher) . $row('Class', $n->class_name) . $row('Subject', $n->subject_name) . $row('Term / Week', trim(($n->term_name ?? '') . ($n->week ? ' · Week ' . $n->week : '')))
            . $row('Date', $n->lesson_date) . $row('Duration', $n->duration_minutes ? $n->duration_minutes . ' minutes' : null) . $row('Topic', $n->topic) . $row('Sub-topic', $n->sub_topic) . '</table>'
            . '<h2>Behavioural objectives</h2>' . $li($c['behavioural_objectives'] ?? []) . '<h2>Instructional materials</h2>' . $li($c['instructional_materials'] ?? [])
            . '<h2>Reference materials</h2>' . $li($c['reference_materials'] ?? []) . '<h2>Previous knowledge</h2>' . $p($c['previous_knowledge'] ?? '')
            . '<h2>Set induction / introduction</h2>' . $p($c['set_induction'] ?? '')
            . '<h2>Presentation</h2>' . ($steps ? '<table class="s"><tr><th style="width:14%">Step</th><th>Teacher\'s activity</th><th>Pupils\' activity</th><th style="width:22%">Learning point</th></tr>' . $steps . '</table>' : '<p>—</p>')
            . '<h2>Evaluation</h2>' . $li($c['evaluation'] ?? []) . '<h2>Summary / conclusion</h2>' . $p($c['summary'] ?? '') . '<h2>Assignment</h2>' . $li($c['assignment'] ?? [])
            . ($studentCanSee && ! $this->canSee($u, $n) ? '' : '<div class="stamp">Status: ' . e(ucfirst($n->status)) . ($n->review_comment ? ' — ' . e($n->review_comment) : '')
                . '<br><br>Vetted by: ' . e($n->reviewer_name ?: '______________________') . ($n->reviewed_at ? ' &nbsp;&nbsp; Date: ' . e(\Carbon\Carbon::parse($n->reviewed_at)->format('j M Y')) : ' &nbsp;&nbsp; Date: ____________') . '</div>')
            . '</body></html>';

        return $request->query('format') === 'pdf'
            ? response(app(\App\Services\HtmlToPdfService::class)->fromHtml($html), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="lesson-note-' . $id . '.pdf"'])
            : response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }

    // ── the school's AI key (admin) ─────────────────────────────────────

    public function aiSettings(Request $request): JsonResponse
    {
        if ($denied = $this->requireSchoolAdminLike($request)) {
            return $denied;
        }

        return response()->json(AiWriter::publicConfig());
    }

    public function saveAiSettings(Request $request): JsonResponse
    {
        if ($denied = $this->requireSchoolAdminLike($request)) {
            return $denied;
        }
        $v = $request->validate(['provider' => 'nullable|in:' . implode(',', array_keys(AiWriter::PROVIDERS)), 'model' => 'nullable|string|max:100', 'api_key' => 'nullable|string|max:400', 'remove_key' => 'nullable|boolean']);
        AiWriter::save($v['provider'] ?? null, $v['model'] ?? null, $v['api_key'] ?? null, (bool) ($v['remove_key'] ?? false));

        return response()->json(['message' => 'AI settings saved'] + AiWriter::publicConfig());
    }

    /** POST /settings/ai/test — a tiny request to check the key works. */
    public function testAi(Request $request): JsonResponse
    {
        if ($denied = $this->requireSchoolAdminLike($request)) {
            return $denied;
        }
        try {
            $reply = AiWriter::complete('Reply with one short sentence.', 'Say hello to the teachers of this school.', 60);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'The key works.', 'reply' => trim($reply)]);
    }

    private function requireSchoolAdminLike(Request $request): ?JsonResponse
    {
        $u = $this->user($request);

        return $u && in_array($u->role, ['super_admin', 'school_admin', 'principal', 'admin'], true) ? null : $this->forbiddenResponse('Only the school admin can manage the AI key.');
    }
}
