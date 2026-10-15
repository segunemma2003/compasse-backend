<?php

namespace Tests\Feature;

use App\Http\Controllers\StaffController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Staff lists everyone who works at the school, and edits land on the right record. */
class StaffDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $profile = fn ($t) => [$t->string('gender')->nullable(), $t->date('date_of_birth')->nullable(), $t->date('employment_date')->nullable(), $t->text('address')->nullable(),
            $t->string('emergency_contact_name')->nullable(), $t->string('emergency_contact_phone')->nullable(), $t->string('next_of_kin_name')->nullable(),
            $t->string('next_of_kin_phone')->nullable(), $t->string('next_of_kin_relationship')->nullable()];
        $tables = [
            'users' => fn ($t) => [$t->id(), $t->string('name'), $t->string('email'), $t->string('phone')->nullable(), $t->string('password'), $t->string('role'), $t->string('status')->default('active'), $t->timestamps(), $t->softDeletes()],
            'schools' => fn ($t) => [$t->id(), $t->string('name')->nullable(), $t->timestamps()],
            'staff' => fn ($t) => array_merge([$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->unsignedBigInteger('user_id')->nullable(), $t->string('employee_id')->nullable(), $t->string('first_name'), $t->string('last_name'), $t->string('middle_name')->nullable(), $t->string('email'), $t->string('phone')->nullable(), $t->string('role'), $t->string('department')->nullable(), $t->string('status')->default('active'), $t->timestamps(), $t->softDeletes()], $profile($t)),
            'teachers' => fn ($t) => array_merge([$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->unsignedBigInteger('department_id')->nullable(), $t->string('employee_id')->nullable(), $t->string('first_name'), $t->string('last_name'), $t->string('middle_name')->nullable(), $t->string('email')->nullable(), $t->string('phone')->nullable(), $t->string('status')->default('active'), $t->timestamps(), $t->softDeletes()], $profile($t)),
            'settings' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('key'), $t->text('value')->nullable(), $t->string('type')->nullable(), $t->string('category')->nullable(), $t->timestamps()],
        ];
        foreach ($tables as $n => $def) {
            Schema::dropIfExists($n);
            Schema::create($n, $def);
        }
        DB::table('schools')->insert(['id' => 1, 'name' => 'Test']);
        foreach ([[1, 'school_admin', 'Ada Admin'], [2, 'accountant', 'Bola Accounts'], [3, 'teacher', 'Chi Teacher'], [4, 'principal', 'Dayo Principal']] as [$id, $role, $name]) {
            User::forceCreate(['id' => $id, 'name' => $name, 'email' => "u{$id}@example.com", 'password' => 'x', 'role' => $role]);
        }
        DB::table('staff')->insert(['id' => 1, 'school_id' => 1, 'user_id' => 2, 'first_name' => 'Bola', 'last_name' => 'Accounts', 'email' => 'u2@example.com', 'role' => 'accountant', 'employment_date' => '2023-04-20']);
        DB::table('teachers')->insert(['id' => 1, 'user_id' => 3, 'first_name' => 'Chi', 'last_name' => 'Teacher', 'email' => 'u3@example.com']);
    }

    private function req(string $method = 'GET', array $data = []): Request
    {
        $u = User::find(1);
        $this->actingAs($u);
        $r = Request::create('/', $method, $data);
        $r->setUserResolver(fn () => $u);

        return $r;
    }

    public function test_lists_staff_teachers_and_leadership(): void
    {
        $rows = collect((new StaffController())->index($this->req())->getData(true)['data']);
        $this->assertEqualsCanonicalizing(['Bola', 'Chi', 'Dayo', 'Ada'], $rows->pluck('first_name')->all());
        $this->assertSame('teacher', $rows->firstWhere('first_name', 'Chi')['source']);
        $this->assertSame('2023-04-20', $rows->firstWhere('first_name', 'Bola')['employment_date']);
    }

    public function test_edits_save_dates_contacts_and_role(): void
    {
        $c = new StaffController();
        $res = $c->update($this->req('PUT', ['source' => 'staff_record', 'date_of_birth' => '1990-05-01', 'employment_date' => '2024-01-08', 'next_of_kin_name' => 'Kemi', 'address' => '2 Road']), 1);
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $s = DB::table('staff')->find(1);
        $this->assertSame(['1990-05-01', '2024-01-08', 'Kemi', '2 Road'], [$s->date_of_birth, $s->employment_date, $s->next_of_kin_name, $s->address]);

        $res = $c->update($this->req('PUT', ['source' => 'teacher', 'role' => 'principal', 'employment_date' => '2022-09-05']), 1);
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $this->assertSame('principal', DB::table('users')->where('id', 3)->value('role'));
        $this->assertSame('2022-09-05', DB::table('teachers')->where('id', 1)->value('employment_date'));
    }
}
