<?php

namespace Tests\Feature;

use App\Http\Controllers\StaffAttendanceController;
use App\Jobs\SendSMSJob;
use App\Jobs\SendWhatsAppJob;
use App\Models\User;
use App\Support\ClockNotify;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Review fixes: shifts (morning / afternoon resumption), staff see only their own attendance,
 * a child's clock-in alert to one or both parents by SMS and WhatsApp, and every SMS kept in the
 * school's SMS log with what the provider said.
 */
class ReviewFixesAttendanceSmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'users' => fn ($t) => [$t->id(), $t->string('name'), $t->string('email'), $t->string('password'), $t->string('role'), $t->string('phone')->nullable(), $t->string('status')->nullable(), $t->string('attendance_shift', 40)->nullable(), $t->string('clock_pin_hash', 64)->nullable(), $t->text('clock_pin')->nullable(), $t->timestamps(), $t->softDeletes()],
            'schools' => fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()],
            'settings' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('key'), $t->text('value')->nullable(), $t->string('type')->nullable(), $t->string('category')->nullable(), $t->timestamps()],
            'departments' => fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()],
            'teachers' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id'), $t->unsignedBigInteger('department_id')->nullable(), $t->string('employee_id')->nullable(), $t->string('phone')->nullable(), $t->timestamps(), $t->softDeletes()],
            'staff' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id'), $t->string('department')->nullable(), $t->string('employee_id')->nullable(), $t->string('phone')->nullable(), $t->timestamps(), $t->softDeletes()],
            'students' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->unsignedBigInteger('class_id')->nullable(), $t->string('admission_number')->nullable(), $t->string('first_name')->nullable(), $t->string('last_name')->nullable(), $t->timestamps(), $t->softDeletes()],
            'guardian_students' => fn ($t) => [$t->id(), $t->unsignedBigInteger('guardian_id'), $t->unsignedBigInteger('student_id'), $t->string('relationship')->nullable(), $t->boolean('is_primary')->default(false), $t->boolean('emergency_contact')->default(false), $t->timestamps()],
            'guardians' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->string('email')->nullable(), $t->string('phone')->nullable(), $t->timestamps(), $t->softDeletes()],
            'staff_duties' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id'), $t->date('week_start'), $t->string('duty')->nullable(), $t->text('notes')->nullable(), $t->timestamps()],
            'attendances' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('attendanceable_type'), $t->unsignedBigInteger('attendanceable_id'), $t->date('date'), $t->string('status')->default('present'), $t->time('check_in_time')->nullable(), $t->time('check_out_time')->nullable(), $t->text('notes')->nullable(), $t->unsignedBigInteger('marked_by')->nullable(), $t->decimal('total_hours', 5, 2)->nullable(), $t->integer('break_duration')->nullable(), $t->string('location')->nullable(), $t->string('device_info')->nullable(), $t->string('ip_address')->nullable(), $t->boolean('is_late')->default(false), $t->integer('late_minutes')->nullable(), $t->boolean('is_absent')->default(false), $t->boolean('is_excused')->default(false), $t->timestamps()],
        ] as $n => $d) {
            Schema::dropIfExists($n);
            Schema::create($n, $d);
        }
        (require database_path('migrations/tenant/2026_11_21_100000_create_sms_logs_table.php'))->up();

        DB::table('schools')->insert(['id' => 1, 'name' => 'Quiver']);
        foreach ([[1, 'school_admin'], [2, 'teacher'], [3, 'care_assistant'], [4, 'guardian'], [5, 'student'], [6, 'guardian']] as [$id, $role]) {
            User::forceCreate(['id' => $id, 'name' => "U{$id}", 'email' => "u{$id}@x.test", 'password' => 'x', 'role' => $role]);
        }
        DB::table('teachers')->insert(['id' => 9, 'user_id' => 2]);
        DB::table('staff')->insert(['user_id' => 3, 'department' => 'PRESCHOOL']);
        DB::table('students')->insert(['id' => 7, 'user_id' => 5, 'first_name' => 'Ada']);
        DB::table('guardians')->insert([['id' => 1, 'user_id' => 4, 'phone' => '08030000001'], ['id' => 2, 'user_id' => 6, 'phone' => '08030000002']]);
        DB::table('guardian_students')->insert([
            ['guardian_id' => 2, 'student_id' => 7, 'relationship' => 'mother', 'is_primary' => true],
            ['guardian_id' => 1, 'student_id' => 7, 'relationship' => 'father', 'is_primary' => false],
        ]);
    }

    private function req(int $uid, string $method = 'GET', array $data = []): Request
    {
        $u = User::find($uid);
        $this->actingAs($u);
        $r = Request::create('/', $method, $data);
        $r->setUserResolver(fn () => $u);
        app()->instance('request', $r);

        return $r;
    }

    private function setting(string $key, string $value): void
    {
        DB::table('settings')->insert(['school_id' => 1, 'key' => $key, 'value' => $value]);
    }

    public function test_each_person_is_late_after_their_own_shift(): void
    {
        $c = app(StaffAttendanceController::class);
        $c->qrUpdate($this->req(1, 'POST', ['shifts' => [['name' => 'Afternoon', 'start' => '13:00'], ['name' => 'Morning', 'start' => '07:00']]]));
        $this->assertSame([['name' => 'Morning', 'start' => '07:00'], ['name' => 'Afternoon', 'start' => '13:00']], StaffAttendanceController::shifts());

        $day = '2026-11-20';
        // No shift set: the one starting nearest the clock-in. 12:50 is early for the afternoon, 13:10 is 10 minutes late.
        $c->mark($this->req(1, 'POST', ['user_id' => 2, 'date' => $day, 'action' => 'in', 'time' => '12:50']));
        $c->mark($this->req(1, 'POST', ['user_id' => 3, 'date' => $day, 'action' => 'in', 'time' => '13:10']));
        $rows = collect($c->index($this->req(1, 'GET', ['date' => $day]))->getData(true)['staff'])->keyBy('user_id');
        $this->assertSame('present', $rows[2]['record']['status']);
        $this->assertSame('late', $rows[3]['record']['status']);
        $this->assertSame(10, $rows[3]['record']['late_minutes']);

        // Put on the morning shift, the same 12:50 is late by 350 minutes.
        $this->assertSame(200, $c->assignShift($this->req(1, 'POST', ['user_ids' => [2], 'shift' => 'Morning']))->getStatusCode());
        $this->assertSame(422, $c->assignShift($this->req(1, 'POST', ['user_ids' => [2], 'shift' => 'Night']))->getStatusCode(), 'unknown shift');
        $rows = collect($c->index($this->req(1, 'GET', ['date' => $day]))->getData(true)['staff'])->keyBy('user_id');
        $this->assertSame('late', $rows[2]['record']['status']);
        $this->assertSame(350, $rows[2]['record']['late_minutes']);
        $this->assertSame('Morning', $rows[2]['shift']);
    }

    public function test_staff_see_only_their_own_attendance(): void
    {
        $c = app(StaffAttendanceController::class);
        $day = '2026-11-20';
        $c->mark($this->req(1, 'POST', ['user_id' => 3, 'date' => $day, 'action' => 'in', 'time' => '07:40']));

        $mine = $c->index($this->req(2, 'GET', ['date' => $day]))->getData(true);
        $this->assertSame([2], array_column($mine['staff'], 'user_id'), 'a teacher not on duty sees only their own row');
        $this->assertSame([2], array_column($c->report($this->req(2, 'GET', ['from' => $day, 'to' => $day]))->getData(true)['staff'], 'user_id'));

        $all = $c->index($this->req(1, 'GET', ['date' => $day]))->getData(true);
        $this->assertSame([1, 2, 3], array_column($all['staff'], 'user_id'), 'leadership sees everyone');
    }

    public function test_childs_clock_in_goes_to_both_parents_or_the_main_contact_by_sms_and_whatsapp(): void
    {
        Queue::fake();
        $this->setting('sms_provider', 'smslive247');
        $this->setting('sms_api_key', 'key');
        $this->setting('whatsapp_token', 'tok');
        $this->setting('whatsapp_phone_number_id', '123');
        $student = \App\Models\Student::find(7);

        ClockNotify::sendStudent($student, 'clock_in', '07:45');
        Queue::assertPushed(SendSMSJob::class, 2);
        Queue::assertPushed(SendWhatsAppJob::class, 2);

        Queue::fake();
        $this->setting('notify_clock_parents', 'primary');
        ClockNotify::sendStudent($student, 'clock_in', '07:45');
        Queue::assertPushed(SendSMSJob::class, 1);
        Queue::assertPushed(SendSMSJob::class, fn ($j) => $j->to === '08030000002' && str_contains($j->message, 'Ada arrived'));
        Queue::assertPushed(SendWhatsAppJob::class, fn ($j) => $j->to === '08030000002');

        Queue::fake();
        $this->setting('notify_clock_whatsapp', '0');
        ClockNotify::sendStudent($student, 'clock_in', '07:45');
        Queue::assertNotPushed(SendWhatsAppJob::class);
    }

    public function test_sms_refused_for_credit_is_logged_once_and_shown_to_the_test_button(): void
    {
        $this->setting('sms_provider', 'smslive247');
        $this->setting('sms_api_key', 'key');
        Http::fake(['api.smslive247.com/*' => Http::sequence()
            ->push(['code' => 500, 'message' => 'Insufficient credits to complete this request. Please top up your account.'], 500)
            ->push(['status' => 'ok'], 200)]);

        // Straight from a request (Settings → test SMS): the provider's message reaches the school.
        try {
            (new SendSMSJob('08030000001', 'Hi', 'QUIVER', '1'))->handle();
            $this->fail('expected the provider error');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Insufficient credits', $e->getMessage());
        }
        $this->assertTrue(SendSMSJob::isPermanent($e->getMessage()));
        $this->assertFalse(SendSMSJob::isPermanent('cURL error 28: Connection timed out'));

        // The queued job's final failure lands in the SMS log, with the reason.
        (new SendSMSJob('08030000001', 'Hi', 'QUIVER', '1'))->failed($e);
        $row = DB::table('sms_logs')->first();
        $this->assertSame('failed', $row->status);
        $this->assertSame('smslive247', $row->provider);
        $this->assertStringContainsString('Insufficient credits', $row->error);

        (new SendSMSJob('08030000001', 'Hi again', 'QUIVER', '1'))->handle();
        $this->assertSame(['failed', 'sent'], DB::table('sms_logs')->orderBy('id')->pluck('status')->all());
    }
}
