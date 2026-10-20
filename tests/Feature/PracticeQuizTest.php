<?php

namespace Tests\Feature;

use App\Http\Controllers\PracticeQuizController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Students only see their class's quizzes, never the answers before submitting, and get marked. */
class PracticeQuizTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $tables = [
            'users' => fn ($t) => [$t->id(), $t->string('name'), $t->string('email'), $t->string('password'), $t->string('role'), $t->timestamps(), $t->softDeletes()],
            'schools' => fn ($t) => [$t->id(), $t->string('name')->nullable(), $t->timestamps()],
            'settings' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('key'), $t->text('value')->nullable(), $t->timestamps()],
            'classes' => fn ($t) => [$t->id(), $t->string('name'), $t->unsignedBigInteger('class_teacher_id')->nullable(), $t->timestamps()],
            'subjects' => fn ($t) => [$t->id(), $t->unsignedBigInteger('class_id')->nullable(), $t->string('name'), $t->timestamps()],
            'students' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->unsignedBigInteger('class_id')->nullable(), $t->string('first_name'), $t->string('last_name'), $t->timestamps(), $t->softDeletes()],
        ];
        foreach ($tables as $n => $def) {
            Schema::dropIfExists($n);
            Schema::create($n, $def);
        }
        (require database_path('migrations/tenant/2026_10_20_000001_create_practice_quizzes.php'))->up();
        DB::table('schools')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('classes')->insert([['id' => 1, 'name' => 'JSS1'], ['id' => 2, 'name' => 'JSS2']]);
        foreach ([[1, 'school_admin'], [2, 'student'], [3, 'student']] as [$id, $role]) {
            User::forceCreate(['id' => $id, 'name' => "U{$id}", 'email' => "u{$id}@e.com", 'password' => 'x', 'role' => $role]);
        }
        DB::table('students')->insert([['id' => 1, 'user_id' => 2, 'class_id' => 1, 'first_name' => 'Ada', 'last_name' => 'O'], ['id' => 2, 'user_id' => 3, 'class_id' => 2, 'first_name' => 'Ben', 'last_name' => 'E']]);
    }

    private function req(int $uid, string $method = 'GET', array $data = []): Request
    {
        $u = User::find($uid);
        $this->actingAs($u);
        $r = Request::create('/', $method, $data);
        $r->setUserResolver(fn () => $u);

        return $r;
    }

    public function test_quiz_flow(): void
    {
        $c = new PracticeQuizController();
        $res = $c->store($this->req(1, 'POST', ['title' => 'Fractions', 'class_id' => 1, 'questions' => [
            ['text' => '1/2 + 1/2 = ?', 'options' => ['1', '2', '1/4'], 'correct' => 0, 'explanation' => 'Two halves make a whole.'],
            ['text' => 'Is 3/4 more than 1/2?', 'options' => ['True', 'False'], 'correct' => 0],
        ]]));
        $this->assertSame(201, $res->getStatusCode(), $res->getContent());
        $id = $res->getData(true)['id'];

        $this->assertCount(1, $c->index($this->req(2))->getData(true)['quizzes']);
        $this->assertCount(0, $c->index($this->req(3))->getData(true)['quizzes'], 'another class');
        $q = $c->show($this->req(2), $id)->getData(true)['quiz']['questions'][0];
        $this->assertArrayNotHasKey('correct', $q, 'no answers before submitting');
        $this->assertSame(403, $c->show($this->req(3), $id)->getStatusCode());

        $r = $c->submit($this->req(2, 'POST', ['answers' => [0, 1]]), $id)->getData(true);
        $this->assertSame([1, 2], [$r['score'], $r['total']]);
        $this->assertSame('Two halves make a whole.', $r['review'][0]['explanation']);
        $this->assertSame(1, $c->index($this->req(2))->getData(true)['quizzes'][0]['best']);
        $this->assertSame('Ada O', $c->results($this->req(1), $id)->getData(true)['results'][0]['name']);
    }
}
