<?php

namespace App\Http\Controllers;

use App\Models\Guardian;
use App\Models\Story;
use App\Models\StoryReaction;
use App\Models\Student;
use App\Models\User;
use App\Support\ClassTeachers;
use App\Support\UserEffectiveRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * School Story and Class Story (ClassDojo style), built on the stories tables.
 *
 * - School story: posts for the whole school community (visibility public / parents / students / staff).
 * - Class story: photos and updates for one or more classes (visibility class_specific), seen by
 *   that class's parents, students and teachers.
 *
 * Teachers post to their own classes; school leadership posts anywhere.
 */
class StoryFeedController extends Controller
{
    private const LEADERSHIP = ['super_admin', 'school_admin', 'principal', 'vice_principal', 'admin'];
    private const TEACHERS = ['teacher', 'class_teacher', 'subject_teacher', 'year_tutor', 'hod'];

    private function roles(User $u): array
    {
        return array_unique(array_merge([$u->role], UserEffectiveRoles::forUser($u)));
    }

    private function isLeadership(User $u): bool
    {
        return (bool) array_intersect($this->roles($u), self::LEADERSHIP);
    }

    /** Classes whose class story this user sees (null = all). */
    private function viewerClassIds(User $u): ?array
    {
        if ($this->isLeadership($u)) {
            return null;
        }
        if ($student = Student::where('user_id', $u->id)->first()) {
            return array_filter([(int) $student->class_id]);
        }
        if ($guardian = Guardian::where('user_id', $u->id)->first()) {
            return $guardian->students()->pluck('students.class_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        }

        return $this->postableClassIds($u) ?? [];
    }

    /** Classes this user may post a class story to (null = any). */
    private function postableClassIds(User $u): ?array
    {
        if ($this->isLeadership($u)) {
            return null;
        }
        $ids = $this->accessibleClassIds($u) ?? [];
        if ($u->teacher) {
            $ids = array_merge($ids, ClassTeachers::classIdsFor((int) $u->teacher->id));
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** School-wide visibilities this user may see. */
    private function schoolVisibilities(User $u): array
    {
        $roles = $this->roles($u);
        if ($this->isLeadership($u)) {
            return ['public', 'students', 'staff', 'parents', 'guardians', 'teachers', 'admin_only'];
        }
        if (array_intersect($roles, ['guardian', 'parent'])) {
            return ['public', 'parents', 'guardians'];
        }
        if (in_array('student', $roles, true)) {
            return ['public', 'students'];
        }
        $v = ['public', 'staff'];
        if (array_intersect($roles, self::TEACHERS)) {
            $v[] = 'teachers';
        }

        return $v;
    }

    /** GET /stories/feed?scope=school|class&class_id=&page= */
    public function feed(Request $request): JsonResponse
    {
        $u = $request->user();
        $scope = $request->input('scope', 'school') === 'class' ? 'class' : 'school';
        $q = Story::with(['author:id,name,role,profile_picture', 'comments' => fn ($c) => $c->with('user:id,name')->latest()->limit(20)])
            ->active()->orderByDesc('is_pinned')->orderByDesc('created_at');

        if ($scope === 'school') {
            $q->whereIn('visibility', $this->schoolVisibilities($u));
        } else {
            $q->where('visibility', 'class_specific');
            $classIds = $this->viewerClassIds($u);
            if ($request->filled('class_id')) {
                $cid = (int) $request->class_id;
                if ($classIds !== null && ! in_array($cid, $classIds, true)) {
                    return response()->json(['stories' => [], 'classes' => []]);
                }
                $classIds = [$cid];
            }
            if ($classIds !== null) {
                if (! $classIds) {
                    return response()->json(['stories' => [], 'classes' => [], 'can_post' => $this->postableClassIds($u) !== []]);
                }
                $q->where(function ($w) use ($classIds) {
                    foreach ($classIds as $cid) {
                        $w->orWhereJsonContains('visible_to_classes', $cid);
                    }
                });
            }
        }

        $page = $q->paginate(min(50, (int) $request->input('per_page', 15)));
        $storyIds = collect($page->items())->pluck('id');
        $liked = StoryReaction::where('user_id', $u->id)->whereIn('story_id', $storyIds)->pluck('story_id')->all();

        // Seeing a post in the feed counts as viewing it (once per person; the author doesn't count).
        if (\Illuminate\Support\Facades\Schema::hasTable('story_views')) {
            $now = now();
            DB::table('story_views')->insertOrIgnore(collect($page->items())
                ->filter(fn ($st) => (int) $st->user_id !== (int) $u->id)
                ->map(fn ($st) => ['story_id' => $st->id, 'user_id' => $u->id, 'viewed_at' => $now, 'created_at' => $now, 'updated_at' => $now])
                ->values()->all());
            $views = DB::table('story_views')->whereIn('story_id', $storyIds)->groupBy('story_id')->pluck(DB::raw('COUNT(*)'), 'story_id');
        } else {
            $views = collect();
        }
        $leader = $this->isLeadership($u);
        $classNames = DB::table('classes')->pluck('name', 'id');

        $postable = $this->postableClassIds($u);
        $viewable = $this->viewerClassIds($u);

        return response()->json([
            'stories' => collect($page->items())->map(fn (Story $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'content' => $s->content,
                'media' => array_values(array_filter((array) ($s->media ?? []))),
                'type' => $s->type,
                'pinned' => (bool) $s->is_pinned,
                'author' => $s->author?->name,
                'author_role' => $s->author?->role,
                'author_id' => $s->user_id,
                'classes' => collect($s->visible_to_classes ?? [])->map(fn ($id) => $classNames[$id] ?? null)->filter()->values(),
                'created_at' => $s->created_at,
                'likes' => (int) $s->reactions_count,
                'views' => (int) ($views[$s->id] ?? 0),
                'can_see_viewers' => $leader || (int) $s->user_id === (int) $u->id,
                'liked' => in_array($s->id, $liked, true),
                'allow_comments' => (bool) $s->allow_comments,
                'comments' => $s->comments->sortBy('created_at')->values()->map(fn ($c) => [
                    'id' => $c->id, 'body' => $c->comment, 'author' => $c->user?->name, 'author_id' => $c->user_id, 'created_at' => $c->created_at,
                ]),
            ]),
            'has_more' => $page->hasMorePages(),
            'can_post' => $scope === 'school' ? $this->isLeadership($u) : $postable === null || $postable !== [],
            'classes' => DB::table('classes')
                ->when($scope === 'class' && $viewable !== null, fn ($c) => $c->whereIn('id', $viewable ?: [0]))
                ->orderBy('name')->get(['id', 'name']),
            'post_classes' => $postable === null
                ? DB::table('classes')->orderBy('name')->get(['id', 'name'])
                : DB::table('classes')->whereIn('id', $postable ?: [0])->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** POST /stories/post {scope: school|class, class_ids?: [], title?, content?, media?: [url], audience?} */
    public function post(Request $request): JsonResponse
    {
        $u = $request->user();
        $data = $request->validate([
            'scope' => 'required|in:school,class',
            'class_ids' => 'required_if:scope,class|array',
            'class_ids.*' => 'integer|exists:classes,id',
            'title' => 'nullable|string|max:255',
            'content' => 'nullable|string|max:10000',
            'media' => 'nullable|array|max:10',
            'media.*' => 'string|max:2000',
            'audience' => 'nullable|in:public,parents,students,staff',
        ]);
        if (empty($data['content']) && empty($data['media']) && empty($data['title'])) {
            return response()->json(['message' => 'Write something or add a photo.'], 422);
        }

        if ($data['scope'] === 'school') {
            if (! $this->isLeadership($u)) {
                return response()->json(['message' => 'Only school leadership can post to the School Story.'], 403);
            }
        } else {
            $allowed = $this->postableClassIds($u);
            $ids = array_map('intval', $data['class_ids']);
            if ($allowed !== null && array_diff($ids, $allowed)) {
                return response()->json(['message' => 'You can only post to your own classes.'], 403);
            }
        }

        $story = Story::create([
            'school_id' => $this->getSchoolIdFromTenant($request),
            'user_id' => $u->id,
            'title' => $data['title'] ?? null,
            'content' => $data['content'] ?? null,
            'type' => ! empty($data['media']) ? 'photo' : 'text',
            'media' => $data['media'] ?? [],
            'visibility' => $data['scope'] === 'school' ? ($data['audience'] ?? 'public') : 'class_specific',
            'visible_to_classes' => $data['scope'] === 'class' ? array_values(array_map('intval', $data['class_ids'])) : null,
            'is_active' => true,
            'allow_comments' => true,
            'allow_reactions' => true,
        ]);

        $this->notifyParents($story, $u);

        return response()->json(['message' => 'Posted', 'story_id' => $story->id], 201);
    }

    /** POST /stories/{story}/like — toggle a like. */
    public function like(Request $request, Story $story): JsonResponse
    {
        $existing = StoryReaction::where('story_id', $story->id)->where('user_id', $request->user()->id)->first();
        if ($existing) {
            $existing->delete();
            $story->decrement('reactions_count');
        } else {
            StoryReaction::create(['story_id' => $story->id, 'user_id' => $request->user()->id, 'reaction_type' => 'like']);
            $story->increment('reactions_count');
        }

        return response()->json(['liked' => ! $existing, 'likes' => (int) $story->fresh()->reactions_count]);
    }

    /** GET /stories/{story}/viewers — who has seen the post (the author and leadership). */
    public function viewers(Request $request, Story $story): JsonResponse
    {
        $u = $request->user();
        if ((int) $story->user_id !== (int) $u->id && ! $this->isLeadership($u)) {
            return response()->json(['message' => 'Only the person who posted can see who viewed it.'], 403);
        }
        $rows = DB::table('story_views')->join('users', 'users.id', '=', 'story_views.user_id')
            ->where('story_views.story_id', $story->id)->orderByDesc('story_views.viewed_at')
            ->get(['users.id', 'users.name', 'users.role', 'story_views.viewed_at']);
        $liked = StoryReaction::where('story_id', $story->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        return response()->json(['viewers' => $rows->map(fn ($r) => [
            'id' => $r->id, 'name' => $r->name, 'role' => $r->role, 'viewed_at' => $r->viewed_at, 'liked' => in_array((int) $r->id, $liked, true),
        ])]);
    }

    /** DELETE /stories/{story}/remove — the author or leadership. */
    public function remove(Request $request, Story $story): JsonResponse
    {
        $u = $request->user();
        if ((int) $story->user_id !== (int) $u->id && ! $this->isLeadership($u)) {
            return response()->json(['message' => 'You can only delete your own posts.'], 403);
        }
        $story->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /** In-app notification to the parents of the story's classes (or to every parent for a school story). */
    private function notifyParents(Story $story, User $author): void
    {
        try {
            $guardianUserIds = $story->visibility === 'class_specific'
                ? DB::table('guardians')->join('guardian_students', 'guardian_students.guardian_id', '=', 'guardians.id')
                    ->join('students', 'students.id', '=', 'guardian_students.student_id')
                    ->whereIn('students.class_id', $story->visible_to_classes ?? [])->whereNotNull('guardians.user_id')
                    ->distinct()->pluck('guardians.user_id')
                : (in_array($story->visibility, ['public', 'parents', 'guardians'], true)
                    ? DB::table('guardians')->whereNotNull('user_id')->pluck('user_id') : collect());
            $title = $story->visibility === 'class_specific' ? 'New class story' : 'New school story';
            $message = $author->name . ': ' . mb_substr(trim($story->title ?: (string) $story->content) ?: 'shared photos', 0, 160);
            $now = now();
            foreach ($guardianUserIds->unique()->chunk(500) as $chunk) {
                DB::table('notifications')->insert($chunk->map(fn ($id) => [
                    'user_id' => $id, 'type' => 'info', 'title' => $title, 'message' => $message, 'is_read' => false, 'created_at' => $now, 'updated_at' => $now,
                ])->values()->all());
            }
            try {
                $this->emailParents($guardianUserIds->unique()->values()->all(), $story, $author, $title);
            } catch (\Throwable $e) {
                report($e);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Email each parent (real addresses only) with the post and a link to see it. */
    private function emailParents(array $userIds, Story $story, User $author, string $title): void
    {
        if (! $userIds) {
            return;
        }
        $sub = null;
        try {
            $sub = tenant()?->subdomain;
        } catch (\Throwable) {
        }
        $link = $sub ? 'https://' . $sub . '.' . config('app.root_domain', 'compasse.net') . '/school/stories?tab=' . ($story->visibility === 'class_specific' ? 'class' : 'school') : '';
        $classes = $story->visibility === 'class_specific'
            ? DB::table('classes')->whereIn('id', $story->visible_to_classes ?? [])->pluck('name')->implode(', ') : '';
        $school = \App\Models\School::first();
        $photos = count((array) ($story->media ?? []));
        $body = $author->name . ' posted' . ($classes ? " in {$classes}" : '') . ":\n\n"
            . trim(($story->title ? $story->title . "\n" : '') . (string) $story->content)
            . ($photos ? "\n\n({$photos} photo" . ($photos === 1 ? '' : 's') . ')' : '')
            . ($link ? "\n\nSee it on the parent portal: {$link}" : '') . "\n\n" . ($school?->name ?? '');

        foreach (User::whereIn('id', $userIds)->get(['id', 'email', 'email_generated']) as $p) {
            if (! \App\Support\EmailAddress::isReal($p->email) || \App\Support\EmailAddress::isPlaceholder($p->email) || $p->email_generated) {
                continue;
            }
            \App\Jobs\SendEmailJob::dispatch($p->email, $title . ' — ' . ($school?->name ?? 'school'), $body, [], [],
                $school ? (string) $school->id : null, false, 'story');
        }
    }
}
