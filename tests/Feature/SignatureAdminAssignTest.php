<?php

namespace Tests\Feature;

use App\Http\Controllers\SignatureController;
use App\Models\School;
use App\Models\SchoolSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Signature: current system applies across the entire school" —
 * SchoolSignature::resolveForReportCard() already personalizes a report
 * card's class_teacher slot to that class's actual teacher, and a teacher
 * can already self-upload their own via "My Signature" (SignatureController
 * ::storeMine()). The real gap: the admin-facing Settings > Signatures
 * screen (SignatureController::store()/update(), what Settings.tsx
 * actually calls) had no way to set one up FOR a specific teacher at all —
 * only the single school-wide default per role, which is exactly what
 * "applies across the entire school" describes. Confirmed live: demoschool
 * has exactly one signature on file (role=director, teacher_id=null) —
 * no teacher had ever discovered/used the self-service upload.
 *
 * Added teacher_id support to the admin store()/update() endpoints so an
 * admin can assign a specific teacher's signature directly, without
 * depending on that teacher finding "My Signature" themselves.
 */
class SignatureAdminAssignTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private int $teacherId;

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

        Schema::dropIfExists('teachers');
        Schema::create('teachers', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('employee_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::dropIfExists('school_signatures');
        Schema::create('school_signatures', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('teacher_id')->nullable();
            $t->string('role');
            $t->string('name');
            $t->string('signature_path');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->school = School::create(['tenant_id' => 'test-tenant', 'name' => 'Greenfield Academy']);
        $this->teacherId = DB::table('teachers')->insertGetId([
            'first_name' => 'Mary', 'last_name' => 'Obi', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::create([
            'tenant_id' => 'test-tenant', 'name' => 'Admin', 'email' => 'admin@example.test',
            'password' => bcrypt('secret'), 'role' => 'school_admin',
        ]));

        Storage::fake('s3');
    }

    private function uploadRequest(array $extra): Request
    {
        $request = Request::create('/', 'POST', array_merge([
            'name' => 'Test Signature',
            'role' => 'class_teacher',
        ], $extra), [], [
            'signature_file' => UploadedFile::fake()->image('sig.png'),
        ]);
        $request->attributes->set('school', $this->school);

        return $request;
    }

    public function test_admin_can_assign_a_signature_to_a_specific_teacher(): void
    {
        $response = (new SignatureController())->store($this->uploadRequest(['teacher_id' => $this->teacherId]));

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $data = $response->getData(true);
        $this->assertSame($this->teacherId, $data['signature']['teacher_id']);
        $this->assertSame('Mary', $data['signature']['teacher']['first_name']);
    }

    public function test_a_teachers_personal_signature_does_not_deactivate_the_shared_default_for_the_same_role(): void
    {
        $shared = SchoolSignature::create([
            'school_id' => $this->school->id, 'role' => 'class_teacher', 'teacher_id' => null,
            'name' => 'Shared Default', 'signature_path' => 'shared.png', 'active' => true,
        ]);

        (new SignatureController())->store($this->uploadRequest(['teacher_id' => $this->teacherId]));

        $this->assertTrue($shared->fresh()->active, 'assigning a teacher-specific signature should not deactivate the shared role default');
    }

    public function test_a_second_shared_default_for_the_same_role_deactivates_the_first_but_not_a_teachers_personal_one(): void
    {
        $personal = SchoolSignature::create([
            'school_id' => $this->school->id, 'role' => 'class_teacher', 'teacher_id' => $this->teacherId,
            'name' => "Mary's Signature", 'signature_path' => 'mary.png', 'active' => true,
        ]);
        $firstShared = SchoolSignature::create([
            'school_id' => $this->school->id, 'role' => 'class_teacher', 'teacher_id' => null,
            'name' => 'Old Default', 'signature_path' => 'old.png', 'active' => true,
        ]);

        (new SignatureController())->store($this->uploadRequest([])); // no teacher_id -> new shared default

        $this->assertFalse($firstShared->fresh()->active);
        $this->assertTrue($personal->fresh()->active, 'a specific teacher\'s own signature must be untouched by a new shared default');
    }

    public function test_index_includes_the_assigned_teachers_name(): void
    {
        SchoolSignature::create([
            'school_id' => $this->school->id, 'role' => 'class_teacher', 'teacher_id' => $this->teacherId,
            'name' => "Mary's Signature", 'signature_path' => 'mary.png', 'active' => true,
        ]);

        $request = Request::create('/', 'GET');
        $request->attributes->set('school', $this->school);
        $response = (new SignatureController())->index($request);
        $data = $response->getData(true);

        $this->assertSame('Mary', $data['signatures'][0]['teacher']['first_name']);
    }
}
