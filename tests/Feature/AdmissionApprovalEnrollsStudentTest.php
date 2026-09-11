<?php

namespace Tests\Feature;

use App\Http\Controllers\AdmissionController;
use App\Jobs\SendEmailJob;
use App\Models\AdmissionCycle;
use App\Models\Applicant;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers the three admissions gaps reported by schools actually using it:
 *   1. "The admission form can't be viewed to verify the application" —
 *      showApplicant() now returns the full applicant + exam attempt/answers.
 *   2. "After approving, where does the application go? Does it register
 *      as a student?" — it didn't. Approving only flipped a status column
 *      and sent a fixed one-line email. It now creates the Student (and a
 *      Guardian, if a parent email was given) exactly as manual enrollment
 *      does, is idempotent on a second approval, and the response carries
 *      login credentials the same way Enroll Student's does.
 *   3. "The school should be able to set up the Welcome Email from under
 *      admissions" — admission_cycles.welcome_email_subject/body are now
 *      editable, with placeholder substitution, and real credentials are
 *      appended even if the school's custom text forgets to ask for them.
 */
class AdmissionApprovalEnrollsStudentTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        // portalUrl()/AdmissionCycle::getRegistrationUrlAttribute() read
        // config('tenant.subdomain') as a plain string — which is what it
        // is at runtime, because TenantMiddleware overwrites it with the
        // resolved tenant's subdomain. Outside that middleware (as here),
        // it's still config/tenant.php's default *array* shape
        // ({enabled, wildcard, main_domain}), so simulate what the
        // middleware would have set.
        config(['tenant.subdomain' => 'test-tenant']);

        // The central users table's `role` column is a plain SQL CHECK
        // constraint on SQLite (Laravel's enum() column type compiles to
        // one there), fixed at CREATE TABLE time to the original 2025
        // migration's short list (super_admin/school_admin/teacher/student/
        // parent). The later migration that widens it to the real role
        // list (.../2025_11_24_add_more_roles_to_users_table.php) only
        // does anything on MySQL — its SQLite branch is a documented no-op
        // ("SQLite accepts any string value", which is not actually true
        // once a CHECK constraint exists). Harmless in production (MySQL
        // only) but it means no test anywhere in this repo can create a
        // 'guardian' (or 'accountant'/'nurse'/etc.) user without hitting
        // this. Rebuilding it here — inside the per-test transaction
        // RefreshDatabase already wraps setUp() in, so it reverts after
        // this test like everything else — unblocks that for good.
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

        Schema::create('classes', function ($t) { $t->id(); $t->string('name'); });
        Schema::create('arms', function ($t) { $t->id(); $t->string('name'); });
        Schema::create('applicant_exam_attempts', function ($t) {
            $t->id();
            $t->unsignedBigInteger('applicant_id');
            $t->unsignedBigInteger('admission_exam_id');
            $t->timestamps();
        });

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

        Schema::create('admission_cycles', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->string('name');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('academic_year_id')->nullable();
            $t->text('description')->nullable();
            $t->string('welcome_email_subject')->nullable();
            $t->text('welcome_email_body')->nullable();
            $t->boolean('requires_entrance_exam')->default(false);
            $t->dateTime('opens_at')->nullable();
            $t->dateTime('closes_at')->nullable();
            $t->string('status')->default('draft');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('applicants', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('admission_cycle_id');
            $t->uuid('access_token')->unique();
            $t->string('first_name');
            $t->string('last_name');
            $t->date('date_of_birth')->nullable();
            $t->string('gender')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('parent_name')->nullable();
            $t->string('parent_phone')->nullable();
            $t->string('parent_email')->nullable();
            $t->string('previous_school')->nullable();
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('student_id')->nullable();
            $t->string('status')->default('submitted');
            $t->decimal('exam_score', 6, 2)->nullable();
            $t->text('decision_notes')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->timestamps();
        });

        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->school = School::create(['tenant_id' => 'test-tenant', 'name' => 'Greenfield Academy']);
    }

    private function makeCycle(array $overrides = []): AdmissionCycle
    {
        $classId = DB::table('classes')->insertGetId(['name' => 'JSS1']);

        return AdmissionCycle::create(array_merge([
            'school_id' => $this->school->id,
            'name' => '2026 Intake',
            'class_id' => $classId,
            'status' => 'open',
        ], $overrides));
    }

    private function makeApplicant(AdmissionCycle $cycle, array $overrides = []): Applicant
    {
        return Applicant::create(array_merge([
            'school_id' => $this->school->id,
            'admission_cycle_id' => $cycle->id,
            'first_name' => 'Ada',
            'last_name' => 'Okafor',
            'date_of_birth' => '2015-04-10',
            'gender' => 'female',
            'email' => null,
            'class_id' => $cycle->class_id,
            'parent_name' => 'Ngozi Okafor',
            'parent_phone' => '08010000000',
            'parent_email' => 'ngozi@example.test',
            'status' => 'submitted',
        ], $overrides));
    }

    public function test_approving_an_applicant_creates_a_student_and_links_a_guardian(): void
    {
        Queue::fake();
        $cycle = $this->makeCycle();
        $applicant = $this->makeApplicant($cycle);

        $response = (new AdmissionController())->approveApplicant(Request::create('/', 'POST'), $applicant->id);
        $data = $response->getData(true);

        $this->assertSame('approved', $data['applicant']['status']);
        $this->assertNotNull($data['applicant']['student_id'], 'approving did not link a student at all');
        $this->assertArrayHasKey('login_credentials', $data);
        $this->assertNotEmpty($data['login_credentials']['email']);
        $this->assertNotEmpty($data['login_credentials']['password']);

        $student = Student::find($data['applicant']['student_id']);
        $this->assertNotNull($student, 'applicant was marked approved but no Student row exists');
        $this->assertSame('Ada', $student->first_name);
        $this->assertSame('Okafor', $student->last_name);

        $guardianCount = DB::table('guardians')->where('email', 'ngozi@example.test')->count();
        $this->assertSame(1, $guardianCount, 'guardian was not created from the applicant\'s parent_email');

        $linked = DB::table('guardian_students')->where('student_id', $student->id)->first();
        $this->assertNotNull($linked, 'the new guardian was never attached to the new student');
        $this->assertSame(1, (int) $linked->is_primary);

        Queue::assertPushed(SendEmailJob::class);
    }

    public function test_approving_the_same_applicant_twice_does_not_create_a_second_student(): void
    {
        Queue::fake();
        $cycle = $this->makeCycle();
        $applicant = $this->makeApplicant($cycle);

        $controller = new AdmissionController();
        $first = $controller->approveApplicant(Request::create('/', 'POST'), $applicant->id)->getData(true);
        $second = $controller->approveApplicant(Request::create('/', 'POST', ['notes' => 'confirmed']), $applicant->id)->getData(true);

        $this->assertSame($first['applicant']['student_id'], $second['applicant']['student_id']);
        $this->assertNull($second['login_credentials'], 'a second approval should not re-mint credentials for an already-enrolled applicant');
        $this->assertSame(1, Student::count());
    }

    public function test_rejecting_an_applicant_never_creates_a_student(): void
    {
        Queue::fake();
        $cycle = $this->makeCycle();
        $applicant = $this->makeApplicant($cycle);

        (new AdmissionController())->rejectApplicant(Request::create('/', 'POST'), $applicant->id);

        $this->assertSame(0, Student::count());
        $this->assertSame('rejected', $applicant->fresh()->status);
    }

    public function test_the_schools_custom_welcome_email_is_used_with_credentials_appended(): void
    {
        Queue::fake();
        $cycle = $this->makeCycle([
            'welcome_email_subject' => 'You\'re in, {applicant_name}!',
            // Deliberately doesn't reference {login_email}/{password} — the
            // school shouldn't have to remember the placeholder for the
            // family to actually receive usable credentials.
            'welcome_email_body' => 'Welcome to {school_name}, {applicant_name}. See you in {class_name}.',
        ]);
        $applicant = $this->makeApplicant($cycle);

        (new AdmissionController())->approveApplicant(Request::create('/', 'POST'), $applicant->id);

        Queue::assertPushed(SendEmailJob::class, function (SendEmailJob $job) {
            return $job->to === 'ngozi@example.test'
                && $job->subject === "You're in, Ada Okafor!"
                && str_contains($job->body, 'Welcome to Greenfield Academy, Ada Okafor. See you in JSS1.')
                && str_contains($job->body, 'Login email:')
                && str_contains($job->body, 'Password:');
        });
    }

    public function test_show_applicant_returns_the_full_application_for_review(): void
    {
        $cycle = $this->makeCycle();
        $applicant = $this->makeApplicant($cycle);

        $response = (new AdmissionController())->showApplicant($applicant->id);
        $data = $response->getData(true)['applicant'];

        $this->assertSame('Ada', $data['first_name']);
        $this->assertSame('ngozi@example.test', $data['parent_email']);
        $this->assertArrayHasKey('cycle', $data);
        $this->assertArrayHasKey('class', $data);
    }

    public function test_the_admission_cycle_exposes_a_shareable_registration_link(): void
    {
        $cycle = $this->makeCycle();
        $this->assertStringEndsWith('/apply', $cycle->registration_url);
    }
}
