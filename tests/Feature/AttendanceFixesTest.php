<?php

namespace Tests\Feature;

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\ClassController;
use App\Http\Controllers\TermAttendanceSummaryController;
use App\Models\School;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Term attendance totals entered by class teachers (and used on report
 * cards), clock-in, the notifications table, and class section detection.
 */
class AttendanceFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('users');
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role')->default('school_admin');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::dropIfExists('students');
        Schema::create('students', function ($t) {
            $t->id();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->unsignedBigInteger('arm_id')->nullable();
            $t->string('first_name');
            $t->string('last_name');
            $t->string('admission_number')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
            $t->softDeletes();
        });
        foreach (['terms'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, function ($t) {
                $t->id();
                $t->unsignedBigInteger('academic_year_id')->nullable();
                $t->string('name');
                $t->date('start_date')->nullable();
                $t->date('end_date')->nullable();
                $t->boolean('is_current')->default(false);
                $t->timestamps();
            });
        }
        Schema::dropIfExists('attendances');
        Schema::create('attendances', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->unsignedBigInteger('attendanceable_id');
            $t->string('attendanceable_type');
            $t->date('date');
            $t->string('status');
            $t->timestamps();
        });
        $migration = require database_path('migrations/tenant/2026_10_13_000001_create_term_attendance_summaries_table.php');
        $migration->up();

        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        School::create(['tenant_id' => 'test-tenant', 'name' => 'Demo School']);
        DB::table('terms')->insert(['id' => 1, 'name' => 'First Term', 'start_date' => '2026-09-01', 'end_date' => '2026-12-15']);
        DB::table('students')->insert([
            ['id' => 1, 'class_id' => 5, 'first_name' => 'Ada', 'last_name' => 'Eze', 'admission_number' => 'A1', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'class_id' => 5, 'first_name' => 'Ben', 'last_name' => 'Obi', 'admission_number' => 'A2', 'created_at' => now(), 'updated_at' => now()],
        ]);
        // Ben has two days on the daily register.
        DB::table('attendances')->insert([
            ['attendanceable_id' => 2, 'attendanceable_type' => \App\Models\Student::class, 'date' => '2026-09-02', 'status' => 'present', 'created_at' => now(), 'updated_at' => now()],
            ['attendanceable_id' => 2, 'attendanceable_type' => \App\Models\Student::class, 'date' => '2026-09-03', 'status' => 'absent', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'role' => 'school_admin']);
        $this->actingAs($this->admin);
    }

    private function request(string $method, array $data): Request
    {
        $r = Request::create('/', $method, $data);
        $r->setUserResolver(fn () => $this->admin);

        return $r;
    }

    public function test_class_teacher_totals_are_saved_and_win_on_the_report_card(): void
    {
        $res = (new TermAttendanceSummaryController())->save($this->request('PUT', [
            'class_id' => 5, 'term_id' => 1,
            'rows' => [['student_id' => 1, 'days_opened' => 62, 'days_present' => 60]],
        ]));
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());

        $term = Term::find(1);
        $this->assertSame(['opened' => 62, 'present' => 60, 'absent' => 2], TermAttendanceSummaryController::forReportCard(1, $term),
            'absent defaults to opened − present');
        $this->assertSame(['opened' => 2, 'present' => 1, 'absent' => 1], TermAttendanceSummaryController::forReportCard(2, $term),
            'no totals entered: fall back to the daily register');

        $list = (new TermAttendanceSummaryController())->index($this->request('GET', ['class_id' => 5, 'term_id' => 1]))->getData(true);
        $this->assertCount(2, $list['students']);
        $this->assertTrue($list['students'][0]['entered']);
    }

    public function test_impossible_totals_are_rejected(): void
    {
        $res = (new TermAttendanceSummaryController())->save($this->request('PUT', [
            'class_id' => 5, 'term_id' => 1,
            'rows' => [['student_id' => 1, 'days_opened' => 10, 'days_present' => 8, 'days_absent' => 5]],
        ]));
        $this->assertSame(422, $res->getStatusCode());
    }

    public function test_notifications_table_gets_the_columns_the_app_uses(): void
    {
        Schema::dropIfExists('notifications');
        Schema::create('notifications', function ($t) {
            $t->id();
            $t->morphs('notifiable');
            $t->string('type');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
        (require database_path('migrations/tenant/2026_10_13_000002_align_notifications_table_with_model.php'))->up();

        $n = \App\Models\Notification::create(['user_id' => $this->admin->id, 'title' => 'Hi', 'message' => 'Hello', 'type' => 'info']);
        $this->assertSame(1, \App\Models\Notification::where('user_id', $this->admin->id)->count());
        $this->assertFalse((bool) $n->fresh()->is_read);
    }

    public function test_section_is_detected_from_class_level_names(): void
    {
        $this->assertSame('nursery', ClassController::sectionTypeFromName('PRE SCHOOL'));
        $this->assertSame('nursery', ClassController::sectionTypeFromName('Creche'));
        $this->assertSame('nursery', ClassController::sectionTypeFromName('Toddler'));
        $this->assertSame('primary', ClassController::sectionTypeFromName('PRIMARY'));
        $this->assertSame('junior_secondary', ClassController::sectionTypeFromName('JSS'));
        $this->assertSame('senior_secondary', ClassController::sectionTypeFromName('Senior Secondary'));
        $this->assertNull(ClassController::sectionTypeFromName('Special Unit'));
    }
}
