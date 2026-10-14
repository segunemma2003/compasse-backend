<?php

namespace Tests\Feature;

use App\Http\Controllers\StoryFeedController;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Class Story is only seen by that class's families; teachers post only to their own classes. */
class StoryFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $tables = [
            'users' => fn ($t) => [$t->id(), $t->string('name'), $t->string('email'), $t->string('password'), $t->string('role'), $t->string('profile_picture')->nullable(), $t->timestamps(), $t->softDeletes()],
            'schools' => fn ($t) => [$t->id(), $t->string('name')->nullable(), $t->timestamps()],
            'classes' => fn ($t) => [$t->id(), $t->string('name'), $t->unsignedBigInteger('class_teacher_id')->nullable(), $t->timestamps()],
            'students' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->unsignedBigInteger('class_id')->nullable(), $t->string('first_name'), $t->string('last_name'), $t->timestamps(), $t->softDeletes()],
            'teachers' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->timestamps(), $t->softDeletes()],
            'guardians' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->string('first_name')->nullable(), $t->timestamps()],
            'guardian_students' => fn ($t) => [$t->id(), $t->unsignedBigInteger('guardian_id'), $t->unsignedBigInteger('student_id'), $t->string('relationship')->nullable(), $t->boolean('is_primary')->default(false), $t->boolean('emergency_contact')->default(false), $t->timestamps()],
            'teacher_subjects' => fn ($t) => [$t->id(), $t->unsignedBigInteger('teacher_id'), $t->unsignedBigInteger('subject_id'), $t->unsignedBigInteger('class_id')->nullable(), $t->string('status')->default('active'), $t->timestamps()],
            'subjects' => fn ($t) => [$t->id(), $t->unsignedBigInteger('class_id')->nullable(), $t->string('name'), $t->unsignedBigInteger('teacher_id')->nullable(), $t->timestamps()],
            'notifications' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->string('title')->nullable(), $t->text('message')->nullable(), $t->string('type')->nullable(), $t->boolean('is_read')->default(false), $t->timestamps()],
            'stories' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->unsignedBigInteger('user_id'), $t->string('title')->nullable(), $t->text('content')->nullable(), $t->string('type')->default('text'),
                $t->json('media')->nullable(), $t->string('thumbnail')->nullable(), $t->string('visibility')->default('public'), $t->json('visible_to_classes')->nullable(), $t->boolean('is_pinned')->default(false),
                $t->timestamp('expires_at')->nullable(), $t->boolean('is_active')->default(true), $t->boolean('allow_comments')->default(true), $t->boolean('allow_reactions')->default(true),
                $t->unsignedInteger('views_count')->default(0), $t->unsignedInteger('reactions_count')->default(0), $t->unsignedInteger('comments_count')->default(0), $t->unsignedInteger('shares_count')->default(0),
                $t->json('tags')->nullable(), $t->string('category')->nullable(), $t->timestamps(), $t->softDeletes()],
            'story_reactions' => fn ($t) => [$t->id(), $t->unsignedBigInteger('story_id'), $t->unsignedBigInteger('user_id'), $t->string('reaction_type'), $t->timestamps()],
            'story_comments' => fn ($t) => [$t->id(), $t->unsignedBigInteger('story_id'), $t->unsignedBigInteger('user_id'), $t->unsignedBigInteger('parent_id')->nullable(), $t->text('comment'), $t->boolean('is_approved')->default(true), $t->timestamps(), $t->softDeletes()],
        ];
        foreach ($tables as $name => $def) {
            Schema::dropIfExists($name);
            Schema::create($name, $def);
        }
        DB::table('schools')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('classes')->insert([['id' => 1, 'name' => 'Toddler One', 'class_teacher_id' => 1], ['id' => 2, 'name' => 'Toddler Two', 'class_teacher_id' => null]]);
        foreach ([[1, 'teacher'], [2, 'guardian'], [3, 'guardian'], [4, 'school_admin']] as [$id, $role]) {
            User::forceCreate(['id' => $id, 'name' => ucfirst($role) . $id, 'email' => "u{$id}@example.com", 'password' => 'x', 'role' => $role]);
        }
        DB::table('teachers')->insert(['id' => 1, 'user_id' => 1]);
        DB::table('students')->insert([['id' => 1, 'class_id' => 1, 'first_name' => 'Ada', 'last_name' => 'O'], ['id' => 2, 'class_id' => 2, 'first_name' => 'Ben', 'last_name' => 'E']]);
        DB::table('guardians')->insert([['id' => 1, 'user_id' => 2], ['id' => 2, 'user_id' => 3]]);
        DB::table('guardian_students')->insert([['guardian_id' => 1, 'student_id' => 1], ['guardian_id' => 2, 'student_id' => 2]]);
    }

    private function req(int $uid, string $method = 'GET', array $data = []): Request
    {
        $u = User::find($uid);
        $this->actingAs($u);
        $r = Request::create('/', $method, $data);
        $r->setUserResolver(fn () => $u);

        return $r;
    }

    public function test_class_story_reaches_only_that_class(): void
    {
        $c = new StoryFeedController();
        $res = $c->post($this->req(1, 'POST', ['scope' => 'class', 'class_ids' => [1], 'content' => 'Painting day!', 'media' => ['https://example.com/a.jpg']]));
        $this->assertSame(201, $res->getStatusCode(), $res->getContent());
        $this->assertSame(1, DB::table('notifications')->where('user_id', 2)->count(), 'the class parent is told');
        $this->assertSame(0, DB::table('notifications')->where('user_id', 3)->count());

        $this->assertCount(1, $c->feed($this->req(2, 'GET', ['scope' => 'class']))->getData(true)['stories']);
        $this->assertCount(0, $c->feed($this->req(3, 'GET', ['scope' => 'class']))->getData(true)['stories'], 'another class does not see it');
        $this->assertCount(1, $c->feed($this->req(4, 'GET', ['scope' => 'class']))->getData(true)['stories'], 'leadership sees every class');
    }

    public function test_teachers_post_only_to_their_classes_and_not_the_school_story(): void
    {
        $c = new StoryFeedController();
        $this->assertSame(403, $c->post($this->req(1, 'POST', ['scope' => 'class', 'class_ids' => [2], 'content' => 'x']))->getStatusCode());
        $this->assertSame(403, $c->post($this->req(1, 'POST', ['scope' => 'school', 'content' => 'x']))->getStatusCode());
        $this->assertSame(201, $c->post($this->req(4, 'POST', ['scope' => 'school', 'content' => 'Sports day on Friday']))->getStatusCode());
        $this->assertCount(1, $c->feed($this->req(3, 'GET', ['scope' => 'school']))->getData(true)['stories']);
    }

    public function test_like_toggles(): void
    {
        $story = Story::create(['school_id' => 1, 'user_id' => 4, 'content' => 'Hi', 'type' => 'text', 'visibility' => 'public', 'is_active' => true]);
        $c = new StoryFeedController();
        $this->assertTrue($c->like($this->req(2, 'POST'), $story)->getData(true)['liked']);
        $this->assertFalse($c->like($this->req(2, 'POST'), $story)->getData(true)['liked']);
    }
}
