<?php

namespace Tests\Feature;

use App\Http\Controllers\ResultController;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ResultController::approveResult()/publishResults()/unpublishResults() had
 * no requireCapability() gate at all — unlike generateResults() and
 * addComments() in the same controller, which are already gated behind
 * 'result.manage' — so any authenticated tenant user (a student or a
 * guardian included) could approve or publish/unpublish a class's results
 * regardless of what a school_admin had dialed down for their role in Role
 * Access. Closes that gap the same way, reusing the existing
 * 'result.manage' capability rather than inventing a new one, since these
 * three actions are just further steps in the same result-management
 * workflow generateResults()/addComments() already require it for.
 */
class ResultApprovalPublishCapabilityGatingTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private int $classId;
    private int $termId;
    private int $academicYearId;
    private int $studentId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenant.subdomain' => 'test-tenant']);

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

        Schema::create('classes', function ($t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('academic_years', function ($t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('terms', function ($t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('students', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->string('first_name');
            $t->string('last_name');
            $t->timestamps();
        });

        Schema::create('student_results', function ($t) {
            $t->id();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('term_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->string('result_type')->default('end_term');
            $t->decimal('total_score', 5, 2)->default(0);
            $t->decimal('average_score', 5, 2)->default(0);
            $t->string('grade')->nullable();
            $t->text('class_teacher_comment')->nullable();
            $t->text('principal_comment')->nullable();
            $t->date('next_term_begins')->nullable();
            $t->string('status')->default('draft');
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamps();
        });

        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->school = School::create(['tenant_id' => 'test-tenant', 'name' => 'Greenfield Academy']);

        $this->classId = DB::table('classes')->insertGetId(['name' => 'JSS 1', 'created_at' => now(), 'updated_at' => now()]);
        $this->termId = DB::table('terms')->insertGetId(['name' => 'First Term', 'created_at' => now(), 'updated_at' => now()]);
        $this->academicYearId = DB::table('academic_years')->insertGetId(['name' => '2025/2026', 'created_at' => now(), 'updated_at' => now()]);
        $this->studentId = DB::table('students')->insertGetId([
            'school_id' => $this->school->id, 'first_name' => 'Ada', 'last_name' => 'Okafor',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeUser(string $role, string $email): User
    {
        return User::create([
            'tenant_id' => 'test-tenant', 'name' => ucfirst($role), 'email' => $email,
            'password' => bcrypt('secret'), 'role' => $role,
        ]);
    }

    private function makeResult(string $status = 'draft'): int
    {
        return DB::table('student_results')->insertGetId([
            'student_id' => $this->studentId, 'class_id' => $this->classId, 'term_id' => $this->termId,
            'academic_year_id' => $this->academicYearId, 'result_type' => 'end_term', 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function req(array $data = []): Request
    {
        $request = Request::create('/', 'POST', $data);
        $request->attributes->set('school', $this->school);

        return $request;
    }

    public function test_student_cannot_approve_a_result_but_class_teacher_can(): void
    {
        $resultId = $this->makeResult('draft');

        $this->actingAs($this->makeUser('student', 'student@example.test'));
        $denied = (new ResultController())->approveResult($this->req(), $resultId);
        $this->assertSame(403, $denied->getStatusCode(), $denied->getContent());
        $this->assertSame('draft', DB::table('student_results')->find($resultId)->status);

        $this->actingAs($this->makeUser('class_teacher', 'teacher@example.test'));
        $allowed = (new ResultController())->approveResult($this->req(), $resultId);
        $this->assertSame(200, $allowed->getStatusCode(), $allowed->getContent());
        $this->assertSame('approved', DB::table('student_results')->find($resultId)->status);
    }

    public function test_guardian_cannot_publish_results_but_school_admin_can(): void
    {
        $resultId = $this->makeResult('approved');

        $payload = [
            'class_id' => $this->classId, 'term_id' => $this->termId,
            'academic_year_id' => $this->academicYearId, 'result_type' => 'end_term',
        ];

        $this->actingAs($this->makeUser('guardian', 'guardian@example.test'));
        $denied = (new ResultController())->publishResults($this->req($payload));
        $this->assertSame(403, $denied->getStatusCode(), $denied->getContent());
        $this->assertSame('approved', DB::table('student_results')->find($resultId)->status);

        $this->actingAs($this->makeUser('school_admin', 'owner@example.test'));
        $allowed = (new ResultController())->publishResults($this->req($payload));
        $this->assertSame(200, $allowed->getStatusCode(), $allowed->getContent());
        $this->assertSame('published', DB::table('student_results')->find($resultId)->status);
    }

    public function test_parent_cannot_unpublish_results_but_principal_can(): void
    {
        $resultId = $this->makeResult('published');

        $payload = [
            'class_id' => $this->classId, 'term_id' => $this->termId,
            'academic_year_id' => $this->academicYearId, 'result_type' => 'end_term',
        ];

        $this->actingAs($this->makeUser('parent', 'parent@example.test'));
        $denied = (new ResultController())->unpublishResults($this->req($payload));
        $this->assertSame(403, $denied->getStatusCode(), $denied->getContent());
        $this->assertSame('published', DB::table('student_results')->find($resultId)->status);

        $this->actingAs($this->makeUser('principal', 'principal@example.test'));
        $allowed = (new ResultController())->unpublishResults($this->req($payload));
        $this->assertSame(200, $allowed->getStatusCode(), $allowed->getContent());
        $this->assertSame('approved', DB::table('student_results')->find($resultId)->status);
    }
}
