<?php

namespace Tests\Feature;

use App\Jobs\ProcessBulkUploadJob;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A school deleted staff it had uploaded by mistake, then re-uploaded the
 * same people as teachers: every row failed "Email already exists" because
 * the deleted staff's login accounts still held those emails. Re-uploading
 * now reclaims accounts nobody is using and restores deleted records, and
 * still refuses emails of people who are actually on the books.
 */
class BulkUploadReuploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('users');
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role')->default('staff');
            $t->string('status')->default('active');
            $t->timestamp('email_verified_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        foreach (['students', 'teachers', 'staff'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, function ($t) {
                $t->id();
                $t->unsignedBigInteger('school_id')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('first_name')->nullable();
                $t->string('last_name')->nullable();
                $t->string('email')->unique();
                $t->string('employee_id')->nullable()->unique();
                $t->string('status')->default('active');
                $t->timestamps();
                $t->softDeletes();
            });
        }
    }

    private function invoke(string $method, ...$args)
    {
        $m = new ReflectionMethod(ProcessBulkUploadJob::class, $method);
        $m->setAccessible(true);

        return $m->invoke(new ProcessBulkUploadJob(1), ...$args);
    }

    public function test_a_leftover_account_from_a_deleted_staff_member_is_reclaimed(): void
    {
        $orphan = User::create(['name' => 'Old', 'email' => 'ruth@example.test', 'password' => 'x', 'role' => 'staff']);

        $user = $this->invoke('claimUser', 'ruth@example.test', 'Ruth Okegbuna', 'Teacher@123', 'teacher');

        $this->assertSame($orphan->id, $user->id);
        $this->assertSame('teacher', $user->fresh()->role);
        $this->assertSame('Ruth Okegbuna', $user->fresh()->name);
    }

    public function test_a_soft_deleted_account_is_restored_instead_of_a_duplicate_key_error(): void
    {
        $deleted = User::create(['name' => 'Old', 'email' => 'ruth@example.test', 'password' => 'x', 'role' => 'teacher']);
        $deleted->delete();

        $user = $this->invoke('claimUser', 'ruth@example.test', 'Ruth', 'Teacher@123', 'teacher');

        $this->assertSame($deleted->id, $user->id);
        $this->assertFalse($user->fresh()->trashed());
    }

    public function test_someone_already_on_the_books_is_skipped_not_failed(): void
    {
        $user = User::create(['name' => 'Live', 'email' => 'live@example.test', 'password' => 'x', 'role' => 'teacher']);
        DB::table('teachers')->insert(['user_id' => $user->id, 'email' => 'live@example.test', 'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(\App\Jobs\SkippedRow::class);
        $this->expectExceptionMessage('live@example.test already has an account');
        $this->invoke('claimUser', 'live@example.test', 'Someone', 'Teacher@123', 'teacher');
    }

    public function test_an_existing_live_teacher_row_is_skipped(): void
    {
        Teacher::create(['email' => 'ruth@example.test', 'first_name' => 'Ruth']);

        $this->expectException(\App\Jobs\SkippedRow::class);
        $this->invoke('createOrRestore', Teacher::class, ['email' => 'ruth@example.test', 'first_name' => 'Ruth'], 'employee_id', false);
    }

    public function test_school_admin_accounts_are_never_reclaimed(): void
    {
        User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'role' => 'school_admin']);

        // A failure (it's someone else's admin login), not a quiet skip.
        $this->expectExceptionMessage('belongs to an administrator account');
        $this->invoke('claimUser', 'admin@example.test', 'Someone', 'Teacher@123', 'teacher');
    }

    public function test_a_deleted_teacher_is_restored_keeping_their_employee_id(): void
    {
        $old = Teacher::create(['email' => 'ruth@example.test', 'first_name' => 'Old', 'employee_id' => 'THETE0001']);
        $old->delete();

        $teacher = $this->invoke('createOrRestore', Teacher::class,
            ['email' => 'ruth@example.test', 'first_name' => 'Ruth', 'employee_id' => 'THETE0009'], 'employee_id', false);

        $this->assertSame($old->id, $teacher->id);
        $this->assertSame('THETE0001', $teacher->fresh()->employee_id);
        $this->assertSame('Ruth', $teacher->fresh()->first_name);
        $this->assertSame(1, Teacher::count());
    }

    public function test_free_email_skips_addresses_held_by_deleted_accounts(): void
    {
        User::create(['name' => 'X', 'email' => 'ada.eze1@school.test', 'password' => 'x'])->delete();

        $email = $this->invoke('freeEmail', 'Ada', 'Eze', 1, 'school.test');

        $this->assertNotSame('ada.eze1@school.test', $email);
    }

    public function test_bulk_uploaded_people_get_their_own_password_by_email(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        DB::table('tenants')->insert(['id' => 't1', 'created_at' => now(), 'updated_at' => now()]);
        $school = \App\Models\School::create(['tenant_id' => 't1', 'name' => 'The Quiver School']);

        $password = $this->invoke('newPassword');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{10}$/', $password);
        $this->assertNotSame($password, $this->invoke('newPassword'), 'each person gets a different password');

        $user = $this->invoke('claimUser', 'ruth@example.test', 'Ruth', $password, 'teacher');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($password, $user->fresh()->password));

        $this->invoke('emailLoginDetails', $school, 'ruth@example.test', 'Ruth', $password, 'teacher');

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendEmailJob::class, function ($job) use ($password) {
            return $job->to === 'ruth@example.test'
                && $job->subject === 'Your Teacher Login Credentials'
                && str_contains($job->body, $password)
                && str_contains($job->body, 'The Quiver School');
        });
    }

    public function test_a_student_without_email_gets_their_login_sent_to_the_parent(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        DB::table('tenants')->insert(['id' => 't2', 'created_at' => now(), 'updated_at' => now()]);
        $school = \App\Models\School::create(['tenant_id' => 't2', 'name' => 'The Quiver School']);

        $this->invoke('emailLoginDetails', $school, 'desire.adeyemo1@quiver.compasse.net', 'Desire Adeyemo',
            'Pw12345678', 'student', 'mum@example.test', 'Mrs Adeyemo');

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendEmailJob::class, function ($job) {
            return $job->to === 'mum@example.test'
                && $job->subject === 'Student Login Credentials for Desire Adeyemo'
                && str_contains($job->body, 'Hello Mrs Adeyemo')
                && str_contains($job->body, 'desire.adeyemo1@quiver.compasse.net')
                && str_contains($job->body, 'Pw12345678');
        });
    }
}
