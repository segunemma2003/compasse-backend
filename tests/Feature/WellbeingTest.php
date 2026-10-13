<?php

namespace Tests\Feature;

use App\Http\Controllers\WellbeingController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** SEN & Wellbeing access rules: counselling confidentiality, care-class scope, teacher view. */
class WellbeingTest extends TestCase
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
        Schema::dropIfExists('classes');
        Schema::create('classes', fn ($t) => [$t->id(), $t->string('name'), $t->unsignedBigInteger('class_teacher_id')->nullable(), $t->timestamps()]);
        Schema::dropIfExists('notifications');
        Schema::create('notifications', function ($t) {
            $t->id(); $t->unsignedBigInteger('user_id')->nullable(); $t->string('title')->nullable(); $t->text('message')->nullable();
            $t->string('type')->nullable(); $t->boolean('is_read')->default(false); $t->timestamps();
        });
        (require database_path('migrations/tenant/2026_10_13_000005_create_wellbeing_tables.php'))->up();

        DB::table('classes')->insert([['id' => 1, 'name' => 'Toddler One'], ['id' => 2, 'name' => 'Toddler Two']]);
        DB::table('students')->insert([
            ['id' => 1, 'class_id' => 1, 'first_name' => 'Ada', 'last_name' => 'O'],
            ['id' => 2, 'class_id' => 2, 'first_name' => 'Ben', 'last_name' => 'E'],
        ]);
    }

    private function as(string $role, int $id): User
    {
        return User::forceCreate(['id' => $id, 'name' => ucfirst($role), 'email' => "{$role}{$id}@example.com", 'password' => 'x', 'role' => $role]);
    }

    private function req(User $u, string $method = 'GET', array $data = []): Request
    {
        $this->actingAs($u);
        $r = Request::create('/', $method, $data);
        $r->setUserResolver(fn () => $u);

        return $r;
    }

    public function test_counselling_notes_are_only_visible_to_counsellors(): void
    {
        $counsellor = $this->as('counsellor', 10);
        $principal = $this->as('principal', 11);
        $c = new WellbeingController();

        $res = $c->storeSession($this->req($counsellor, 'POST', ['student_id' => 1, 'kind' => 'counselling', 'session_date' => '2026-10-13', 'notes' => 'Private worries']));
        $this->assertSame(201, $res->getStatusCode(), $res->getContent());

        $forPrincipal = $c->sessions($this->req($principal))->getData(true)['sessions'][0];
        $this->assertNull($forPrincipal['notes']);
        $this->assertTrue($forPrincipal['confidential']);

        $forCounsellor = $c->sessions($this->req($counsellor))->getData(true)['sessions'][0];
        $this->assertSame('Private worries', $forCounsellor['notes']);

        $sen = $this->as('sen_therapist', 12);
        $this->assertSame(403, $c->storeSession($this->req($sen, 'POST', ['student_id' => 1, 'kind' => 'counselling', 'session_date' => '2026-10-13']))->getStatusCode(),
            'only counsellors record counselling');
    }

    public function test_care_assistants_log_only_for_their_assigned_classes(): void
    {
        $care = $this->as('care_assistant', 20);
        DB::table('care_assignments')->insert(['user_id' => 20, 'class_id' => 1]);
        $c = new WellbeingController();

        $ok = $c->storeCareLog($this->req($care, 'POST', ['student_ids' => [1], 'type' => 'nap', 'details' => '1h']));
        $this->assertSame(201, $ok->getStatusCode(), $ok->getContent());

        $denied = $c->storeCareLog($this->req($care, 'POST', ['student_ids' => [2], 'type' => 'meal']));
        $this->assertSame(403, $denied->getStatusCode());

        $logs = $c->careLogs($this->req($care, 'GET', ['class_id' => 1]))->getData(true)['logs'];
        $this->assertCount(1, $logs);
    }

    public function test_a_referral_notifies_the_counsellors(): void
    {
        $this->as('counsellor', 10);
        $principal = $this->as('principal', 11);

        $res = (new WellbeingController())->storeReferral($this->req($principal, 'POST', ['student_id' => 2, 'referred_to' => 'counsellor', 'reason' => 'Withdrawn lately', 'urgency' => 'urgent']));

        $this->assertSame(201, $res->getStatusCode(), $res->getContent());
        $this->assertSame(1, DB::table('notifications')->where('user_id', 10)->where('type', 'warning')->count());
    }
}
