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
 * ResultController::addComments() only checked the coarse 'result.manage'
 * capability, which every teacher-tier role has by default — so any subject
 * teacher could write the class teacher's remark for a class they don't
 * teach, or the principal's remark outright, on any student's result. Scopes
 * each comment field to the role it's actually meant for: class_teacher_comment
 * to this student's own class teacher (arm-level or class-wide) or an
 * admin-tier role, principal_comment to principal/vice_principal/school_admin/
 * admin only.
 */
class ResultCommentRoleScopingTest extends TestCase
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
            $t->unsignedBigInteger('class_teacher_id')->nullable();
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
            $t->unsignedBigInteger('academic_year_id')->nullable();
            $t->date('start_date')->nullable();
            $t->timestamps();
        });

        Schema::create('teachers', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('first_name');
            $t->string('last_name');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('students', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('class_id')->nullable();
            $t->unsignedBigInteger('arm_id')->nullable();
            $t->string('first_name');
            $t->string('last_name');
            $t->timestamps();
            $t->softDeletes();
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
        $this->academicYearId = DB::table('academic_years')->insertGetId(['name' => '2025/2026', 'created_at' => now(), 'updated_at' => now()]);
        $this->termId = DB::table('terms')->insertGetId([
            'name' => 'First Term', 'academic_year_id' => $this->academicYearId, 'start_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->studentId = DB::table('students')->insertGetId([
            'school_id' => $this->school->id, 'class_id' => $this->classId, 'first_name' => 'Ada', 'last_name' => 'Okafor',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeTeacherUser(string $role, string $email, bool $isClassTeacherOfTheClass = false): User
    {
        $user = User::create([
            'tenant_id' => 'test-tenant', 'name' => ucfirst($role), 'email' => $email,
            'password' => bcrypt('secret'), 'role' => $role,
        ]);

        $teacherId = DB::table('teachers')->insertGetId([
            'user_id' => $user->id, 'first_name' => ucfirst($role), 'last_name' => 'Teacher',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($isClassTeacherOfTheClass) {
            DB::table('classes')->where('id', $this->classId)->update(['class_teacher_id' => $teacherId]);
        }

        return $user;
    }

    private function makeResult(): int
    {
        return DB::table('student_results')->insertGetId([
            'student_id' => $this->studentId, 'class_id' => $this->classId, 'term_id' => $this->termId,
            'academic_year_id' => $this->academicYearId, 'result_type' => 'end_term', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function req(array $data): Request
    {
        $request = Request::create('/', 'POST', $data);
        $request->attributes->set('school', $this->school);

        return $request;
    }

    public function test_subject_teacher_not_assigned_to_the_class_cannot_write_the_class_teacher_comment(): void
    {
        $resultId = $this->makeResult();

        $this->actingAs($this->makeTeacherUser('subject_teacher', 'subject@example.test'));
        $denied = (new ResultController())->addComments($this->req(['class_teacher_comment' => 'Great improvement']), $resultId);

        $this->assertSame(403, $denied->getStatusCode(), $denied->getContent());
        $this->assertNull(DB::table('student_results')->find($resultId)->class_teacher_comment);
    }

    public function test_the_actual_class_teacher_can_write_the_class_teacher_comment(): void
    {
        $resultId = $this->makeResult();

        $this->actingAs($this->makeTeacherUser('class_teacher', 'classteacher@example.test', isClassTeacherOfTheClass: true));
        $allowed = (new ResultController())->addComments($this->req(['class_teacher_comment' => 'Great improvement']), $resultId);

        $this->assertSame(200, $allowed->getStatusCode(), $allowed->getContent());
        $this->assertSame('Great improvement', DB::table('student_results')->find($resultId)->class_teacher_comment);
    }

    public function test_a_teacher_cannot_write_the_principal_comment_but_the_principal_can(): void
    {
        $resultId = $this->makeResult();

        $this->actingAs($this->makeTeacherUser('class_teacher', 'classteacher2@example.test', isClassTeacherOfTheClass: true));
        $denied = (new ResultController())->addComments($this->req(['principal_comment' => 'Well done']), $resultId);
        $this->assertSame(403, $denied->getStatusCode(), $denied->getContent());
        $this->assertNull(DB::table('student_results')->find($resultId)->principal_comment);

        $principal = User::create([
            'tenant_id' => 'test-tenant', 'name' => 'Principal', 'email' => 'principal@example.test',
            'password' => bcrypt('secret'), 'role' => 'principal',
        ]);
        $this->actingAs($principal);
        $allowed = (new ResultController())->addComments($this->req(['principal_comment' => 'Well done']), $resultId);
        $this->assertSame(200, $allowed->getStatusCode(), $allowed->getContent());
        $this->assertSame('Well done', DB::table('student_results')->find($resultId)->principal_comment);
    }
}
