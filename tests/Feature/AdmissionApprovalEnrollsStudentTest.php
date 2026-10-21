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

        (require database_path('migrations/tenant/2026_10_22_000001_admission_onboarding.php'))->up();
        if (! Schema::hasTable('onboarding_documents')) {
            $this->fail('onboarding tables missing');
        }

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

    public function test_approving_offers_a_place_with_the_admission_letter_but_does_not_enrol(): void
    {
        Queue::fake();
        $cycle = $this->makeCycle();
        $applicant = $this->makeApplicant($cycle);

        $data = (new AdmissionController())->approveApplicant(Request::create('/', 'POST'), $applicant->id)->getData(true);

        $this->assertSame('approved', $data['applicant']['status']);
        $this->assertNull($data['applicant']['student_id'], 'approving must not create the student any more');
        $this->assertSame(0, Student::count());
        Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job) => $job->to === 'ngozi@example.test'
            && str_contains($job->subject, 'Admission offer for Ada')
            && collect($job->attachments)->contains(fn ($f) => str_starts_with($f['name'], 'Admission-letter-') && $f['mime'] === 'application/pdf'));
    }

    public function test_enrolling_creates_the_student_and_guardian_once_and_sends_the_starter_pack(): void
    {
        Queue::fake();
        $cycle = $this->makeCycle();
        $applicant = $this->makeApplicant($cycle);
        $c = new AdmissionController();

        $this->assertSame(422, $c->enroll(Request::create('/', 'POST'), $applicant->id)->getStatusCode(), 'offer first');
        $c->approveApplicant(Request::create('/', 'POST'), $applicant->id);

        $first = $c->enroll(Request::create('/', 'POST', ['payment_confirmed' => true]), $applicant->id)->getData(true);
        $this->assertSame('enrolled', $first['applicant']['status']);
        $student = Student::find($first['applicant']['student_id']);
        $this->assertSame('Ada', $student->first_name);
        $this->assertNotEmpty($first['login_credentials']['password']);
        $this->assertSame(1, DB::table('guardians')->where('email', 'ngozi@example.test')->count());
        $this->assertSame(1, (int) DB::table('guardian_students')->where('student_id', $student->id)->value('is_primary'));
        Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job) => str_contains($job->subject, "admission is confirmed"));

        $second = $c->enroll(Request::create('/', 'POST'), $applicant->id)->getData(true);
        $this->assertSame($first['applicant']['student_id'], $second['applicant']['student_id']);
        $this->assertNull($second['login_credentials']);
        $this->assertSame(1, Student::count());
        $this->assertSame(2, DB::table('onboarding_sends')->count(), 'offer + starter pack, each sent once');
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

    public function test_the_school_writes_its_own_onboarding_emails_and_timed_ones_go_out_once(): void
    {
        Queue::fake();
        $cycle = $this->makeCycle(['resumption_date' => now()->addDays(3)->toDateString()]);
        $applicant = $this->makeApplicant($cycle);
        $steps = \App\Support\Onboarding::steps();
        $this->assertSame(['welcome', 'offer', 'confirmed', 'meet_teacher', 'first_day', 'first_week'], $steps->pluck('key')->all());

        DB::table('onboarding_steps')->where('key', 'offer')->update(['subject' => 'A place for {child_first_name} in {class_name}!', 'body' => 'Dear {parent_name}, see you on {resumption_date}.']);
        $c = new AdmissionController();
        $c->approveApplicant(Request::create('/', 'POST'), $applicant->id);
        Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job) => $job->subject === 'A place for Ada in JSS1!' && str_contains($job->body, 'Dear Ngozi Okafor'));

        // Timed emails only go to enrolled families, and only once.
        $this->assertSame(0, \App\Support\Onboarding::sendDue());
        $c->enroll(Request::create('/', 'POST'), $applicant->id);
        $this->assertSame(2, \App\Support\Onboarding::sendDue(), 'meet your teacher (7 days before, still within the week) and first-day reminder (3 days before)');
        $this->assertSame(0, \App\Support\Onboarding::sendDue());

        DB::table('onboarding_steps')->where('key', 'first_week')->update(['enabled' => false]);
        $this->assertStringContainsString('LETTER OF ADMISSION', \App\Support\Onboarding::letterHtml($applicant->fresh()));
        $this->assertStringContainsString('Ngozi Okafor', \App\Support\Onboarding::applicationFormHtml($applicant->fresh()));
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
