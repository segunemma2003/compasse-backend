<?php

namespace Tests\Feature;

use App\Http\Controllers\ClassController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\SchoolMeetingController;
use App\Models\ClassModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Parents only reach their children's teachers / care staff and the school office, and only see
 * their children's classes — not the rest of the school's children, parents or classes.
 */
class ParentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $tables = [
            'users' => fn ($t) => [$t->id(), $t->string('name'), $t->string('email'), $t->string('password'), $t->string('role'), $t->string('status')->default('active'), $t->boolean('email_generated')->default(false), $t->timestamps(), $t->softDeletes()],
            'messages' => fn ($t) => [$t->id(), $t->unsignedBigInteger('sender_id'), $t->unsignedBigInteger('receiver_id'), $t->string('subject')->nullable(), $t->text('body'), $t->string('type')->nullable(), $t->boolean('is_read')->default(false), $t->timestamp('read_at')->nullable(), $t->timestamps()],
            'notifications' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->string('title')->nullable(), $t->text('message')->nullable(), $t->string('type')->nullable(), $t->boolean('is_read')->default(false), $t->timestamps()],
            'schools' => fn ($t) => [$t->id(), $t->string('name')->nullable(), $t->timestamps()],
            'classes' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('name'), $t->unsignedBigInteger('class_teacher_id')->nullable(), $t->timestamps()],
            'subjects' => fn ($t) => [$t->id(), $t->unsignedBigInteger('class_id')->nullable(), $t->unsignedBigInteger('teacher_id')->nullable(), $t->string('name'), $t->timestamps()],
            'teacher_subjects' => fn ($t) => [$t->id(), $t->unsignedBigInteger('teacher_id'), $t->unsignedBigInteger('subject_id'), $t->unsignedBigInteger('class_id')->nullable(), $t->string('status')->default('active'), $t->timestamps()],
            'teachers' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->string('employee_id')->nullable(), $t->timestamps(), $t->softDeletes()],
            'students' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->unsignedBigInteger('class_id')->nullable(), $t->unsignedBigInteger('arm_id')->nullable(), $t->string('first_name'), $t->string('last_name'), $t->timestamps(), $t->softDeletes()],
            'guardians' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->timestamps()],
            'arms' => fn ($t) => [$t->id(), $t->string('name'), $t->text('description')->nullable(), $t->string('status')->nullable(), $t->timestamps()],
            'class_arm' => fn ($t) => [$t->id(), $t->unsignedBigInteger('class_id'), $t->unsignedBigInteger('arm_id'), $t->unsignedBigInteger('class_teacher_id')->nullable(), $t->integer('capacity')->nullable(), $t->string('status')->nullable(), $t->timestamps()],
            'guardian_students' => fn ($t) => [$t->id(), $t->unsignedBigInteger('guardian_id'), $t->unsignedBigInteger('student_id'), $t->timestamps()],
        ];
        foreach ($tables as $n => $def) {
            Schema::dropIfExists($n);
            Schema::create($n, $def);
        }
        // 1 admin, 2 Ada's class teacher, 3 Ada's subject teacher, 4 another class's teacher,
        // 5 Ada's parent, 6 Ben's parent, 7 a librarian
        foreach ([[1, 'school_admin'], [2, 'teacher'], [3, 'teacher'], [4, 'teacher'], [5, 'guardian'], [6, 'guardian'], [7, 'librarian']] as [$id, $role]) {
            User::forceCreate(['id' => $id, 'name' => "U{$id}", 'email' => "u{$id}@school.test", 'password' => 'x', 'role' => $role]);
        }
        DB::table('teachers')->insert([['id' => 1, 'user_id' => 2], ['id' => 2, 'user_id' => 3], ['id' => 3, 'user_id' => 4]]);
        DB::table('classes')->insert([['id' => 1, 'name' => 'Toddler Two', 'class_teacher_id' => 1], ['id' => 2, 'name' => 'Nursery One', 'class_teacher_id' => 3]]);
        DB::table('subjects')->insert(['id' => 1, 'class_id' => 1, 'teacher_id' => 2, 'name' => 'Phonics']);
        DB::table('students')->insert([['id' => 1, 'class_id' => 1, 'first_name' => 'Ada', 'last_name' => 'O'], ['id' => 2, 'class_id' => 2, 'first_name' => 'Ben', 'last_name' => 'E']]);
        DB::table('guardians')->insert([['id' => 1, 'user_id' => 5], ['id' => 2, 'user_id' => 6]]);
        DB::table('guardian_students')->insert([['guardian_id' => 1, 'student_id' => 1], ['guardian_id' => 2, 'student_id' => 2]]);
    }

    private function req(int $uid, string $method = 'GET', array $data = []): Request
    {
        $u = User::find($uid);
        $this->actingAs($u);
        $r = Request::create('/', $method, $data);
        $r->setUserResolver(fn () => $u);
        app()->instance('request', $r);

        return $r;
    }

    public function test_parent_messages_only_their_childs_staff_and_the_office(): void
    {
        $c = new MessageController();
        $ids = collect($c->contacts($this->req(5))->getData(true)['data'])->pluck('id')->sort()->values()->all();
        $this->assertSame([1, 2, 3], $ids, "class teacher, subject teacher, office — not the other class's teacher, parents or the librarian");

        $send = fn (int $to) => $c->store($this->req(5, 'POST', ['recipient_id' => $to, 'message' => 'Hello']))->getStatusCode();
        $this->assertSame(201, $send(2));
        $this->assertSame(201, $send(1));
        $this->assertSame(403, $send(4), "another class's teacher");
        $this->assertSame(403, $send(6), 'another parent');
        $this->assertSame(403, $send(7), 'unrelated staff');
        $this->assertSame(403, $c->thread($this->req(5), 6)->getStatusCode());

        // Someone who wrote to the parent first can be answered.
        DB::table('messages')->insert(['sender_id' => 7, 'receiver_id' => 5, 'body' => 'Your library book is due', 'created_at' => now()]);
        $this->assertSame(201, $send(7));

        // Staff are not restricted.
        $this->assertSame(201, $c->store($this->req(4, 'POST', ['recipient_id' => 6, 'message' => 'Hi']))->getStatusCode());
    }

    public function test_meeting_directory_and_classes_are_limited_for_parents(): void
    {
        $dir = collect((new SchoolMeetingController())->directory($this->req(5))->getData(true)['users'])->pluck('id')->sort()->values()->all();
        $this->assertSame([1, 2, 3], $dir, 'no other children, parents or unrelated staff');
        $this->assertCount(7, (new SchoolMeetingController())->directory($this->req(1))->getData(true)['users'], 'staff see everyone');

        $res = (new ClassController())->index($this->req(5));
        $classes = collect($res->getData(true)['data'] ?? [])->pluck('id')->all();
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $this->assertSame([1], $classes);
        $this->req(5);
        $this->assertSame(403, (new ClassController())->show(ClassModel::find(2))->getStatusCode());
        $this->assertSame(200, (new ClassController())->show(ClassModel::find(1))->getStatusCode());
    }
}
