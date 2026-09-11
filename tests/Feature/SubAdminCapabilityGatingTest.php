<?php

namespace Tests\Feature;

use App\Http\Controllers\FeeController;
use App\Http\Controllers\RoleCapabilityController;
use App\Http\Controllers\UserController;
use App\Models\School;
use App\Models\User;
use App\Support\RoleCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers making 'admin' a genuinely dial-able sub-admin tier, distinct from
 * 'school_admin' (the untouchable main-admin owner account):
 *   1. 'admin' now appears in the Role Access matrix (editableRoles()) and
 *      defaults to full access, same as it always effectively had.
 *   2. Once a school_admin restricts a capability for 'admin' in that
 *      matrix, it actually bites — requireCapability() calls were added to
 *      every mutating action across Finance/Users/Academic controllers,
 *      not just Subjects (the one place this existed before).
 *   3. Two escalation loops are closed: an 'admin' with user.manage cannot
 *      grant itself/others the school_admin role, and cannot edit the Role
 *      Access matrix itself (which would let it simply re-grant whatever
 *      was restricted) — both require the real school_admin role, not just
 *      the capability.
 *   4. school_admin/principal/vice_principal are unaffected either way —
 *      still always full access (LEADERSHIP bypass).
 */
class SubAdminCapabilityGatingTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenant.subdomain' => 'test-tenant']);

        // Central users.role is a SQLite CHECK constraint frozen to the
        // original 2025 migration's short role list (super_admin/
        // school_admin/teacher/student/parent) — 'admin' isn't in it.
        // Rebuild with a plain string column, same workaround used
        // elsewhere in this suite (reverts automatically with the
        // RefreshDatabase transaction).
        Schema::dropIfExists('users');
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('tenant_id')->nullable();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('phone')->nullable();
            $t->string('password');
            $t->string('role')->default('student');
            $t->string('status')->default('active');
            $t->string('profile_picture')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('settings', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->string('type')->default('string');
            $t->string('category')->nullable();
            $t->timestamps();
        });

        Schema::create('academic_years', function ($t) { $t->id(); $t->string('name'); });
        Schema::create('terms', function ($t) { $t->id(); $t->string('name'); });

        Schema::create('students', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->string('first_name');
            $t->string('last_name');
            $t->string('email')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('fees', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('class_id')->nullable();
            $t->unsignedBigInteger('fee_structure_id')->nullable();
            $t->boolean('is_customized')->default(false);
            $t->unsignedBigInteger('academic_year_id')->nullable();
            $t->unsignedBigInteger('term_id')->nullable();
            $t->string('fee_type');
            $t->decimal('amount', 10, 2);
            $t->decimal('amount_paid', 10, 2)->default(0);
            $t->decimal('balance', 10, 2)->default(0);
            $t->date('due_date')->nullable();
            $t->string('status')->default('pending');
            $t->text('description')->nullable();
            $t->timestamp('last_reminded_at')->nullable();
            $t->timestamps();
        });

        Schema::create('fee_items', function ($t) {
            $t->id();
            $t->unsignedBigInteger('fee_id');
            $t->string('name');
            $t->decimal('amount', 10, 2);
            $t->timestamps();
        });

        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->school = School::create(['tenant_id' => 'test-tenant', 'name' => 'Greenfield Academy']);
    }

    private function makeUser(string $role, string $email): User
    {
        return User::create([
            'tenant_id' => 'test-tenant',
            'name' => ucfirst($role),
            'email' => $email,
            'password' => bcrypt('secret'),
            'role' => $role,
        ]);
    }

    private function feeRequest(int $studentId): Request
    {
        $request = Request::create('/', 'POST', [
            'student_id' => $studentId,
            'fee_type' => 'Tuition',
            'amount' => 5000,
            'due_date' => now()->addWeek()->toDateString(),
            'academic_year_id' => 1,
            'term_id' => 1,
        ]);
        $request->attributes->set('school', $this->school);

        return $request;
    }

    public function test_admin_appears_in_role_access_matrix_and_defaults_to_full_access(): void
    {
        $payload = RoleCapabilityService::payloadForSchool($this->school->id);

        $this->assertArrayHasKey('admin', $payload['roles']);
        foreach (array_keys($payload['capabilities']) as $cap) {
            $this->assertTrue($payload['matrix']['admin'][$cap], "admin should default to true for {$cap}");
        }
    }

    public function test_restricting_finance_manage_for_admin_blocks_admin_but_not_school_admin(): void
    {
        DB::table('academic_years')->insert(['id' => 1, 'name' => '2026/2027']);
        DB::table('terms')->insert(['id' => 1, 'name' => 'First Term']);
        $studentId = DB::table('students')->insertGetId([
            'school_id' => $this->school->id, 'first_name' => 'Ada', 'last_name' => 'Okafor',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Before any restriction, admin can create fees like school_admin.
        $this->actingAs($this->makeUser('admin', 'admin@example.test'));
        $before = (new FeeController())->store($this->feeRequest($studentId));
        $this->assertSame(201, $before->getStatusCode(), $before->getContent());

        // A school_admin dials finance.manage off for admin.
        $this->actingAs($this->makeUser('school_admin', 'owner@example.test'));
        $matrix = RoleCapabilityService::matrixForSchool($this->school->id);
        $matrix['admin']['finance.manage'] = false;
        $saveRequest = Request::create('/', 'PUT', ['matrix' => $matrix]);
        $saveRequest->attributes->set('school', $this->school);
        $saveResponse = (new RoleCapabilityController())->update($saveRequest);
        $this->assertSame(200, $saveResponse->getStatusCode(), $saveResponse->getContent());

        // Now admin is blocked from the exact same action...
        $this->actingAs($this->makeUser('admin', 'admin2@example.test'));
        $afterAdmin = (new FeeController())->store($this->feeRequest($studentId));
        $this->assertSame(403, $afterAdmin->getStatusCode());

        // ...but school_admin is never subject to the matrix at all.
        $this->actingAs($this->makeUser('school_admin', 'owner2@example.test'));
        $afterSchoolAdmin = (new FeeController())->store($this->feeRequest($studentId));
        $this->assertSame(201, $afterSchoolAdmin->getStatusCode(), $afterSchoolAdmin->getContent());
    }

    public function test_admin_cannot_edit_the_role_access_matrix_even_with_user_manage(): void
    {
        $this->actingAs($this->makeUser('admin', 'admin@example.test'));

        $request = Request::create('/', 'PUT', ['matrix' => []]);
        $request->attributes->set('school', $this->school);
        $response = (new RoleCapabilityController())->update($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_admin_cannot_promote_a_user_to_school_admin(): void
    {
        $target = $this->makeUser('teacher', 'teacher@example.test');
        $this->actingAs($this->makeUser('admin', 'admin@example.test'));

        $request = Request::create('/', 'POST', ['role' => 'school_admin']);
        $request->attributes->set('school', $this->school);
        $response = (new UserController())->assignRole($request, $target->id);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('teacher', $target->fresh()->role);
    }

    public function test_school_admin_can_promote_a_user_to_school_admin(): void
    {
        $target = $this->makeUser('teacher', 'teacher@example.test');
        $this->actingAs($this->makeUser('school_admin', 'owner@example.test'));

        $request = Request::create('/', 'POST', ['role' => 'school_admin']);
        $request->attributes->set('school', $this->school);
        $response = (new UserController())->assignRole($request, $target->id);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame('school_admin', $target->fresh()->role);
    }
}
