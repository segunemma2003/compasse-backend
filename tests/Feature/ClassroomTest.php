<?php

namespace Tests\Feature;

use App\Http\Controllers\ClassroomController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Classroom membership, the student to-do buckets, private comment threads, and e-Lab grading. */
class ClassroomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('users');
        Schema::create('users', function ($t) {
            $t->id(); $t->string('name'); $t->string('email')->unique(); $t->string('password'); $t->string('role'); $t->timestamps(); $t->softDeletes();
        });
        foreach (['students', 'teachers', 'guardians'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, function ($t) {
                $t->id(); $t->unsignedBigInteger('user_id')->nullable(); $t->unsignedBigInteger('class_id')->nullable();
                $t->string('first_name'); $t->string('last_name'); $t->string('admission_number')->nullable(); $t->string('email')->nullable();
                $t->string('status')->nullable(); $t->timestamps(); $t->softDeletes();
            });
        }
        Schema::dropIfExists('guardian_students');
        Schema::create('guardian_students', fn ($t) => [$t->id(), $t->unsignedBigInteger('guardian_id'), $t->unsignedBigInteger('student_id'),
            $t->string('relationship')->nullable(), $t->boolean('is_primary')->default(false), $t->boolean('emergency_contact')->default(false), $t->timestamps()]);
        Schema::dropIfExists('classes');
        Schema::create('classes', fn ($t) => [$t->id(), $t->string('name'), $t->unsignedBigInteger('class_teacher_id')->nullable(), $t->timestamps()]);
        Schema::dropIfExists('subjects');
        Schema::create('subjects', fn ($t) => [$t->id(), $t->unsignedBigInteger('class_id')->nullable(), $t->string('name'), $t->string('code')->nullable(),
            $t->text('description')->nullable(), $t->unsignedBigInteger('teacher_id')->nullable(), $t->string('status')->nullable(), $t->timestamps()]);
        Schema::dropIfExists('teacher_subjects');
        Schema::create('teacher_subjects', fn ($t) => [$t->id(), $t->unsignedBigInteger('teacher_id'), $t->unsignedBigInteger('subject_id'),
            $t->unsignedBigInteger('class_id')->nullable(), $t->string('status')->default('active'), $t->timestamps()]);
        Schema::dropIfExists('assignments');
        Schema::create('assignments', fn ($t) => [$t->id(), $t->unsignedBigInteger('subject_id'), $t->unsignedBigInteger('class_id')->nullable(), $t->string('title'),
            $t->text('description')->nullable(), $t->dateTime('due_date')->nullable(), $t->integer('total_marks')->nullable(), $t->string('status'), $t->timestamps()]);
        Schema::dropIfExists('assignment_submissions');
        Schema::create('assignment_submissions', fn ($t) => [$t->id(), $t->unsignedBigInteger('assignment_id'), $t->unsignedBigInteger('student_id'),
            $t->dateTime('submitted_at')->nullable(), $t->string('status')->nullable(), $t->decimal('marks_obtained', 6, 2)->nullable(), $t->timestamps()]);
        Schema::dropIfExists('notifications');
        Schema::create('notifications', function ($t) {
            $t->id(); $t->unsignedBigInteger('user_id')->nullable(); $t->string('title')->nullable(); $t->text('message')->nullable();
            $t->string('type')->nullable(); $t->boolean('is_read')->default(false); $t->timestamps();
        });
        (require database_path('migrations/tenant/2026_10_13_000006_create_classroom_tables.php'))->up();
        (require database_path('migrations/tenant/2026_10_25_000001_elab_practical_settings_and_results.php'))->up();

        // JSS1 Basic Science (subject 1, taught by teacher 1) and JSS2 Maths (subject 2).
        DB::table('classes')->insert([['id' => 1, 'name' => 'JSS1'], ['id' => 2, 'name' => 'JSS2']]);
        DB::table('subjects')->insert([['id' => 1, 'class_id' => 1, 'name' => 'Basic Science'], ['id' => 2, 'class_id' => 2, 'name' => 'Maths']]);
        $this->user('teacher', 10);
        DB::table('teachers')->insert(['id' => 1, 'user_id' => 10, 'first_name' => 'Tola', 'last_name' => 'T']);
        DB::table('teacher_subjects')->insert(['teacher_id' => 1, 'subject_id' => 1]);
        $this->user('student', 20);
        $this->user('student', 21);
        DB::table('students')->insert([
            ['id' => 1, 'user_id' => 20, 'class_id' => 1, 'first_name' => 'Ada', 'last_name' => 'O'],
            ['id' => 2, 'user_id' => 21, 'class_id' => 2, 'first_name' => 'Ben', 'last_name' => 'E'],
        ]);
        $this->user('guardian', 30);
        DB::table('guardians')->insert(['id' => 1, 'user_id' => 30, 'first_name' => 'Mama', 'last_name' => 'O']);
        DB::table('guardian_students')->insert(['guardian_id' => 1, 'student_id' => 1]);
    }

    private function user(string $role, int $id): User
    {
        return User::forceCreate(['id' => $id, 'name' => ucfirst($role) . $id, 'email' => "{$role}{$id}@example.com", 'password' => 'x', 'role' => $role]);
    }

    private function req(int $userId, string $method = 'GET', array $data = []): Request
    {
        $u = User::find($userId);
        $this->actingAs($u);
        $r = Request::create('/', $method, $data);
        $r->setUserResolver(fn () => $u);

        return $r;
    }

    public function test_membership_decides_who_posts_reads_and_comments(): void
    {
        $c = new ClassroomController();

        $post = $c->storePost($this->req(10, 'POST', ['type' => 'announcement', 'body' => 'Bring your lab coats']), 1);
        $this->assertSame(201, $post->getStatusCode(), $post->getContent());
        $this->assertSame(1, DB::table('notifications')->where('user_id', 20)->count(), 'the class is notified');

        $this->assertSame(403, $c->storePost($this->req(20, 'POST', ['type' => 'announcement', 'body' => 'hi']), 1)->getStatusCode(), 'students cannot post');
        $this->assertSame(403, $c->stream($this->req(21), 1)->getStatusCode(), 'another class is kept out');
        $this->assertSame(403, $c->storePost($this->req(10, 'POST', ['type' => 'announcement', 'body' => 'x']), 2)->getStatusCode(), 'not their subject');

        $this->assertCount(1, $c->stream($this->req(30), 1)->getData(true)['posts'], 'parents read the stream');
        $postId = $post->getData(true)['post']['id'];
        $this->assertSame(403, $c->storeComment($this->req(30, 'POST', ['body' => 'Thanks']), $postId)->getStatusCode(), 'parents are read-only');
        $this->assertSame(201, $c->storeComment($this->req(20, 'POST', ['body' => 'Okay ma']), $postId)->getStatusCode());

        $this->assertSame(['Basic Science'], collect($c->index($this->req(10))->getData(true)['classrooms'])->pluck('name')->all());
        $this->assertSame(['Basic Science'], collect($c->index($this->req(20))->getData(true)['classrooms'])->pluck('name')->all());
    }

    public function test_todo_sorts_work_into_assigned_missing_and_done(): void
    {
        DB::table('assignments')->insert([
            ['id' => 1, 'subject_id' => 1, 'title' => 'Overdue essay', 'due_date' => now()->subDay(), 'status' => 'published'],
            ['id' => 2, 'subject_id' => 1, 'title' => 'Next week', 'due_date' => now()->addWeek(), 'status' => 'published'],
            ['id' => 3, 'subject_id' => 1, 'title' => 'Draft only', 'due_date' => now()->addWeek(), 'status' => 'draft'],
        ]);
        $c = new ClassroomController();
        $lab = $c->storeLab($this->req(10, 'POST', ['sim_slug' => 'states-of-matter-basics', 'questions' => ['What happens when you heat ice?']]), 1);
        $this->assertSame(201, $lab->getStatusCode(), $lab->getContent());
        $labId = $lab->getData(true)['lab']['id'];
        $this->assertSame(201, $c->submitLab($this->req(20, 'POST', ['answers' => ['It melts']]), $labId)->getStatusCode());

        $todo = $c->todo($this->req(20))->getData(true);
        $this->assertSame(['Next week'], array_column($todo['assigned'], 'title'));
        $this->assertSame(['Overdue essay'], array_column($todo['missing'], 'title'));
        $this->assertSame(['States of Matter: Basics'], array_column($todo['done'], 'title'));

        $child = $c->myChildren($this->req(30))->getData(true)['children'][0];
        $this->assertSame(['Overdue essay'], array_column($child['missing'], 'title'), 'parents see missing work');

        $this->assertSame(422, $this->statusOf(fn () => $c->storeLab($this->req(10, 'POST', ['sim_slug' => 'not-a-sim']), 1)));

        // A practical with the teacher's set-up; the student's bench results go in with the submission.
        $p = $c->storeLab($this->req(10, 'POST', ['sim_slug' => 'practical-titration', 'settings' => ['base' => 'carbonate', 'acid' => '0.100']]), 1)->getData(true)['lab'];
        $this->assertSame('Acid–base titration (practical)', $p['title']);
        $shown = $c->showLab($this->req(20), $p['id'])->getData(true);
        $this->assertSame('carbonate', $shown['lab']['settings']['base']);
        $this->assertSame('practical', $shown['sim']['type']);
        $results = ['practical' => 'titration', 'settings' => ['base' => 'carbonate'], 'log' => [['t' => 1, 'text' => 'Effervescence']],
            'tables' => [['caption' => 'Burette readings', 'columns' => ['', 'Rough'], 'rows' => [['Final', '25.10']]]], 'answers' => [['label' => 'Concentration', 'value' => '0.1', 'correct' => true]]];
        $c->submitLab($this->req(20, 'POST', ['answers' => [], 'results' => $results]), $p['id']);
        $teacher = $c->showLab($this->req(10), $p['id'])->getData(true);
        $sub = collect($teacher['submissions'])->firstWhere('student_id', 1)['submission'] ?? collect($teacher['submissions'])->pluck('submission')->filter()->first();
        $this->assertSame('25.10', $sub['results']['tables'][0]['rows'][0][1], 'teacher sees the readings');
    }

    public function test_private_comments_stay_between_one_student_and_the_teachers(): void
    {
        DB::table('assignments')->insert(['id' => 1, 'subject_id' => 1, 'title' => 'Essay', 'status' => 'published']);
        DB::table('students')->insert(['id' => 3, 'user_id' => 22, 'class_id' => 1, 'first_name' => 'Chi', 'last_name' => 'K']);
        $this->user('student', 22);
        $c = new ClassroomController();

        $this->assertSame(201, $c->storePrivateComment($this->req(20, 'POST', ['body' => 'Can I submit late?']), 'assignment', 1)->getStatusCode());
        $this->assertSame(201, $c->storePrivateComment($this->req(10, 'POST', ['body' => 'Yes, by Friday', 'student_id' => 1]), 'assignment', 1)->getStatusCode());

        $this->assertCount(2, $c->privateComments($this->req(20), 'assignment', 1)->getData(true)['comments']);
        $this->assertCount(0, $c->privateComments($this->req(22, 'GET', ['student_id' => 1]), 'assignment', 1)->getData(true)['comments'], 'a classmate cannot read it');
        $this->assertSame(403, $c->privateComments($this->req(30), 'assignment', 1)->getStatusCode());
    }

    public function test_teachers_grade_labs_and_graded_labs_are_locked(): void
    {
        $c = new ClassroomController();
        $labId = $c->storeLab($this->req(10, 'POST', ['sim_slug' => 'ph-scale', 'due_at' => now()->addDay()->toDateTimeString()]), 1)->getData(true)['lab']['id'];
        $c->submitLab($this->req(20, 'POST', ['observations' => 'Lemon juice is acidic']), $labId);

        $this->assertSame(200, $c->gradeLab($this->req(10, 'POST', ['student_id' => 1, 'score' => 9, 'feedback' => 'Good']), $labId)->getStatusCode());
        $this->assertSame(422, $c->submitLab($this->req(20, 'POST', ['observations' => 'changed']), $labId)->getStatusCode());

        $show = $c->showLab($this->req(10), $labId)->getData(true);
        $this->assertSame('ph-scale', $show['sim']['slug']);
        $this->assertEquals(9, $show['submissions'][0]['submission']['score']);
    }

    private function statusOf(callable $fn): int
    {
        try {
            return $fn()->getStatusCode();
        } catch (\Illuminate\Validation\ValidationException $e) {
            return 422;
        }
    }
}
