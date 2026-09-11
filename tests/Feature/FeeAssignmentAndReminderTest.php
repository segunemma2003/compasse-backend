<?php

namespace Tests\Feature;

use App\Http\Controllers\FeeController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SchoolBankAccountController;
use App\Jobs\SendEmailJob;
use App\Models\School;
use App\Models\SchoolBankAccount;
use App\Modules\Financial\Models\Fee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers "the assign by class fails, it says balance field has no default"
 * and everything that turned up alongside it while root-causing that report:
 *   1. fees.balance is NOT NULL with no default, and neither
 *      FeeController::store() nor createFeeStructure() ever set it —
 *      every single fee creation crashed. The controller's own catch block
 *      swallows the exception into a plain JSON error without ever calling
 *      report()/Log::error(), which is why nothing showed up in the server
 *      logs even though every attempt was failing.
 *   2. FeeController::pay() *and* PaymentController::store() (the "Record
 *      Payment" button) both wrote payments.status = 'successful', which
 *      isn't a real value of that enum (pending/confirmed/failed/refunded),
 *      and both passed payment_reference straight through even though it's
 *      NOT NULL + unique — most cash/bank payments never come with one.
 *      Recording a payment crashed on either bug alone; neither path
 *      updated the fee's own amount_paid/balance columns either, which
 *      every other read path (summary/feeBreakdown/feeVoucher) reads
 *      directly. The same status/reference bug was also live in
 *      OnlineFeePaymentController::markIntentPaid() — meaning a
 *      successful online payment crashed recording it *after* the
 *      customer had already been charged.
 *   3. New: single/bulk fee reminder emails, with the school's bank
 *      account details attached so guardians know where to pay.
 */
class FeeAssignmentAndReminderTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private int $classId;
    private int $studentId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenant.subdomain' => 'test-tenant']);

        Schema::create('classes', function ($t) { $t->id(); $t->string('name'); });
        Schema::create('arms', function ($t) { $t->id(); $t->string('name'); });
        Schema::create('academic_years', function ($t) { $t->id(); $t->string('name'); });
        Schema::create('terms', function ($t) { $t->id(); $t->string('name'); });

        Schema::create('students', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('class_id')->nullable();
            $t->unsignedBigInteger('arm_id')->nullable();
            $t->string('first_name');
            $t->string('last_name');
            $t->string('email')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('guardians', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->string('first_name');
            $t->string('last_name');
            $t->string('email')->nullable();
            $t->timestamps();
        });

        Schema::create('guardian_students', function ($t) {
            $t->id();
            $t->unsignedBigInteger('guardian_id');
            $t->unsignedBigInteger('student_id');
            $t->string('relationship')->nullable();
            $t->boolean('is_primary')->default(false);
            $t->boolean('emergency_contact')->default(false);
            $t->timestamps();
        });

        Schema::create('fee_structures', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->string('name');
            $t->unsignedBigInteger('class_id')->nullable();
            $t->unsignedBigInteger('arm_id')->nullable();
            $t->unsignedBigInteger('academic_year_id')->nullable();
            $t->unsignedBigInteger('term_id')->nullable();
            $t->decimal('total_amount', 10, 2)->default(0);
            $t->string('frequency')->default('termly');
            $t->text('description')->nullable();
            $t->date('due_date')->nullable();
            $t->boolean('is_mandatory')->default(true);
            $t->string('status')->default('active');
            $t->timestamps();
        });

        Schema::create('fee_structure_items', function ($t) {
            $t->id();
            $t->unsignedBigInteger('fee_structure_id');
            $t->string('name');
            $t->decimal('amount', 10, 2);
            $t->timestamps();
        });

        // balance deliberately NOT NULL with no default, exactly like the
        // real migration, so an un-fixed FeeController would still crash
        // here the same way it did in production.
        Schema::create('fees', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('class_id')->nullable();
            $t->unsignedBigInteger('fee_structure_id')->nullable();
            $t->boolean('is_customized')->default(false);
            $t->unsignedBigInteger('academic_year_id')->nullable();
            $t->unsignedBigInteger('term_id')->nullable();
            $t->string('fee_type');
            $t->decimal('amount', 10, 2);
            $t->decimal('amount_paid', 10, 2)->default(0);
            $t->decimal('balance', 10, 2);
            $t->date('due_date')->nullable();
            $t->string('status')->default('pending');
            $t->text('description')->nullable();
            $t->timestamp('last_reminded_at')->nullable();
            $t->timestamps();
        });

        Schema::create('fee_items', function ($t) {
            $t->id();
            $t->unsignedBigInteger('fee_id');
            $t->string('name');
            $t->decimal('amount', 10, 2);
            $t->timestamps();
        });

        Schema::create('payments', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('guardian_id')->nullable();
            $t->unsignedBigInteger('fee_id')->nullable();
            $t->decimal('amount', 10, 2);
            $t->string('payment_method');
            // NOT NULL + unique, exactly like the real payments table
            // (2026_01_18_000001_create_financial_tables.php) — reproduces
            // the "payment_reference cannot be null" crash if pay()/store()
            // regress to passing null through when the caller omits one.
            $t->string('payment_reference')->unique();
            $t->date('payment_date')->nullable();
            $t->string('status')->default('confirmed');
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('school_bank_accounts', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->string('bank_name');
            $t->string('account_name');
            $t->string('account_number');
            $t->string('notes')->nullable();
            $t->boolean('is_primary')->default(false);
            $t->timestamps();
        });

        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->school = School::create(['tenant_id' => 'test-tenant', 'name' => 'Greenfield Academy']);

        $this->classId = DB::table('classes')->insertGetId(['name' => 'JSS1']);

        $this->studentId = DB::table('students')->insertGetId([
            'school_id' => $this->school->id,
            'class_id' => $this->classId,
            'first_name' => 'Ada',
            'last_name' => 'Okafor',
            'email' => 'ada@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeGuardian(string $email): int
    {
        $guardianId = DB::table('guardians')->insertGetId([
            'school_id' => $this->school->id,
            'first_name' => 'Ngozi',
            'last_name' => 'Okafor',
            'email' => $email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('guardian_students')->insert([
            'guardian_id' => $guardianId,
            'student_id' => $this->studentId,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $guardianId;
    }

    public function test_assign_fee_by_class_does_not_crash_on_balance_and_links_the_structure(): void
    {
        $request = Request::create('/', 'POST', [
            'name' => 'First Term Fees',
            'items' => [
                ['name' => 'Tuition', 'amount' => 50000],
                ['name' => 'Sports', 'amount' => 5000],
            ],
            'class_ids' => [$this->classId],
            'due_date' => now()->addMonth()->toDateString(),
            'academic_year_id' => 1,
            'term_id' => 1,
        ]);
        DB::table('academic_years')->insert(['id' => 1, 'name' => '2026/2027']);
        DB::table('terms')->insert(['id' => 1, 'name' => 'First Term']);

        $response = (new FeeController())->createFeeStructure($request);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $data = $response->getData(true);
        $this->assertSame(1, $data['fees_created']);

        $fee = Fee::where('student_id', $this->studentId)->first();
        $this->assertNotNull($fee, 'no Fee row was created for the student in the class');
        $this->assertSame('55000.00', $fee->balance, 'balance should start equal to the full amount');
        $this->assertSame('0.00', $fee->amount_paid);
        $this->assertSame($data['fee_structures'][0]['id'], $fee->fee_structure_id, 'fee_structure_id was not linked');
        $this->assertFalse((bool) $fee->is_customized);
    }

    public function test_assigning_a_single_fee_does_not_crash_on_balance(): void
    {
        DB::table('academic_years')->insert(['id' => 1, 'name' => '2026/2027']);
        DB::table('terms')->insert(['id' => 1, 'name' => 'First Term']);

        $response = (new FeeController())->store(Request::create('/', 'POST', [
            'student_id' => $this->studentId,
            'fee_type' => 'Uniform Fee',
            'amount' => 12000,
            'due_date' => now()->addWeek()->toDateString(),
            'academic_year_id' => 1,
            'term_id' => 1,
        ]));

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $fee = Fee::find($response->getData(true)['fee']['id']);
        $this->assertSame('12000.00', $fee->balance);
    }

    public function test_paying_a_fee_updates_balance_and_status_with_a_valid_payment_status(): void
    {
        $fee = Fee::create([
            'school_id' => $this->school->id,
            'student_id' => $this->studentId,
            'fee_type' => 'Tuition',
            'amount' => 10000,
            'amount_paid' => 0,
            'balance' => 10000,
            'due_date' => now()->addWeek(),
            'status' => 'pending',
        ]);

        $response = (new FeeController())->pay(Request::create('/', 'POST', [
            'amount' => 4000,
            'payment_method' => 'cash',
        ]), $fee->id);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $paymentRow = DB::table('payments')->where('fee_id', $fee->id)->first();
        // 'successful' was never a real payments.status value; this used to
        // crash here (or, in this schema-flexible test, would silently be
        // wrong forever since nothing else ever recognizes it).
        $this->assertSame('confirmed', $paymentRow->status);
        // payment_reference is NOT NULL + unique in the real table; no
        // reference was supplied, so one must have been auto-generated
        // rather than crashing the insert.
        $this->assertNotEmpty($paymentRow->payment_reference);

        $fee->refresh();
        $this->assertSame('4000.00', $fee->amount_paid);
        $this->assertSame('6000.00', $fee->balance);
        $this->assertSame('partial', $fee->status);

        // Paying off the rest should flip it to paid, and get its own
        // distinct auto-generated reference (no unique-constraint clash).
        $second = (new FeeController())->pay(Request::create('/', 'POST', [
            'amount' => 6000,
            'payment_method' => 'cash',
        ]), $fee->id);
        $this->assertSame(201, $second->getStatusCode(), $second->getContent());
        $fee->refresh();
        $this->assertSame('0.00', $fee->balance);
        $this->assertSame('paid', $fee->status);
    }

    public function test_recording_a_payment_via_payment_controller_applies_it_to_the_fee(): void
    {
        $fee = Fee::create([
            'school_id' => $this->school->id,
            'student_id' => $this->studentId,
            'fee_type' => 'Tuition',
            'amount' => 8000,
            'amount_paid' => 0,
            'balance' => 8000,
            'due_date' => now()->addWeek(),
            'status' => 'pending',
        ]);

        $guardianId = $this->makeGuardian('ngozi@example.test');

        // The "Record Payment" button's endpoint - three of the exact same
        // bugs as FeeController::pay() (bad status enum, null reference,
        // fee balance never applied), plus a fourth found live: it always
        // includes guardian_id in the insert, but that column never
        // actually existed on the payments table until the migration
        // paired with this test - every call crashed on that alone,
        // independent of whether a guardian_id was even provided.
        $response = (new PaymentController())->store(Request::create('/', 'POST', [
            'student_id' => $this->studentId,
            'guardian_id' => $guardianId,
            'fee_id' => $fee->id,
            'amount' => 8000,
            'payment_method' => 'bank_transfer',
        ]));

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $paymentRow = DB::table('payments')->where('fee_id', $fee->id)->first();
        $this->assertSame('confirmed', $paymentRow->status);
        $this->assertNotEmpty($paymentRow->payment_reference);
        $this->assertSame($guardianId, $paymentRow->guardian_id);

        $fee->refresh();
        $this->assertSame('8000.00', $fee->amount_paid);
        $this->assertSame('0.00', $fee->balance);
        $this->assertSame('paid', $fee->status);
    }

    public function test_recording_a_payment_with_no_guardian_still_works(): void
    {
        // Exactly the request shape that 500'd live: no guardian_id at all,
        // not even a null one explicitly passed.
        $response = (new PaymentController())->store(Request::create('/', 'POST', [
            'student_id' => $this->studentId,
            'amount' => 1500,
            'payment_method' => 'cash',
        ]));

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
    }

    public function test_reminding_a_single_fee_emails_guardian_with_bank_details(): void
    {
        Queue::fake();
        $this->makeGuardian('ngozi@example.test');
        SchoolBankAccount::create([
            'school_id' => $this->school->id,
            'bank_name' => 'GTBank',
            'account_name' => 'Greenfield Academy',
            'account_number' => '0123456789',
            'is_primary' => true,
        ]);

        $fee = Fee::create([
            'school_id' => $this->school->id,
            'student_id' => $this->studentId,
            'fee_type' => 'Tuition',
            'amount' => 10000,
            'amount_paid' => 0,
            'balance' => 10000,
            'due_date' => now()->addWeek(),
            'status' => 'pending',
        ]);

        $response = (new FeeController())->remind(Request::create('/', 'POST'), $fee->id);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        Queue::assertPushed(SendEmailJob::class, function (SendEmailJob $job) {
            return $job->to === 'ngozi@example.test'
                && str_contains($job->body, 'GTBank')
                && str_contains($job->body, '0123456789');
        });
        $this->assertNotNull($fee->fresh()->last_reminded_at);
    }

    public function test_reminding_a_fee_with_no_balance_is_rejected(): void
    {
        $fee = Fee::create([
            'school_id' => $this->school->id,
            'student_id' => $this->studentId,
            'fee_type' => 'Tuition',
            'amount' => 10000,
            'amount_paid' => 10000,
            'balance' => 0,
            'due_date' => now()->addWeek(),
            'status' => 'paid',
        ]);

        $response = (new FeeController())->remind(Request::create('/', 'POST'), $fee->id);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_bulk_reminder_only_emails_outstanding_fees(): void
    {
        Queue::fake();
        $this->makeGuardian('ngozi@example.test');

        $unpaid = Fee::create([
            'school_id' => $this->school->id, 'student_id' => $this->studentId,
            'fee_type' => 'Tuition', 'amount' => 10000, 'amount_paid' => 0, 'balance' => 10000,
            'due_date' => now()->addWeek(), 'status' => 'pending',
        ]);
        Fee::create([
            'school_id' => $this->school->id, 'student_id' => $this->studentId,
            'fee_type' => 'Sports', 'amount' => 5000, 'amount_paid' => 5000, 'balance' => 0,
            'due_date' => now()->addWeek(), 'status' => 'paid',
        ]);

        $response = (new FeeController())->remindBulk(Request::create('/', 'POST', [
            'school_id' => $this->school->id,
        ]));

        $data = $response->getData(true);
        $this->assertSame(1, $data['fees_matched'], 'the paid fee should not have matched');
        $this->assertSame(1, $data['fees_reminded']);
        Queue::assertPushed(SendEmailJob::class, 1);
        $this->assertNotNull($unpaid->fresh()->last_reminded_at);
    }

    public function test_bank_account_crud_keeps_exactly_one_primary(): void
    {
        $controller = new SchoolBankAccountController();

        $first = $controller->store(Request::create('/', 'POST', [
            'school_id' => $this->school->id,
            'bank_name' => 'GTBank',
            'account_name' => 'Greenfield Academy',
            'account_number' => '0123456789',
        ]))->getData(true)['bank_account'];
        $this->assertTrue($first['is_primary'], 'the first bank account added should default to primary');

        $second = $controller->store(Request::create('/', 'POST', [
            'school_id' => $this->school->id,
            'bank_name' => 'Zenith Bank',
            'account_name' => 'Greenfield Academy',
            'account_number' => '9876543210',
            'is_primary' => true,
        ]))->getData(true)['bank_account'];
        $this->assertTrue($second['is_primary']);

        $this->assertFalse(
            (bool) SchoolBankAccount::find($first['id'])->fresh()->is_primary,
            'setting a new primary should demote the old one'
        );

        $controller->destroy($second['id']);
        $this->assertTrue(
            (bool) SchoolBankAccount::find($first['id'])->fresh()->is_primary,
            'deleting the primary account should promote another one'
        );
    }
}
