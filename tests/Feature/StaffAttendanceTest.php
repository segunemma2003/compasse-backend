<?php

namespace Tests\Feature;

use App\Http\Controllers\StaffAttendanceController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Staff attendance covers non-teaching staff too; the QR code clocks staff and students in and out. */
class StaffAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'users' => fn ($t) => [$t->id(), $t->string('name'), $t->string('email'), $t->string('password'), $t->string('role'), $t->string('phone')->nullable(), $t->string('status')->nullable(), $t->timestamps(), $t->softDeletes()],
            'schools' => fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()],
            'settings' => fn ($t) => [$t->id(), $t->string('key'), $t->text('value')->nullable(), $t->timestamps()],
            'departments' => fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()],
            'teachers' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id'), $t->unsignedBigInteger('department_id')->nullable(), $t->string('employee_id')->nullable(), $t->string('phone')->nullable(), $t->timestamps(), $t->softDeletes()],
            'staff' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id'), $t->string('department')->nullable(), $t->string('employee_id')->nullable(), $t->string('phone')->nullable(), $t->timestamps(), $t->softDeletes()],
            'students' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->unsignedBigInteger('class_id')->nullable(), $t->string('first_name')->nullable(), $t->string('last_name')->nullable(), $t->timestamps(), $t->softDeletes()],
            'guardian_students' => fn ($t) => [$t->id(), $t->unsignedBigInteger('guardian_id'), $t->unsignedBigInteger('student_id'), $t->string('relationship')->nullable(), $t->boolean('is_primary')->default(false), $t->boolean('emergency_contact')->default(false), $t->timestamps()],
            'guardians' => fn ($t) => [$t->id(), $t->string('email')->nullable(), $t->string('phone')->nullable(), $t->timestamps(), $t->softDeletes()],
            'attendances' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('attendanceable_type'), $t->unsignedBigInteger('attendanceable_id'), $t->date('date'), $t->string('status')->default('present'), $t->time('check_in_time')->nullable(), $t->time('check_out_time')->nullable(), $t->text('notes')->nullable(), $t->unsignedBigInteger('marked_by')->nullable(), $t->decimal('total_hours', 5, 2)->nullable(), $t->integer('break_duration')->nullable(), $t->string('location')->nullable(), $t->string('device_info')->nullable(), $t->string('ip_address')->nullable(), $t->boolean('is_late')->default(false), $t->integer('late_minutes')->nullable(), $t->boolean('is_absent')->default(false), $t->boolean('is_excused')->default(false), $t->timestamps()],
        ] as $n => $d) {
            Schema::dropIfExists($n);
            Schema::create($n, $d);
        }
        DB::table('schools')->insert(['id' => 1, 'name' => 'Quiver']);
        DB::table('departments')->insert(['id' => 1, 'name' => 'Primary']);
        foreach ([[1, 'school_admin'], [2, 'teacher'], [3, 'care_assistant'], [4, 'guardian'], [5, 'student']] as [$id, $role]) {
            User::forceCreate(['id' => $id, 'name' => "U{$id}", 'email' => "u{$id}@x.test", 'password' => 'x', 'role' => $role]);
        }
        DB::table('teachers')->insert(['id' => 9, 'user_id' => 2, 'department_id' => 1]);
        DB::table('staff')->insert(['user_id' => 3, 'department' => 'PRESCHOOL']);
        DB::table('students')->insert(['id' => 7, 'user_id' => 5, 'first_name' => 'Ada']);
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

    public function test_lists_all_staff_and_records_teachers_and_non_teaching_staff(): void
    {
        $c = app(StaffAttendanceController::class);
        $day = '2026-10-28';
        $this->assertSame(200, $c->mark($this->req(1, 'POST', ['user_id' => 2, 'date' => $day, 'action' => 'in', 'time' => '07:50']))->getStatusCode());
        $this->assertSame(200, $c->mark($this->req(1, 'POST', ['user_id' => 3, 'date' => $day, 'action' => 'in', 'time' => '08:20']))->getStatusCode());
        $this->assertSame(200, $c->mark($this->req(1, 'POST', ['user_id' => 3, 'date' => $day, 'action' => 'out', 'time' => '16:20']))->getStatusCode());
        $this->assertSame(422, $c->mark($this->req(1, 'POST', ['user_id' => 2, 'date' => $day, 'action' => 'out', 'time' => '07:00']))->getStatusCode(), 'out before in');

        // The teacher is kept on their Teacher record, the care assistant on their user account.
        $this->assertSame('App\Models\Teacher', DB::table('attendances')->where('attendanceable_id', 9)->value('attendanceable_type'));
        $this->assertSame('07:50:00', DB::table('attendances')->where('attendanceable_id', 9)->value('check_in_time'));

        $list = $c->index($this->req(1, 'GET', ['date' => $day]))->getData(true);
        $this->assertSame(['U1', 'U2', 'U3'], array_column($list['staff'], 'name'), 'guardians and students are not staff');
        $byName = collect($list['staff'])->keyBy('name');
        $this->assertSame('present', $byName['U2']['record']['status']);
        $this->assertSame('Primary', $byName['U2']['department']);
        $this->assertSame('late', $byName['U3']['record']['status']);
        $this->assertSame(20, $byName['U3']['record']['late_minutes']);
        $this->assertSame(8.0, (float) $byName['U3']['record']['hours']);
        $this->assertSame(['total' => 3, 'present' => 2, 'late' => 1, 'absent' => 0, 'not_marked' => 1], $list['summary']);

        // A teacher who is not on duty may only record themselves.
        $this->assertSame(403, $c->mark($this->req(2, 'POST', ['user_id' => 3, 'date' => $day, 'action' => 'absent']))->getStatusCode());

        $rep = $c->report($this->req(1, 'GET', ['from' => '2026-10-26', 'to' => '2026-10-30']))->getData(true);
        $this->assertCount(5, $rep['school_days']);
        $u3 = collect($rep['staff'])->firstWhere('name', 'U3');
        $this->assertSame([1, 1, 0, 4], [$u3['present'], $u3['late'], $u3['absent'], $u3['not_recorded']]);
        $this->assertSame('08:20', $u3['average_arrival']);
        $this->assertSame('L', $u3['daily'][$day]);
    }

    public function test_qr_scan_clocks_staff_and_students_in_then_out(): void
    {
        $c = app(StaffAttendanceController::class);
        $key = $c->qr($this->req(1))->getData(true)['key'];
        $this->assertSame(403, $c->qr($this->req(2))->getStatusCode(), 'teachers cannot see the code');

        $this->assertSame(422, $c->qrClock($this->req(3, 'POST', ['key' => 'wrong']))->getStatusCode());

        $this->travelTo(\Carbon\Carbon::parse('2026-11-04 07:45', 'Africa/Lagos'));
        $r = $c->qrClock($this->req(3, 'POST', ['key' => $key]))->getData(true);
        $this->assertSame('in', $r['action']);
        $this->travelTo(\Carbon\Carbon::parse('2026-11-04 16:05', 'Africa/Lagos'));
        $r = $c->qrClock($this->req(3, 'POST', ['key' => $key]))->getData(true);
        $this->assertSame('out', $r['action']);
        $this->assertSame('16:05:00', DB::table('attendances')->where('attendanceable_type', User::class)->value('check_out_time'));
        $this->assertSame('done', $c->qrClock($this->req(3, 'POST', ['key' => $key]))->getData(true)['action']);

        $this->travelTo(\Carbon\Carbon::parse('2026-11-04 07:30', 'Africa/Lagos'));
        $this->assertSame('in', $c->qrClock($this->req(5, 'POST', ['key' => $key]))->getData(true)['action']);
        $this->assertSame('07:30:00', DB::table('attendances')->where('attendanceable_type', 'App\Models\Student')->where('attendanceable_id', 7)->value('check_in_time'));

        $this->assertSame(403, $c->qrClock($this->req(4, 'POST', ['key' => $key]))->getStatusCode(), 'parents');

        // A new code stops the old one working.
        $c->qrUpdate($this->req(1, 'POST', ['rotate' => true]));
        $this->assertSame(422, $c->qrClock($this->req(2, 'POST', ['key' => $key]))->getStatusCode());
    }
}
