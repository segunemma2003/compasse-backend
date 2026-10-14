<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Classroom\ClassroomComment;
use App\Models\Classroom\ClassroomPost;
use App\Models\Classroom\ClassroomTopic;
use App\Models\Classroom\ElabActivity;
use App\Models\Classroom\ElabSubmission;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\PhetCatalog;
use App\Support\UserEffectiveRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Classroom: one per subject (subjects are class-scoped), Google Classroom style.
 *
 * - Stream: announcements and materials, with public comments.
 * - Classwork: materials, the subject's assignments and e-Lab activities, grouped by topic.
 * - Private comments: a thread between one student and the teachers about a piece of work.
 * - To-do (students) and the children's work (parents): assigned, missing, done.
 *
 * Who is what in a classroom:
 * - teacher: school leadership, the subject's teachers, and the class teacher. Posts and grades.
 * - student: students in the subject's class. Reads, comments, and submits.
 * - parent: guardians of a student in the class. Read-only.
 */
class ClassroomController extends Controller
{
    private const LEADERSHIP = ['super_admin', 'school_admin', 'principal', 'vice_principal', 'admin'];

    // ─── Access ───────────────────────────────────────────────────────────

    private function isLeadership(User $u): bool
    {
        return in_array($u->role, self::LEADERSHIP, true) || (bool) array_intersect(UserEffectiveRoles::forUser($u), self::LEADERSHIP);
    }

