<?php

namespace Tests\Feature;

use App\Http\Controllers\StaffController;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "STAFF NOT EDITING" from the general review — live-verified on production
 * that the update itself actually persists and is readable back over the
 * real HTTP path; the only real bug found along the way was
 * employment_date being silently dropped (present in the frontend edit
 * form, absent from both the validator and the update()'s $request->only()
 * list), so a change to just that field looked like it "didn't save".
 */
class StaffUpdateTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private int $staffId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('users');
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('tenant_id')->nullable();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role')->default('school_admin');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('staff', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('employee_id')->nullable();
            $t->string('first_name');
            $t->string('last_name');
            $t->string('middle_name')->nullable();
            $t->string('email')->unique();
            $t->string('phone')->nullable();
            $t->string('role')->default('staff');
            $t->string('department')->nullable();
            $t->date('employment_date')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
            $t->softDeletes();
        });

        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->school = School::create(['tenant_id' => 'test-tenant', 'name' => 'Greenfield Academy']);

        $this->staffId = DB::table('staff')->insertGetId([
            'school_id' => $this->school->id, 'first_name' => 'Blessing', 'last_name' => 'Eze',
            'email' => 'blessing@example.test', 'role' => 'accountant',
            'employment_date' => '2025-08-25', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::create([
            'tenant_id' => 'test-tenant', 'name' => 'Admin', 'email' => 'admin@example.test',
            'password' => bcrypt('secret'), 'role' => 'school_admin',
        ]));
    }

    public function test_updating_employment_date_actually_persists(): void
    {
        $request = Request::create('/', 'PUT', ['employment_date' => '2026-01-15']);
        $request->attributes->set('school', $this->school);
        $request->setUserResolver(fn () => auth()->user());

        $response = (new StaffController())->update($request, $this->staffId);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame(
            '2026-01-15',
            DB::table('staff')->find($this->staffId)->employment_date,
            'employment_date must persist, not be silently dropped'
        );
    }

    public function test_updating_other_fields_still_works(): void
    {
        $request = Request::create('/', 'PUT', ['department' => 'Finance']);
        $request->attributes->set('school', $this->school);
        $request->setUserResolver(fn () => auth()->user());

        $response = (new StaffController())->update($request, $this->staffId);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame('Finance', DB::table('staff')->find($this->staffId)->department);
    }
}
