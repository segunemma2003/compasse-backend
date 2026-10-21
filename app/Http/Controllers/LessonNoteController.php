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
 * Lesson notes: teachers write them (or draft one with the school's AI key), submit them, and the
 * head / HOD approves them or sends them back with a comment. Printable on the school letterhead.
 */
class LessonNoteController extends Controller
{
    private const REVIEWERS = ['super_admin', 'school_admin', 'principal', 'vice_principal', 'admin', 'hod'];
    private const STAFF = ['super_admin', 'school_admin', 'principal', 'vice_principal', 'admin', 'teacher', 'class_teacher', 'subject_teacher', 'year_tutor', 'hod'];
    private const AI_DAILY_LIMIT = 30;

    private function user(Request $request)
    {
        return $request->user() ?: auth()->user();
    }

    private function reviewer($u): bool
    {
        return $u && in_array($u->role, self::REVIEWERS, true);
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
            ->select('lesson_notes.*', 'users.name as teacher', 'classes.name as class_name', 'subjects.name as subject_name', 'terms.name as term_name');
    }

    /** GET /lesson-notes?status=&class_id=&subject_id=&term_id=&week=&mine= */
    public function index(Request $request): JsonResponse
    {
        $u = $this->user($request);
        if (! $u || ! in_array($u->role, self::STAFF, true)) {
            return $this->forbiddenResponse();
        }
        $q = $this->base();
        if (! $this->reviewer($u) || $request->boolean('mine')) {
            $q->where('lesson_notes.user_id', $u->id);
        }
        foreach (['status', 'class_id', 'subject_id', 'term_id', 'week'] as $f) {
            if ($request->filled($f)) {
                $q->where('lesson_notes.' . $f, $request->input($f));
            }
        }
        $rows = $q->orderByDesc('lesson_notes.updated_at')->limit(300)->get()->map(fn ($n) => $this->present($n));
        $ai = AiWriter::config();

        return response()->json([
            'notes' => $rows, 'can_review' => $this->reviewer($u),
            'ai' => ['ready' => (bool) ($ai['provider'] && $ai['key']), 'provider' => $ai['provider'] ? AiWriter::PROVIDERS[$ai['provider']]['name'] : null],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $n = $this->base()->where('lesson_notes.id', $id)->first();
        $u = $this->user($request);
        if (! $n || (! $this->reviewer($u) && (int) $n->user_id !== (int) $u?->id)) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json(['note' => $this->present($n)]);
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
        if (! $this->reviewer($u) && ! ($own && in_array($n->status, ['draft', 'returned'], true))) {
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
        if (! $this->reviewer($u) && ! ((int) $n->user_id === (int) $u?->id && $n->status !== 'approved')) {
            return $this->forbiddenResponse();
        }
        DB::table('lesson_notes')->where('id', $id)->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /** POST /lesson-notes/{id}/submit — for vetting. */
    public function submit(Request $request, int $id): JsonResponse
    {
        $u = $this->user($request);
        $n = DB::table('lesson_notes')->find($id);
        if (! $n || (int) $n->user_id !== (int) $u?->id) {
            return response()->json(['error' => 'Not found'], 404);
        }
        DB::table('lesson_notes')->where('id', $id)->update(['status' => 'submitted', 'submitted_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('users')->whereIn('role', ['principal', 'vice_principal', 'school_admin'])->pluck('id') as $rid) {
            \App\Models\Notification::create(['user_id' => $rid, 'type' => 'info', 'title' => 'Lesson note to vet', 'message' => mb_substr("{$u->name}: {$n->topic}", 0, 250)]);
        }

        return response()->json(['message' => 'Submitted for vetting']);
    }

    /** POST /lesson-notes/{id}/review {decision: approve|return, comment?} */
    public function review(Request $request, int $id): JsonResponse
    {
        $u = $this->user($request);
        if (! $this->reviewer($u)) {
            return $this->forbiddenResponse('Only the head of school or HOD vets lesson notes.');
        }
        $v = $request->validate(['decision' => 'required|in:approve,return', 'comment' => 'nullable|string|max:3000']);
        $n = DB::table('lesson_notes')->find($id);
        if (! $n) {
            return response()->json(['error' => 'Not found'], 404);
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
        if (! $n || (! $this->reviewer($u) && (int) $n->user_id !== (int) $u?->id)) {
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
            . '<div class="stamp">Status: ' . e(ucfirst($n->status)) . ($n->review_comment ? ' — ' . e($n->review_comment) : '') . '<br><br>Vetted by: ______________________ &nbsp;&nbsp; Date: ____________</div>'
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
