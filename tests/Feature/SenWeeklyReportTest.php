<?php

namespace Tests\Feature;

use App\Http\Controllers\SenWeeklyReportController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** SEN weekly reports: open to every therapist / facilitator for every child on the SEN register. */
class SenWeeklyReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'users' => fn ($t) => [$t->id(), $t->string('name'), $t->string('email'), $t->string('password'), $t->string('role'), $t->timestamps(), $t->softDeletes()],
            'classes' => fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()],
            'students' => fn ($t) => [$t->id(), $t->unsignedBigInteger('class_id')->nullable(), $t->string('first_name')->nullable(), $t->string('last_name')->nullable(), $t->timestamps(), $t->softDeletes()],
            'sen_profiles' => fn ($t) => [$t->id(), $t->unsignedBigInteger('student_id'), $t->json('needs')->nullable(), $t->string('support_level')->default('monitoring'), $t->json('assigned_user_ids')->nullable(), $t->boolean('active')->default(true), $t->timestamps()],
        ] as $n => $d) {
            Schema::dropIfExists($n);
            Schema::create($n, $d);
        }
        (require database_path('migrations/tenant/2026_11_02_000004_create_sen_weekly_reports.php'))->up();
        DB::table('classes')->insert(['id' => 1, 'name' => 'Nursery']);
        DB::table('students')->insert([['id' => 1, 'class_id' => 1, 'first_name' => 'Ada'], ['id' => 2, 'class_id' => 1, 'first_name' => 'Bola']]);
        foreach ([[1, 'sen_therapist'], [2, 'sen_facilitator'], [3, 'teacher']] as [$id, $role]) {
            User::forceCreate(['id' => $id, 'name' => "U{$id}", 'email' => "u{$id}@x.test", 'password' => 'x', 'role' => $role]);
        }
        // Ada is on the register, assigned to the facilitator only; Bola is not on it.
        DB::table('sen_profiles')->insert(['student_id' => 1, 'needs' => json_encode(['Speech']), 'assigned_user_ids' => json_encode([2]), 'active' => true]);
    }

    private function req(int $uid, string $method = 'GET', array $data = []): Request
    {
        $u = User::find($uid);
        $this->actingAs($u);
        $r = Request::create('/', $method, $data);
        $r->setUserResolver(fn () => $u);

        return $r;
    }

    public function test_any_therapist_can_report_on_any_sen_child_teachers_cannot(): void
    {
        $c = app(SenWeeklyReportController::class);
        // The therapist isn't on Ada's caseload but still sees her and can report.
        $list = $c->index($this->req(1))->getData(true);
        $this->assertSame([1], array_column($list['children'], 'student_id'), 'only children on the register');
        $this->assertSame(200, $c->save($this->req(1, 'POST', ['student_id' => 1, 'progress' => 'Used 3 new words', 'rating' => 4]))->getStatusCode());
        // Saving again the same week updates rather than duplicates.
        $c->save($this->req(1, 'POST', ['student_id' => 1, 'progress' => 'Used 5 new words', 'rating' => 5]));
        $this->assertSame(1, DB::table('sen_weekly_reports')->count());
        $this->assertSame('Used 5 new words', DB::table('sen_weekly_reports')->value('progress'));
        $this->assertTrue($c->index($this->req(1))->getData(true)['children'][0]['done_by_me']);

        $this->assertSame(200, $c->save($this->req(2, 'POST', ['student_id' => 1, 'progress' => 'Calm week']))->getStatusCode(), 'facilitator too');
        $this->assertSame(422, $c->save($this->req(1, 'POST', ['student_id' => 2, 'progress' => 'x']))->getStatusCode(), 'not on the register');
        $this->assertSame(403, $c->index($this->req(3))->getStatusCode(), 'a teacher');
        $this->assertCount(2, $c->history($this->req(2), 1)->getData(true)['reports']);
    }
}