    /** Subject ids the user teaches: assigned subjects, subjects they lead, and every subject of a class they're class teacher of. */
    private function taughtSubjectIds(User $u): array
    {
        $teacher = $u->teacher;
        if (! $teacher) {
            return [];
        }

        return DB::table('teacher_subjects')->where('teacher_id', $teacher->id)->where('status', 'active')->pluck('subject_id')
            ->merge(Subject::where('teacher_id', $teacher->id)->pluck('id'))
            ->merge(Subject::whereIn('class_id', \App\Support\ClassTeachers::classIdsFor((int) $teacher->id))->pluck('id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function studentOf(User $u): ?Student
    {
        return Student::where('user_id', $u->id)->first();
    }

    private function childrenOf(User $u)
    {
        $guardian = Guardian::where('user_id', $u->id)->first();

        return $guardian ? $guardian->students()->get() : collect();
    }

    /** The user's role in this classroom, or null when they have no access. */
    private function roleIn(Request $r, Subject $s): ?string
    {
        $u = $r->user();
        if ($this->isLeadership($u) || in_array($s->id, $this->taughtSubjectIds($u), true)) {
            return 'teacher';
        }
        $student = $this->studentOf($u);
        if ($student && (int) $student->class_id === (int) $s->class_id) {
            return 'student';
        }
        if ($this->childrenOf($u)->contains(fn ($c) => (int) $c->class_id === (int) $s->class_id)) {
            return 'parent';
        }

        return null;
    }

    private function deny(): JsonResponse
    {
        return response()->json(['message' => 'You are not in this classroom.'], 403);
    }

    private function teacherOnly(): JsonResponse
    {
        return response()->json(['message' => 'Only the classroom teachers can do this.'], 403);
    }

    private function name(?User $u): ?string
    {
        return $u?->name;
    }

    // ─── Classrooms ───────────────────────────────────────────────────────

    /** GET /classroom: the classrooms this user belongs to. */
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $q = Subject::with(['class:id,name', 'teacher.user:id,name'])->orderBy('class_id')->orderBy('name');
        $role = 'teacher';
        $childNames = [];

        if ($this->isLeadership($u)) {
            $q->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->class_id));
        } elseif ($taught = $this->taughtSubjectIds($u)) {
            $q->whereIn('id', $taught);
        } elseif ($student = $this->studentOf($u)) {
            $role = 'student';
            $q->where('class_id', $student->class_id ?? 0);
        } else {
            $role = 'parent';
            $children = $this->childrenOf($u);
            foreach ($children as $c) {
                $childNames[$c->class_id][] = $c->first_name;
            }
            $q->whereIn('class_id', $children->pluck('class_id')->filter()->unique());
        }

        $subjects = $q->get();
        $ids = $subjects->pluck('id');
        $posts = ClassroomPost::whereIn('subject_id', $ids)->selectRaw('subject_id, count(*) c, max(created_at) latest')->groupBy('subject_id')->get()->keyBy('subject_id');

        return response()->json([
            'role' => $role,
            'classrooms' => $subjects->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'code' => $s->code,
                'class_id' => $s->class_id,
                'class' => $s->class?->name,
                'teacher' => $s->teacher?->user?->name,
                'posts' => (int) ($posts[$s->id]->c ?? 0),
                'last_activity' => $posts[$s->id]->latest ?? null,
                'children' => $childNames[$s->class_id] ?? [],
            ])->values(),
        ]);
    }

    /** GET /classroom/{subject}: header info, the user's role, and the people. */
    public function show(Request $request, int $subject): JsonResponse
    {
        $s = Subject::with(['class:id,name,class_teacher_id', 'teacher.user:id,name'])->findOrFail($subject);
        if (! $role = $this->roleIn($request, $s)) {
            return $this->deny();
        }

        $teacherIds = DB::table('teacher_subjects')->where('subject_id', $s->id)->where('status', 'active')->pluck('teacher_id')
            ->push($s->teacher_id)->merge($classTeacherIds = \App\Support\ClassTeachers::teacherIdsFor((int) $s->class_id))->filter()->unique();
        $teachers = DB::table('teachers')->whereIn('teachers.id', $teacherIds)->whereNull('teachers.deleted_at')
            ->leftJoin('users', 'users.id', '=', 'teachers.user_id')
            ->get(['teachers.id', 'teachers.first_name', 'teachers.last_name', 'users.email'])
            ->map(fn ($t) => ['id' => $t->id, 'name' => trim("{$t->first_name} {$t->last_name}"), 'email' => $t->email,
                'class_teacher' => in_array((int) $t->id, $classTeacherIds, true)]);
        $students = Student::where('class_id', $s->class_id)->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'admission_number'])
            ->map(fn ($st) => ['id' => $st->id, 'name' => trim("{$st->first_name} {$st->last_name}"), 'admission_number' => $st->admission_number]);

        return response()->json([
            'classroom' => ['id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'class_id' => $s->class_id, 'class' => $s->class?->name, 'description' => $s->description],
            'role' => $role,
            'teachers' => $teachers->values(),
            'students' => $role === 'teacher' ? $students : $students->map(fn ($st) => ['id' => $st['id'], 'name' => $st['name']]),
        ]);
    }

    // ─── Stream ───────────────────────────────────────────────────────────

    /** GET /classroom/{subject}/stream */
    public function stream(Request $request, int $subject): JsonResponse
    {
        $s = Subject::findOrFail($subject);
        if (! $this->roleIn($request, $s)) {
            return $this->deny();
        }

        $posts = ClassroomPost::with('author:id,name,role')->where('subject_id', $s->id)
            ->orderByDesc('pinned')->orderByDesc('created_at')->limit(100)->get();
        $comments = ClassroomComment::with('author:id,name,role')->whereIn('post_id', $posts->pluck('id'))->orderBy('created_at')->get()->groupBy('post_id');

        // New assignments and labs appear in the stream too, like Classroom's "posted a new assignment".
        $work = $this->workItems($s, $this->roleIn($request, $s) === 'teacher')
            ->map(fn ($w) => ['kind' => $w['kind'], 'id' => $w['id'], 'title' => $w['title'], 'due' => $w['due'], 'created_at' => $w['created_at']]);

        return response()->json([
            'posts' => $posts->map(fn ($p) => $this->postRow($p, $comments[$p->id] ?? collect())),
            'work' => $work->sortByDesc('created_at')->take(30)->values(),
        ]);
    }

    private function postRow(ClassroomPost $p, $comments): array
    {
        return [
            'id' => $p->id, 'type' => $p->type, 'title' => $p->title, 'body' => $p->body, 'topic_id' => $p->topic_id,
            'links' => $p->links ?? [], 'pinned' => $p->pinned,
            'files' => collect($p->attachments ?? [])->map(fn ($f, $i) => ['index' => $i, 'name' => $f['name']])->values(),
            'author' => $this->name($p->author), 'author_id' => $p->author_id, 'created_at' => $p->created_at,
            'comments' => $comments->map(fn ($c) => ['id' => $c->id, 'body' => $c->body, 'author' => $this->name($c->author),
                'author_id' => $c->author_id, 'created_at' => $c->created_at])->values(),
        ];
    }

    /** POST /classroom/{subject}/posts (multipart: type, title, body, topic_id, links[][url|label], files[]) */
    public function storePost(Request $request, int $subject): JsonResponse
    {
        $s = Subject::findOrFail($subject);
        if ($this->roleIn($request, $s) !== 'teacher') {
            return $this->teacherOnly();
        }
        $data = $request->validate([
            'type' => 'required|in:announcement,material',
            'title' => 'nullable|string|max:255|required_if:type,material',
            'body' => 'nullable|string|max:20000',
            'topic_id' => 'nullable|integer',
            'pinned' => 'nullable|boolean',
            'links' => 'nullable|array|max:20',
            'links.*.url' => 'required|url|max:2000',
            'links.*.label' => 'nullable|string|max:255',
            'files' => 'nullable|array|max:10',
            'files.*' => 'file|max:20480|mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,txt,csv,jpg,jpeg,png,gif,webp,mp3,mp4,zip',
        ]);
        if (empty($data['body']) && empty($data['title']) && empty($data['links']) && ! $request->hasFile('files')) {
            return response()->json(['message' => 'Write something or attach a file or link.'], 422);
        }
        if (! empty($data['topic_id']) && ! ClassroomTopic::where('subject_id', $s->id)->whereKey($data['topic_id'])->exists()) {
            $data['topic_id'] = null;
        }

        $post = ClassroomPost::create([
            'subject_id' => $s->id, 'type' => $data['type'], 'title' => $data['title'] ?? null, 'body' => $data['body'] ?? null,
            'topic_id' => $data['topic_id'] ?? null, 'pinned' => (bool) ($data['pinned'] ?? false),
            'links' => array_values($data['links'] ?? []), 'author_id' => $request->user()->id,
        ]);
        $files = [];
        foreach ($request->file('files', []) as $file) {
            $files[] = ['path' => $file->store("classroom/subject-{$s->id}/post-{$post->id}", 'local'), 'name' => $file->getClientOriginalName()];
        }
        if ($files) {
            $post->update(['attachments' => $files]);
        }

        $this->notifyClass($s, $data['type'] === 'material' ? "New material in {$s->name}" : "New announcement in {$s->name}",
            $data['title'] ?? mb_substr(strip_tags($data['body'] ?? ''), 0, 200));

        return response()->json(['message' => 'Posted', 'post' => $this->postRow($post->load('author'), collect())], 201);
    }

    /** PUT /classroom/posts/{post}: edit text, topic, links, pin. */
    public function updatePost(Request $request, int $post): JsonResponse
    {
        $p = ClassroomPost::findOrFail($post);
        if ($this->roleIn($request, Subject::findOrFail($p->subject_id)) !== 'teacher') {
            return $this->teacherOnly();
        }
        $data = $request->validate([
            'title' => 'nullable|string|max:255', 'body' => 'nullable|string|max:20000', 'topic_id' => 'nullable|integer',
            'pinned' => 'nullable|boolean', 'links' => 'nullable|array|max:20', 'links.*.url' => 'required|url|max:2000', 'links.*.label' => 'nullable|string|max:255',
        ]);
        $p->update(collect($data)->only(array_keys($request->all()))->all());

        return response()->json(['message' => 'Updated', 'post' => $this->postRow($p->fresh('author'), collect())]);
    }

    /** DELETE /classroom/posts/{post} */
    public function deletePost(Request $request, int $post): JsonResponse
    {
        $p = ClassroomPost::findOrFail($post);
        if ($this->roleIn($request, Subject::findOrFail($p->subject_id)) !== 'teacher') {
            return $this->teacherOnly();
        }
        foreach ($p->attachments ?? [] as $f) {
            Storage::disk('local')->delete($f['path']);
        }
        ClassroomComment::where('post_id', $p->id)->delete();
        $p->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /** GET /classroom/posts/{post}/files/{index} */
    public function downloadFile(Request $request, int $post, int $index)
    {
        $p = ClassroomPost::findOrFail($post);
        if (! $this->roleIn($request, Subject::findOrFail($p->subject_id))) {
            return $this->deny();
        }
        $file = ($p->attachments ?? [])[$index] ?? null;
        if (! $file || ! Storage::disk('local')->exists($file['path'])) {
            return response()->json(['message' => 'File not found'], 404);
        }

        return Storage::disk('local')->download($file['path'], $file['name']);
    }

    /** POST /classroom/posts/{post}/comments: class comments (teachers and students). */
    public function storeComment(Request $request, int $post): JsonResponse
    {
        $p = ClassroomPost::findOrFail($post);
        $role = $this->roleIn($request, Subject::findOrFail($p->subject_id));
        if (! in_array($role, ['teacher', 'student'], true)) {
            return $this->deny();
        }
        $data = $request->validate(['body' => 'required|string|max:5000']);
        $c = ClassroomComment::create(['post_id' => $p->id, 'author_id' => $request->user()->id, 'body' => $data['body']]);

        return response()->json(['comment' => ['id' => $c->id, 'body' => $c->body, 'author' => $request->user()->name,
            'author_id' => $c->author_id, 'created_at' => $c->created_at]], 201);
    }

    /** DELETE /classroom/comments/{comment}: the author, or a teacher of the classroom. */
    public function deleteComment(Request $request, int $comment): JsonResponse
    {
        $c = ClassroomComment::findOrFail($comment);
        $subjectId = $c->post_id ? ClassroomPost::whereKey($c->post_id)->value('subject_id') : $this->workSubjectId($c->work_type, (int) $c->work_id);
        $isTeacher = $subjectId && $this->roleIn($request, Subject::findOrFail($subjectId)) === 'teacher';
        if ((int) $c->author_id !== (int) $request->user()->id && ! $isTeacher) {
            return response()->json(['message' => 'You can only delete your own comments.'], 403);
        }
        $c->delete();

        return response()->json(['message' => 'Deleted']);
    }

    // ─── Topics & classwork ───────────────────────────────────────────────

    /** POST /classroom/{subject}/topics */
    public function storeTopic(Request $request, int $subject): JsonResponse
    {
        $s = Subject::findOrFail($subject);
        if ($this->roleIn($request, $s) !== 'teacher') {
            return $this->teacherOnly();
        }
        $data = $request->validate(['name' => 'required|string|max:120']);
        $topic = ClassroomTopic::create(['subject_id' => $s->id, 'name' => $data['name'],
            'position' => (int) ClassroomTopic::where('subject_id', $s->id)->max('position') + 1]);

        return response()->json(['topic' => $topic], 201);
    }

    /** DELETE /classroom/topics/{topic}: items under it move to "No topic". */
    public function deleteTopic(Request $request, int $topic): JsonResponse
    {
        $t = ClassroomTopic::findOrFail($topic);
        if ($this->roleIn($request, Subject::findOrFail($t->subject_id)) !== 'teacher') {
            return $this->teacherOnly();
        }
        ClassroomPost::where('topic_id', $t->id)->update(['topic_id' => null]);
        ElabActivity::where('topic_id', $t->id)->update(['topic_id' => null]);
        $t->delete();

        return response()->json(['message' => 'Topic removed']);
    }

    /** GET /classroom/{subject}/classwork: topics, materials, assignments and labs (with the student's own status). */
    public function classwork(Request $request, int $subject): JsonResponse
    {
        $s = Subject::findOrFail($subject);
        if (! $role = $this->roleIn($request, $s)) {
            return $this->deny();
        }
        $student = $role === 'student' ? $this->studentOf($request->user()) : null;

        $materials = ClassroomPost::with('author:id,name')->where('subject_id', $s->id)->where('type', 'material')->orderByDesc('created_at')->get()
            ->map(fn ($p) => $this->postRow($p, collect()));
        $work = $this->workItems($s, $role === 'teacher', $student);

        return response()->json([
            'role' => $role,
            'topics' => ClassroomTopic::where('subject_id', $s->id)->orderBy('position')->get(['id', 'name']),
            'materials' => $materials,
            'work' => $work->values(),
        ]);
    }

    /**
     * Assignments and e-Lab activities for a subject. Students see only published assignments.
     * With a student, each item carries that student's status: assigned | missing | submitted | graded.
     */
    private function workItems(Subject $s, bool $includeDrafts, ?Student $student = null)
    {
        $assignments = Assignment::where('subject_id', $s->id)
            ->when(! $includeDrafts, fn ($q) => $q->where('status', 'published'))
            ->get(['id', 'title', 'description', 'due_date', 'total_marks', 'status', 'created_at']);
        $labs = ElabActivity::where('subject_id', $s->id)->get();

        $mySubs = $student ? AssignmentSubmission::where('student_id', $student->id)->whereIn('assignment_id', $assignments->pluck('id'))->get()->keyBy('assignment_id') : collect();
        $myLabs = $student ? ElabSubmission::where('student_id', $student->id)->whereIn('activity_id', $labs->pluck('id'))->get()->keyBy('activity_id') : collect();

        $items = $assignments->map(fn ($a) => [
            'kind' => 'assignment', 'id' => $a->id, 'title' => $a->title, 'description' => $a->description,
            'due' => $a->due_date ? \Illuminate\Support\Carbon::parse($a->due_date)->toIso8601String() : null,
            'points' => $a->total_marks, 'status' => $a->status, 'topic_id' => null, 'created_at' => $a->created_at,
            'mine' => $student ? $this->workStatus($a->due_date, $mySubs[$a->id] ?? null, fn ($sub) => $sub->status === 'graded', fn ($sub) => $sub->marks_obtained) : null,
        ]);
        $items = $items->concat($labs->map(fn ($l) => [
            'kind' => 'lab', 'id' => $l->id, 'title' => $l->title, 'description' => $l->instructions,
            'due' => $l->due_at?->toIso8601String(), 'points' => null, 'status' => 'published', 'topic_id' => $l->topic_id,
            'created_at' => $l->created_at, 'sim' => PhetCatalog::find($l->sim_slug),
            'mine' => $student ? $this->workStatus($l->due_at, $myLabs[$l->id] ?? null, fn ($sub) => $sub->score !== null, fn ($sub) => $sub->score) : null,
        ]));

        return $items->sortBy(fn ($i) => $i['due'] ?? '9999')->values();
    }

    private function workStatus($due, $sub, callable $isGraded, callable $score): array
    {
        if ($sub && $sub->submitted_at) {
            return ['state' => $isGraded($sub) ? 'graded' : 'submitted', 'score' => $isGraded($sub) ? $score($sub) : null,
                'submitted_at' => $sub->submitted_at];
        }

        return ['state' => $due && now()->gt($due) ? 'missing' : 'assigned', 'score' => null, 'submitted_at' => null];
    }

    // ─── To-do & parents ──────────────────────────────────────────────────

    /** Every classroom's work for one student, sorted into assigned / missing / done. */
    private function studentWork(Student $student): array
    {
        $out = ['assigned' => [], 'missing' => [], 'done' => []];
        foreach (Subject::where('class_id', $student->class_id ?? 0)->get() as $s) {
            foreach ($this->workItems($s, false, $student) as $w) {
                $row = ['classroom_id' => $s->id, 'classroom' => $s->name] + collect($w)->only(['kind', 'id', 'title', 'due', 'points', 'mine'])->all();
                $bucket = match ($w['mine']['state']) { 'assigned' => 'assigned', 'missing' => 'missing', default => 'done' };
                $out[$bucket][] = $row;
            }
        }
        $out['done'] = collect($out['done'])->sortByDesc(fn ($r) => $r['mine']['submitted_at'] ?? '')->take(30)->values()->all();

        return $out;
    }

    /** GET /classroom/todo (students) */
    public function todo(Request $request): JsonResponse
    {
        $student = $this->studentOf($request->user());
        if (! $student) {
            return response()->json(['assigned' => [], 'missing' => [], 'done' => []]);
        }

        return response()->json($this->studentWork($student));
    }

    /** GET /classroom/my-children (parents): each child's assigned, missing and done work. */
    public function myChildren(Request $request): JsonResponse
    {
        return response()->json(['children' => $this->childrenOf($request->user())->map(fn ($c) => [
            'id' => $c->id, 'name' => trim("{$c->first_name} {$c->last_name}"), 'class' => $c->class?->name,
        ] + $this->studentWork($c))->values()]);
    }

    // ─── Private comments on work ─────────────────────────────────────────

    private function workSubjectId(?string $type, int $id): ?int
    {
        return match ($type) {
            'assignment' => Assignment::whereKey($id)->value('subject_id'),
            'lab' => ElabActivity::whereKey($id)->value('subject_id'),
            default => null,
        };
    }

    /** Resolve [role, studentId] for a private thread, or a JsonResponse error. */
    private function privateThread(Request $request, string $type, int $id): array|JsonResponse
    {
        $subjectId = $this->workSubjectId($type, $id);
        if (! $subjectId) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $role = $this->roleIn($request, Subject::findOrFail($subjectId));
        if ($role === 'student') {
            return [$role, $this->studentOf($request->user())->id];
        }
        if ($role === 'teacher') {
            $studentId = (int) $request->input('student_id');

            return $studentId ? [$role, $studentId] : response()->json(['message' => 'Choose a student.'], 422);
        }

        return $this->deny();
    }

    /** GET /classroom/work/{type}/{id}/comments?student_id= */
    public function privateComments(Request $request, string $type, int $id): JsonResponse
    {
        $t = $this->privateThread($request, $type, $id);
        if ($t instanceof JsonResponse) {
            return $t;
        }

        return response()->json(['comments' => ClassroomComment::with('author:id,name,role')
            ->where('work_type', $type)->where('work_id', $id)->where('student_id', $t[1])->orderBy('created_at')->get()
            ->map(fn ($c) => ['id' => $c->id, 'body' => $c->body, 'author' => $this->name($c->author), 'author_id' => $c->author_id, 'created_at' => $c->created_at])]);
    }

    /** POST /classroom/work/{type}/{id}/comments {body, student_id (teachers)} */
    public function storePrivateComment(Request $request, string $type, int $id): JsonResponse
    {
        $t = $this->privateThread($request, $type, $id);
        if ($t instanceof JsonResponse) {
            return $t;
        }
        $data = $request->validate(['body' => 'required|string|max:5000']);
        $c = ClassroomComment::create(['work_type' => $type, 'work_id' => $id, 'student_id' => $t[1], 'author_id' => $request->user()->id, 'body' => $data['body']]);

        // Teacher → tell the student; student → tell the subject's teacher.
        $s = Subject::with('teacher')->find($this->workSubjectId($type, $id));
        $to = $t[0] === 'teacher' ? Student::whereKey($t[1])->value('user_id') : $s?->teacher?->user_id;
        if ($to) {
            \App\Models\Notification::create(['user_id' => $to, 'type' => 'info', 'title' => "Private comment from {$request->user()->name}",
                'message' => mb_substr($data['body'], 0, 300)]);
        }

        return response()->json(['comment' => ['id' => $c->id, 'body' => $c->body, 'author' => $request->user()->name,
            'author_id' => $c->author_id, 'created_at' => $c->created_at]], 201);
    }

    // ─── e-Lab (PhET simulations) ─────────────────────────────────────────

    /** GET /elab/catalog */
    public function catalog(): JsonResponse
    {
        return response()->json(['simulations' => PhetCatalog::all(), 'attribution' => PhetCatalog::ATTRIBUTION]);
    }

    /** POST /classroom/{subject}/labs */
    public function storeLab(Request $request, int $subject): JsonResponse
    {
        $s = Subject::findOrFail($subject);
        if ($this->roleIn($request, $s) !== 'teacher') {
            return $this->teacherOnly();
        }
        $data = $request->validate([
            'sim_slug' => ['required', 'string', fn ($a, $v, $fail) => PhetCatalog::has($v) ? null : $fail('Choose a simulation from the e-Lab catalog.')],
            'title' => 'nullable|string|max:255',
            'instructions' => 'nullable|string|max:20000',
            'questions' => 'nullable|array|max:20',
            'questions.*' => 'string|max:1000',
            'due_at' => 'nullable|date',
            'topic_id' => 'nullable|integer',
        ]);
        $lab = ElabActivity::create([
            'subject_id' => $s->id, 'sim_slug' => $data['sim_slug'],
            'title' => ($data['title'] ?? null) ?: PhetCatalog::find($data['sim_slug'])['title'],
            'instructions' => $data['instructions'] ?? null,
            'questions' => array_values(array_filter($data['questions'] ?? [], fn ($q) => trim($q) !== '')),
            'due_at' => $data['due_at'] ?? null,
            'topic_id' => ! empty($data['topic_id']) && ClassroomTopic::where('subject_id', $s->id)->whereKey($data['topic_id'])->exists() ? $data['topic_id'] : null,
            'author_id' => $request->user()->id,
        ]);
        $this->notifyClass($s, "New e-Lab in {$s->name}", $lab->title);

        return response()->json(['message' => 'Lab created', 'lab' => $lab], 201);
    }

    /** GET /elab/{lab}: the activity, the simulation, and the student's submission or the teacher's list. */
    public function showLab(Request $request, int $lab): JsonResponse
    {
        $l = ElabActivity::findOrFail($lab);
        $s = Subject::with('class:id,name')->findOrFail($l->subject_id);
        if (! $role = $this->roleIn($request, $s)) {
            return $this->deny();
        }
        $out = [
            'role' => $role,
            'lab' => $l->only(['id', 'subject_id', 'topic_id', 'sim_slug', 'title', 'instructions', 'questions', 'due_at', 'created_at']),
            'classroom' => ['id' => $s->id, 'name' => $s->name, 'class' => $s->class?->name],
            'sim' => PhetCatalog::find($l->sim_slug),
            'attribution' => PhetCatalog::ATTRIBUTION,
        ];

        if ($role === 'student') {
            $out['submission'] = ElabSubmission::where('activity_id', $l->id)->where('student_id', $this->studentOf($request->user())->id)->first();
        } elseif ($role === 'teacher') {
            $subs = ElabSubmission::where('activity_id', $l->id)->get()->keyBy('student_id');
            $out['submissions'] = Student::where('class_id', $s->class_id)->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
                ->map(fn ($st) => ['student_id' => $st->id, 'name' => trim("{$st->first_name} {$st->last_name}"), 'submission' => $subs[$st->id] ?? null]);
        }

        return response()->json($out);
    }

    /** POST /elab/{lab}/submit (students): answers to the lab's questions plus free observations. */
    public function submitLab(Request $request, int $lab): JsonResponse
    {
        $l = ElabActivity::findOrFail($lab);
        if ($this->roleIn($request, Subject::findOrFail($l->subject_id)) !== 'student') {
            return response()->json(['message' => 'Only students in this class can submit.'], 403);
        }
        $data = $request->validate(['answers' => 'nullable|array', 'answers.*' => 'nullable|string|max:5000', 'observations' => 'nullable|string|max:20000']);
        $student = $this->studentOf($request->user());
        $existing = ElabSubmission::where('activity_id', $l->id)->where('student_id', $student->id)->first();
        if ($existing && $existing->score !== null) {
            return response()->json(['message' => 'This lab has already been graded.'], 422);
        }

        $sub = ElabSubmission::updateOrCreate(['activity_id' => $l->id, 'student_id' => $student->id], [
            'answers' => array_values($data['answers'] ?? []), 'observations' => $data['observations'] ?? null, 'submitted_at' => now(),
        ]);

        return response()->json(['message' => 'Lab submitted', 'submission' => $sub], 201);
    }

    /** POST /elab/{lab}/grade {student_id, score, feedback} */
    public function gradeLab(Request $request, int $lab): JsonResponse
    {
        $l = ElabActivity::findOrFail($lab);
        if ($this->roleIn($request, Subject::findOrFail($l->subject_id)) !== 'teacher') {
            return $this->teacherOnly();
        }
        $data = $request->validate(['student_id' => 'required|integer', 'score' => 'required|numeric|min:0|max:1000', 'feedback' => 'nullable|string|max:5000']);
        $sub = ElabSubmission::where('activity_id', $l->id)->where('student_id', $data['student_id'])->firstOrFail();
        $sub->update(['score' => $data['score'], 'feedback' => $data['feedback'] ?? null, 'graded_by' => $request->user()->id]);

        if ($uid = Student::whereKey($data['student_id'])->value('user_id')) {
            \App\Models\Notification::create(['user_id' => $uid, 'type' => 'success', 'title' => "Lab graded: {$l->title}", 'message' => "Score: {$data['score']}"]);
        }

        return response()->json(['message' => 'Graded', 'submission' => $sub]);
    }

    /** DELETE /elab/{lab} */
    public function deleteLab(Request $request, int $lab): JsonResponse
    {
        $l = ElabActivity::findOrFail($lab);
        if ($this->roleIn($request, Subject::findOrFail($l->subject_id)) !== 'teacher') {
            return $this->teacherOnly();
        }
        ElabSubmission::where('activity_id', $l->id)->delete();
        ClassroomComment::where('work_type', 'lab')->where('work_id', $l->id)->delete();
        $l->delete();

        return response()->json(['message' => 'Lab removed']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    /** In-app notification to every student (with a login) in the classroom's class. */
    private function notifyClass(Subject $s, string $title, ?string $message): void
    {
        $now = now();
        $rows = Student::where('class_id', $s->class_id)->whereNotNull('user_id')->pluck('user_id')->unique()
            ->map(fn ($uid) => ['user_id' => $uid, 'type' => 'info', 'title' => $title, 'message' => $message ?? '', 'is_read' => false,
                'created_at' => $now, 'updated_at' => $now])->values()->all();
        try {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('notifications')->insert($chunk);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
