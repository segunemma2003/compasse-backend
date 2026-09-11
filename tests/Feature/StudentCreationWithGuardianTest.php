<?php

namespace Tests\Feature;

use App\Http\Controllers\StudentController;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "Students not adding" — reproduced live 2026-09-11: creating a student
 * with no guardian attached worked fine, but attaching a guardian (the
 * normal, common case) 500'd every time with "Array to string conversion"
 * in ManagesGuardianAccounts::portalUrl() (used to build the login-details
 * email body). Root cause: portalUrl() read config('tenant.subdomain')
 * directly, which every real request leaves at its config/tenant.php
 * default (an array) — see TenantUrl::currentSubdomain()'s docblock for
 * why. Fixed there; this test exercises the actual reported flow
 * end-to-end through StudentController::store(), deliberately without the
 * config(['tenant.subdomain' => ...]) override most other tests in this
 * suite use, so it fails again if this regresses.
 */
class StudentCreationWithGuardianTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private int $classId;

    protected function setUp(): void
    {
        parent::setUp();

        // Deliberately NOT setting config(['tenant.subdomain' => ...]) —
        // that would mask exactly the bug this test exists to catch.

        Schema::dropIfExists('users');
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('tenant_id')->nullable();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role')->default('student');
            $t->string('status')->default('active');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('classes', function ($t) { $t->id(); $t->string('name'); });
        Schema::create('arms', function ($t) { $t->id(); $t->string('name'); });

        Schema::create('students', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('admission_number')->unique();
            $t->string('first_name');
            $t->string('last_name');
            $t->string('email')->unique();
            $t->date('date_of_birth')->nullable();
            $t->string('gender')->nullable();
            $t->date('admission_date');
            $t->unsignedBigInteger('class_id')->nullable();
            $t->unsignedBigInteger('arm_id')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('guardians', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('first_name');
            $t->string('last_name');
            $t->string('middle_name')->nullable();
            $t->string('email')->unique();
            $t->string('phone')->nullable();
            $t->text('address')->nullable();
            $t->string('occupation')->nullable();
            $t->string('employer')->nullable();
            $t->string('relationship_to_student')->nullable();
            $t->string('emergency_contact')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });

        Schema::create('guardian_students', function ($t) {
            $t->id();
            $t->unsignedBigInteger('guardian_id');
            $t->unsignedBigInteger('student_id');
            $t->string('relationship');
            $t->boolean('is_primary')->default(false);
            $t->boolean('emergency_contact')->default(false);
            $t->timestamps();
        });

        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->school = School::create(['tenant_id' => 'test-tenant', 'name' => 'Greenfield Academy']);
        $this->classId = DB::table('classes')->insertGetId(['name' => 'JSS1']);

        // student.create is now required (sub-admin capability gating) —
        // school_admin always passes it, unrelated to what's under test.
        $this->actingAs(\App\Models\User::create([
            'tenant_id' => 'test-tenant', 'name' => 'Admin', 'email' => 'admin@example.test',
            'password' => bcrypt('secret'), 'role' => 'school_admin',
        ]));
    }

    public function test_creating_a_student_with_a_guardian_does_not_crash(): void
    {
        Queue::fake();

        $request = Request::create('/', 'POST', [
            'first_name' => 'Ada',
            'last_name' => 'Okafor',
            'email' => 'ada@example.test',
            'date_of_birth' => '2015-01-01',
            'gender' => 'female',
            'admission_date' => now()->toDateString(),
            'class_id' => $this->classId,
            'guardians' => [[
                'first_name' => 'Ngozi', 'last_name' => 'Okafor',
                'email' => 'ngozi@example.test', 'phone' => '08010000000',
                'relationship' => 'mother', 'is_primary' => true,
            ]],
        ]);
        $request->attributes->set('school', $this->school);

        $response = (new StudentController())->store($request);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $student = Student::where('email', 'ada@example.test')->first();
        $this->assertNotNull($student);

        $guardianCount = DB::table('guardians')->where('email', 'ngozi@example.test')->count();
        $this->assertSame(1, $guardianCount, 'the guardian was never created — this is the exact crash this test guards against');

        $link = DB::table('guardian_students')->where('student_id', $student->id)->first();
        $this->assertNotNull($link);
        $this->assertSame(1, (int) $link->is_primary);
    }

    public function test_creating_a_student_without_a_guardian_still_works(): void
    {
        $request = Request::create('/', 'POST', [
            'first_name' => 'Chidi', 'last_name' => 'Eze',
            'email' => 'chidi@example.test',
            'date_of_birth' => '2015-01-01', 'gender' => 'male',
            'admission_date' => now()->toDateString(),
            'class_id' => $this->classId,
        ]);
        $request->attributes->set('school', $this->school);

        $response = (new StudentController())->store($request);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
    }
}
