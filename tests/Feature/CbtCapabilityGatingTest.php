<?php

namespace Tests\Feature;

use App\Http\Controllers\BulkUploadController;
use App\Http\Controllers\GradeController;
use App\Http\Controllers\RoleCapabilityController;
use App\Http\Controllers\TimetableController;
use App\Models\School;
use App\Models\User;
use App\Support\RoleCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * CBT/Exams/Assignments/CA/Psychomotor/Question-bank/Quizzes/Grades/
 * Timetable/Bulk-upload — this whole route block (routes/api.php
 * "ACADEMIC STAFF" group) previously had no capability gate at all, only
 * the coarse role: middleware (any academic-staff role could create/edit/
 * delete exams, assignments, CA, grades, timetable entries, no matter what
 * a school_admin had dialed down for them in Role Access). Deferred earlier
 * given the size of that route block; this closes the gap the same way
 * every other module in this session was gated: a new 'cbt.manage'
 * capability for the assessment-building/grading family, reusing the
 * existing (previously unwired) 'timetable.manage' and 'result.manage'
 * capabilities for their respective actions.
 *
 * Both new-default capabilities are seeded true for every teacher-tier role
 * (teacher/class_teacher/subject_teacher/year_tutor/hod) so that adding the
 * gate does not silently take away access a school hasn't touched in Role
 * Access — only school_admin dialing it down actually bites, same pattern
 * as every other capability added this session.
 */
class CbtCapabilityGatingTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

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

        Schema::create('settings', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->string('type')->default('string');
            $t->string('category')->nullable();
            $t->timestamps();
        });

        Schema::create('subjects', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('code')->nullable();
            $t->timestamps();
        });

        Schema::create('classes', function ($t) {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('class_teacher_id')->nullable();
            $t->timestamps();
        });

        Schema::create('teachers', function ($t) {
            $t->id();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->timestamps();
        });

        Schema::create('students', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->string('first_name');
            $t->string('last_name');
            $t->timestamps();
        });

        Schema::create('grades', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('class_id')->nullable();
            $t->unsignedBigInteger('term_id')->nullable();
            $t->unsignedBigInteger('academic_year_id')->nullable();
            $t->string('assessment_type')->nullable();
            $t->unsignedBigInteger('assessment_id')->nullable();
            $t->decimal('score', 8, 2);
            $t->decimal('total_marks', 8, 2);
            $t->string('grade')->nullable();
            $t->decimal('percentage', 5, 2)->nullable();
            $t->text('remarks')->nullable();
            $t->unsignedBigInteger('graded_by')->nullable();
            $t->timestamps();
        });

        Schema::create('timetables', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('class_id')->nullable();
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('teacher_id')->nullable();
            $t->string('day_of_week');
            $t->time('start_time');
            $t->time('end_time');
            $t->string('room')->nullable();
            $t->string('term')->nullable();
            $t->unsignedBigInteger('academic_year_id')->nullable();
            $t->timestamps();
        });

        // bulk_uploads only exists in database/migrations/tenant/, not the
        // central database/migrations/ RefreshDatabase loads by default —
        // unlike school_signatures/teachers (defined centrally), it needs
        // to be created here explicitly.
        Schema::create('bulk_uploads', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('user_id');
            $t->string('type');
            $t->string('status')->default('pending');
            $t->string('file_path');
            $t->string('file_name');
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('processed_rows')->default(0);
            $t->unsignedInteger('success_rows')->default(0);
            $t->unsignedInteger('failed_rows')->default(0);
            $t->json('errors')->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });

        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->school = School::create(['tenant_id' => 'test-tenant', 'name' => 'Greenfield Academy']);
    }

    private function makeUser(string $role, string $email): User
    {
        return User::create([
            'tenant_id' => 'test-tenant', 'name' => ucfirst($role), 'email' => $email,
            'password' => bcrypt('secret'), 'role' => $role,
        ]);
    }

    /**
     * A bare Request::create() has no resolved user ($request->user() is
     * null) unlike a real HTTP request, which auth:sanctum populates before
     * the controller runs. Several of the controllers gated here (Grade,
     * BulkUpload) read $request->user() directly rather than going through
     * Controller::roleCan()'s auth()->user() fallback, so tests calling them
     * directly need this wired up the same way the real middleware would.
     */
    private function withUser(Request $request): Request
    {
        $request->setUserResolver(fn () => auth()->user());

        return $request;
    }

    private function restrict(string $role, string $capability): void
    {
        $matrix = RoleCapabilityService::matrixForSchool($this->school->id);
        $matrix[$role][$capability] = false;
        $request = Request::create('/', 'PUT', ['matrix' => $matrix]);
        $request->attributes->set('school', $this->school);
        $response = (new RoleCapabilityController())->update($request);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    }

    public function test_cbt_manage_and_timetable_manage_default_true_for_every_teacher_tier_role(): void
    {
        foreach (['teacher', 'class_teacher', 'subject_teacher', 'year_tutor', 'hod'] as $role) {
            $user = $this->makeUser($role, "{$role}@example.test");
            $this->assertTrue(
                RoleCapabilityService::userCan($user, 'cbt.manage', $this->school->id),
                "{$role} should default to cbt.manage=true — this route block was previously ungated for it"
            );
            $this->assertTrue(
                RoleCapabilityService::userCan($user, 'timetable.manage', $this->school->id),
                "{$role} should default to timetable.manage=true — this route block was previously ungated for it"
            );
        }
    }

    public function test_restricting_cbt_manage_for_admin_blocks_recording_a_grade_but_not_school_admin(): void
    {
        $subjectId = DB::table('subjects')->insertGetId(['name' => 'Mathematics', 'created_at' => now(), 'updated_at' => now()]);
        $studentId = DB::table('students')->insertGetId([
            'school_id' => $this->school->id, 'first_name' => 'Ada', 'last_name' => 'Okafor',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $gradeRequest = fn () => $this->withUser(tap(Request::create('/', 'POST', [
            'student_id' => $studentId, 'subject_id' => $subjectId, 'score' => 80, 'total_marks' => 100,
        ]), fn ($r) => $r->attributes->set('school', $this->school)));

        // Before any restriction, admin can record grades like school_admin.
        $this->actingAs($this->makeUser('admin', 'admin@example.test'));
        $before = (new GradeController())->store($gradeRequest());
        $this->assertSame(201, $before->getStatusCode(), $before->getContent());

        $this->actingAs($this->makeUser('school_admin', 'owner@example.test'));
        $this->restrict('admin', 'cbt.manage');

        $this->actingAs($this->makeUser('admin', 'admin2@example.test'));
        $afterAdmin = (new GradeController())->store($gradeRequest());
        $this->assertSame(403, $afterAdmin->getStatusCode());

        $this->actingAs($this->makeUser('school_admin', 'owner2@example.test'));
        $afterSchoolAdmin = (new GradeController())->store($gradeRequest());
        $this->assertSame(201, $afterSchoolAdmin->getStatusCode(), $afterSchoolAdmin->getContent());
    }

    public function test_restricting_timetable_manage_for_admin_blocks_creating_a_timetable_entry_but_not_school_admin(): void
    {
        $subjectId = DB::table('subjects')->insertGetId(['name' => 'English', 'created_at' => now(), 'updated_at' => now()]);

        $ttRequest = fn () => tap(Request::create('/', 'POST', [
            'subject_id' => $subjectId, 'day_of_week' => 'Monday', 'start_time' => '08:00', 'end_time' => '08:45',
        ]), fn ($r) => $r->attributes->set('school', $this->school));

        $this->actingAs($this->makeUser('admin', 'admin@example.test'));
        $before = (new TimetableController())->store($ttRequest());
        $this->assertSame(201, $before->getStatusCode(), $before->getContent());

        $this->actingAs($this->makeUser('school_admin', 'owner@example.test'));
        $this->restrict('admin', 'timetable.manage');

        $this->actingAs($this->makeUser('admin', 'admin2@example.test'));
        $afterAdmin = (new TimetableController())->store($ttRequest());
        $this->assertSame(403, $afterAdmin->getStatusCode());

        $this->actingAs($this->makeUser('school_admin', 'owner2@example.test'));
        $afterSchoolAdmin = (new TimetableController())->store($ttRequest());
        $this->assertSame(201, $afterSchoolAdmin->getStatusCode(), $afterSchoolAdmin->getContent());
    }

    public function test_restricting_student_create_for_admin_blocks_bulk_uploading_students_but_not_school_admin(): void
    {
        Storage::fake('local');
        Queue::fake();

        $uploadRequest = function () {
            $request = Request::create('/', 'POST', ['type' => 'students'], [], [
                'file' => UploadedFile::fake()->create('students.csv', 10, 'text/csv'),
            ]);
            $request->attributes->set('school', $this->school);

            return $this->withUser($request);
        };

        $this->actingAs($this->makeUser('admin', 'admin@example.test'));
        $before = (new BulkUploadController())->upload($uploadRequest());
        $this->assertSame(202, $before->getStatusCode(), $before->getContent());

        $this->actingAs($this->makeUser('school_admin', 'owner@example.test'));
        $this->restrict('admin', 'student.create');

        $this->actingAs($this->makeUser('admin', 'admin2@example.test'));
        $afterAdmin = (new BulkUploadController())->upload($uploadRequest());
        $this->assertSame(403, $afterAdmin->getStatusCode());

        $this->actingAs($this->makeUser('school_admin', 'owner2@example.test'));
        $afterSchoolAdmin = (new BulkUploadController())->upload($uploadRequest());
        $this->assertSame(202, $afterSchoolAdmin->getStatusCode(), $afterSchoolAdmin->getContent());
    }
}
