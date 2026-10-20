<?php

namespace Tests\Feature;

use App\Http\Controllers\CareRecordController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\RequisitionController;
use App\Models\User;
use App\Support\ReceiptDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Pay slip lines + salary sheet upload + logged edit/delete; the school forms (who sees, edits and
 * deletes what); imprest / requisition flow; the receipt's amount in words.
 */
class SchoolFormsPayrollRequisitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $tables = [
            'users' => fn ($t) => [$t->id(), $t->string('name'), $t->string('email'), $t->string('username')->nullable(), $t->string('password'), $t->string('role'), $t->boolean('email_generated')->default(false), $t->timestamps(), $t->softDeletes()],
            'schools' => fn ($t) => [$t->id(), $t->string('name')->nullable(), $t->text('settings')->nullable(), $t->timestamps()],
            'academic_years' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('name'), $t->boolean('is_current')->default(false), $t->timestamps()],
            'classes' => fn ($t) => [$t->id(), $t->string('name'), $t->unsignedBigInteger('class_teacher_id')->nullable(), $t->timestamps()],
            'subjects' => fn ($t) => [$t->id(), $t->unsignedBigInteger('class_id')->nullable(), $t->string('name'), $t->boolean('is_optional')->nullable(), $t->timestamps()],
            'teacher_subjects' => fn ($t) => [$t->id(), $t->unsignedBigInteger('teacher_id'), $t->unsignedBigInteger('subject_id'), $t->unsignedBigInteger('class_id')->nullable(), $t->string('status')->default('active'), $t->timestamps()],
            'teachers' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->string('employee_id')->nullable(), $t->unsignedBigInteger('department_id')->nullable(), $t->timestamps(), $t->softDeletes()],
            'students' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->unsignedBigInteger('class_id')->nullable(), $t->string('first_name'), $t->string('last_name'), $t->string('status')->nullable(), $t->date('date_of_birth')->nullable(), $t->string('gender')->nullable(), $t->string('admission_number')->nullable(), $t->timestamps(), $t->softDeletes()],
            'guardians' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->string('first_name')->nullable(), $t->string('last_name')->nullable(), $t->string('email')->nullable(), $t->timestamps()],
            'guardian_students' => fn ($t) => [$t->id(), $t->unsignedBigInteger('guardian_id'), $t->unsignedBigInteger('student_id'), $t->timestamps()],
            'notifications' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id'), $t->string('title'), $t->text('message')->nullable(), $t->string('type')->nullable(), $t->boolean('is_read')->default(false), $t->timestamps()],
            'expenses' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('description'), $t->decimal('amount', 14, 2), $t->string('category'), $t->date('date'), $t->string('payment_method')->nullable(), $t->string('vendor')->nullable(), $t->string('receipt_number')->nullable(), $t->unsignedBigInteger('approved_by')->nullable(), $t->unsignedBigInteger('recorded_by')->nullable(), $t->text('notes')->nullable(), $t->string('status')->nullable(), $t->timestamps()],
            'payrolls' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id'), $t->unsignedBigInteger('staff_id'), $t->unsignedBigInteger('academic_year_id')->nullable(), $t->integer('month'), $t->integer('year'), $t->decimal('basic_salary', 12, 2), $t->decimal('allowances', 12, 2)->default(0), $t->decimal('deductions', 12, 2)->default(0), $t->decimal('net_salary', 12, 2), $t->date('payment_date')->nullable(), $t->string('payment_method')->nullable(), $t->string('status')->default('pending'), $t->text('notes')->nullable(), $t->unsignedBigInteger('processed_by')->nullable(), $t->timestamps()],
        ];
        foreach ($tables as $n => $def) {
            Schema::dropIfExists($n);
            Schema::create($n, $def);
        }
        (require database_path('migrations/tenant/2026_10_21_000001_care_records_requisitions_payroll_lines.php'))->up();

        DB::table('schools')->insert(['id' => 1, 'name' => 'Quiver']);
        DB::table('academic_years')->insert(['id' => 1, 'school_id' => 1, 'name' => '2026/2027', 'is_current' => true]);
        // 1 admin, 2 teacher (class 1), 3 teacher (class 2), 4 parent of Ada, 5 parent of Ben, 6 accountant
        foreach ([[1, 'school_admin', 'Admin'], [2, 'teacher', 'Tola Teacher'], [3, 'teacher', 'Uche Teacher'], [4, 'parent', 'Mrs Ada'], [5, 'parent', 'Mr Ben'], [6, 'accountant', 'Bursar Bisi']] as [$id, $role, $name]) {
            User::forceCreate(['id' => $id, 'name' => $name, 'email' => "u{$id}@quiver.com", 'password' => 'x', 'role' => $role]);
        }
        DB::table('teachers')->insert([['id' => 1, 'user_id' => 2, 'employee_id' => 'QT001'], ['id' => 2, 'user_id' => 3, 'employee_id' => 'QT002']]);
        DB::table('classes')->insert([['id' => 1, 'name' => 'Toddler Two', 'class_teacher_id' => 1], ['id' => 2, 'name' => 'Nursery One', 'class_teacher_id' => 2]]);
        DB::table('students')->insert([
            ['id' => 1, 'class_id' => 1, 'first_name' => 'Ada', 'last_name' => 'O', 'date_of_birth' => '2023-05-01', 'gender' => 'female'],
            ['id' => 2, 'class_id' => 2, 'first_name' => 'Ben', 'last_name' => 'E', 'date_of_birth' => null, 'gender' => 'male'],
        ]);
        DB::table('guardians')->insert([['id' => 1, 'user_id' => 4], ['id' => 2, 'user_id' => 5]]);
        DB::table('guardian_students')->insert([['guardian_id' => 1, 'student_id' => 1], ['guardian_id' => 2, 'student_id' => 2]]);
    }

    private function req(int $uid, string $method = 'GET', array $data = [], array $files = []): Request
    {
        $u = User::find($uid);
        $this->actingAs($u);
        $r = Request::create('/', $method, $data, [], $files);
        $r->setUserResolver(fn () => $u);

        return $r;
    }

    public function test_payroll_lines_logged_edit_delete_and_salary_sheet(): void
    {
        $c = new PayrollController();
        $res = $c->store($this->req(1, 'POST', ['staff_id' => 2, 'month' => 10, 'year' => 2026, 'basic_salary' => 100000, 'items' => [
            ['name' => 'Rewards / Bonus', 'type' => 'earning', 'amount' => 5000],
            ['name' => 'PAYE (tax)', 'type' => 'deduction', 'amount' => 7000],
            ['name' => 'Pension', 'type' => 'deduction', 'amount' => 8000],
            ['name' => 'Staff welfare', 'type' => 'deduction', 'amount' => 0],
        ]]));
        $this->assertSame(201, $res->getStatusCode(), $res->getContent());
        $p = $res->getData(true)['payroll'];
        $this->assertEquals([5000, 15000, 90000], [(float) $p['allowances'], (float) $p['deductions'], (float) $p['net_salary']]);
        $this->assertCount(3, $p['items'], 'zero lines dropped');

        // Paid slips can still be edited and deleted by admin / finance — and it is logged.
        $c->update($this->req(1, 'PUT', ['status' => 'paid', 'payment_date' => '2026-10-28']), (string) $p['id']);
        $this->assertSame(200, $c->update($this->req(6, 'PUT', ['basic_salary' => 120000]), (string) $p['id'])->getStatusCode());
        $this->assertEquals(110000, (float) DB::table('payrolls')->value('net_salary'), 'lines kept, net recalculated');
        $this->assertSame(403, $c->update($this->req(2, 'PUT', ['basic_salary' => 1]), (string) $p['id'])->getStatusCode());
        $this->assertSame(403, $c->destroy($this->req(2, 'DELETE'), (string) $p['id'])->getStatusCode());
        $this->assertSame(200, $c->destroy($this->req(6, 'DELETE', ['reason' => 'Duplicate']), (string) $p['id'])->getStatusCode());
        $log = $c->history($this->req(1), (string) $p['id'])->getData(true)['history'];
        $this->assertSame(['deleted', 'updated', 'updated', 'created'], array_column($log, 'action'));
        $this->assertSame('Bursar Bisi', $log[0]['user_name']);
        $this->assertStringContainsString('Duplicate', $log[0]['summary']);
        $this->assertEquals([100000, 120000], array_map('floatval', $log[1]['changes']['basic_salary']));

        // Salary sheet: by staff ID, email and name; unknown people reported, not guessed.
        $csv = "Staff ID or Email,Name,Basic Salary,PAYE (tax),Pension,Loan deduction,Rewards / Bonus,Notes\n"
            . "QT001,,\"150,000\",10000,12000,,5000,\n"
            . "u3@quiver.com,,90000,,7200,5000,,October\n"
            . ",Bursar Bisi,80000,,,,,\n"
            . "nobody@x.com,Ghost,1000,,,,,\n";
        $res = $c->import($this->req(1, 'POST', ['month' => 11, 'year' => 2026], ['file' => UploadedFile::fake()->createWithContent('salaries.csv', $csv)]));
        $out = $res->getData(true);
        $this->assertSame([3, 0], [$out['created'], $out['updated']], $res->getContent());
        $this->assertCount(1, $out['errors']);
        $tola = DB::table('payrolls')->where('staff_id', 2)->where('month', 11)->first();
        $this->assertEquals([150000, 5000, 22000, 133000], [(float) $tola->basic_salary, (float) $tola->allowances, (float) $tola->deductions, (float) $tola->net_salary]);
        $this->assertSame('October', DB::table('payrolls')->where('staff_id', 3)->where('month', 11)->value('notes'));

        // Uploading again updates the month's slips instead of duplicating them.
        $again = $c->import($this->req(1, 'POST', ['month' => 11, 'year' => 2026], ['file' => UploadedFile::fake()->createWithContent('s.csv', "Staff ID or Email,Basic Salary\nQT001,160000\n")]))->getData(true);
        $this->assertSame([0, 1], [$again['created'], $again['updated']]);
        $this->assertSame(3, DB::table('payrolls')->where('month', 11)->count());

        $tpl = $c->importTemplate($this->req(1, 'GET', ['month' => 11, 'year' => 2026]))->getContent();
        $this->assertStringContainsString('Basic Salary', $tpl);
        $this->assertStringContainsString('QT001,"Tola Teacher",160000', $tpl);
        $this->assertStringNotContainsString('Mrs Ada', $tpl, 'parents are not on the payroll');
    }

    public function test_school_forms_scoping_reply_edit_and_delete(): void
    {
        $c = new CareRecordController();
        $ouch = ['type' => 'ouch_report', 'student_id' => 1, 'record_date' => '2026-10-20', 'data' => ['time' => '10:15', 'cause' => 'Slipped at play', 'injury' => 'Grazed knee', 'first_aid' => 'Cleaned, plaster', 'body_marks' => ['front' => [['x' => 40, 'y' => 80]]]]];
        $res = $c->store($this->req(2, 'POST', $ouch));
        $this->assertSame(201, $res->getStatusCode(), $res->getContent());
        $id = $res->getData(true)['record']['id'];
        $this->assertSame('00001', $res->getData(true)['record']['number']);
        $this->assertSame(403, $c->store($this->req(2, 'POST', ['student_id' => 2] + $ouch))->getStatusCode(), 'not my class');
        $this->assertSame(1, DB::table('notifications')->where('user_id', 1)->count(), 'admin told');
        $this->assertSame(1, DB::table('notifications')->where('user_id', 4)->count(), "Ada's parent told");
        $this->assertSame(0, DB::table('notifications')->where('user_id', 5)->count());

        $this->assertCount(1, $c->index($this->req(4))->getData(true)['data']);
        $this->assertCount(0, $c->index($this->req(5))->getData(true)['data']);
        $this->assertCount(0, $c->index($this->req(3))->getData(true)['data'], "another class's teacher");
        $show = $c->show($this->req(4), $id)->getData(true)['record'];
        $this->assertSame(3, $show['student']['age']);
        $this->assertTrue($show['can_respond']);
        $this->assertSame(200, $c->respond($this->req(4, 'POST', ['comment' => 'Thank you', 'relationship' => 'Mother']), $id)->getStatusCode());
        $this->assertSame(403, $c->respond($this->req(5, 'POST', ['comment' => 'x']), $id)->getStatusCode());

        // Edits are logged; only the admin deletes.
        $this->assertSame(200, $c->update($this->req(2, 'PUT', ['data' => ['injury' => 'Grazed left knee'] + $ouch['data']]), $id)->getStatusCode());
        $this->assertSame(403, $c->update($this->req(4, 'PUT', ['data' => []]), $id)->getStatusCode());
        $this->assertSame(403, $c->destroy($this->req(2, 'DELETE'), $id)->getStatusCode());
        $this->assertSame(200, $c->destroy($this->req(1, 'DELETE'), $id)->getStatusCode());
        $log = $c->history($this->req(1), $id)->getData(true)['history'];
        $this->assertSame(['deleted', 'updated', 'parent_response', 'created'], array_column($log, 'action'));
        $this->assertSame(['Grazed knee', 'Grazed left knee'], $log[1]['changes']['injury']);

        // Parents fill in the medicine form, not the others; staff log doses on it.
        $this->assertSame(403, $c->store($this->req(4, 'POST', $ouch))->getStatusCode());
        $med = $c->store($this->req(4, 'POST', ['type' => 'medicine_form', 'student_id' => 1, 'data' => ['medication' => 'Acepol', 'dosage' => '10ml', 'consent' => ['name' => 'Mrs Ada', 'relationship' => 'Mother']]]))->getData(true)['record']['id'];
        $this->assertSame(403, $c->store($this->req(4, 'POST', ['type' => 'medicine_form', 'student_id' => 2, 'data' => []]))->getStatusCode(), 'not her child');
        $this->assertSame(200, $c->update($this->req(2, 'PUT', ['data' => ['medication' => 'Acepol', 'doses' => [['date' => '2026-10-20', 'time' => '14:00', 'dose' => '10ml', 'given_by' => 'Tola']]]]), $med)->getStatusCode());
        $this->assertSame(403, $c->update($this->req(4, 'PUT', ['data' => ['medication' => 'Other']]), $med)->getStatusCode(), 'locked once a dose is given');

        // School-wide reports: the author and leadership only.
        $sd = $c->store($this->req(2, 'POST', ['type' => 'school_daily_report', 'data' => ['highlights' => 'Sports day']]))->getData(true)['record']['id'];
        $this->assertSame(403, $c->show($this->req(3), $sd)->getStatusCode());
        $this->assertSame(200, $c->show($this->req(1), $sd)->getStatusCode());
        $this->assertSame(403, $c->store($this->req(3, 'POST', ['type' => 'class_daily_report', 'class_id' => 1, 'data' => []]))->getStatusCode());
    }

    public function test_imprest_flow_and_receipt_words(): void
    {
        $c = new RequisitionController();
        $res = $c->store($this->req(2, 'POST', ['type' => 'imprest', 'title' => 'Craft materials', 'items' => [
            ['description' => 'Crayons', 'quantity' => 10, 'unit_cost' => 500], ['description' => 'Glue', 'quantity' => 4, 'unit_cost' => 750],
        ]]));
        $this->assertSame(201, $res->getStatusCode(), $res->getContent());
        $r = $res->getData(true)['requisition'];
        $this->assertEquals(8000, (float) $r['amount']);
        $id = $r['id'];
        $this->assertSame('IMP-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), DB::table('requisitions')->value('number'));

        $this->assertSame(403, $c->decide($this->req(2, 'POST', ['decision' => 'approve']), $id)->getStatusCode());
        $this->assertCount(0, $c->index($this->req(3))->getData(true)['data'], "other staff don't see it");
        $this->assertSame(200, $c->decide($this->req(1, 'POST', ['decision' => 'approve', 'amount_approved' => 7000]), $id)->getStatusCode());
        $this->assertSame(200, $c->disburse($this->req(6, 'POST', ['method' => 'cash']), $id)->getStatusCode());
        $this->assertEquals(7000, (float) DB::table('expenses')->value('amount'));
        $this->assertSame(200, $c->retire($this->req(2, 'POST', ['amount_spent' => 6200, 'note' => 'Receipts attached']), $id)->getStatusCode());
        $this->assertEquals([6200, 800], [(float) DB::table('requisitions')->value('amount_spent'), (float) DB::table('requisitions')->value('amount_returned')]);
        $this->assertEquals(6200, (float) DB::table('expenses')->value('amount'), 'expense = what was spent');

        $this->assertSame(403, $c->update($this->req(2, 'PUT', ['title' => 'x']), $id)->getStatusCode(), 'decided: admin / finance only');
        $this->assertSame(403, $c->destroy($this->req(2, 'DELETE'), $id)->getStatusCode());
        $this->assertSame(200, $c->destroy($this->req(6, 'DELETE'), $id)->getStatusCode());
        $actions = array_column($c->history($this->req(1))->getData(true)['history'], 'action');
        $this->assertSame(['deleted', 'retired', 'disbursed', 'approved', 'created'], $actions);

        $this->assertSame('Fifty Thousand Naira, Twenty Kobo Only', ReceiptDocument::words(50000.20));
        $this->assertSame('One Million, Five Hundred and One Naira Only', ReceiptDocument::words(1000501));
        $this->assertSame(['1,250', '05'], ReceiptDocument::split(1250.05));
    }
}
