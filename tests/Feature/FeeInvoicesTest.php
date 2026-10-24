<?php

namespace Tests\Feature;

use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\RecurringInvoiceController;
use App\Models\User;
use App\Modules\Financial\Models\Fee;
use App\Support\FeeInvoices;
use App\Support\RecurringInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Assigned fees show as invoices; class billing; delete rules; recurring billing plans. */
class FeeInvoicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $tables = [
            'users' => fn ($t) => [$t->id(), $t->string('name'), $t->string('email'), $t->string('password'), $t->string('role'), $t->timestamps(), $t->softDeletes()],
            'schools' => fn ($t) => [$t->id(), $t->string('name')->nullable(), $t->timestamps()],
            'classes' => fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()],
            'students' => fn ($t) => [$t->id(), $t->unsignedBigInteger('class_id')->nullable(), $t->unsignedBigInteger('arm_id')->nullable(), $t->string('first_name')->nullable(), $t->string('last_name')->nullable(), $t->string('admission_number')->nullable(), $t->string('status')->nullable(), $t->timestamps(), $t->softDeletes()],
            'terms' => fn ($t) => [$t->id(), $t->string('name'), $t->boolean('is_current')->default(false), $t->timestamps()],
            'academic_years' => fn ($t) => [$t->id(), $t->string('name'), $t->boolean('is_current')->default(false), $t->timestamps()],
            'fees' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id'), $t->unsignedBigInteger('student_id'), $t->unsignedBigInteger('class_id')->nullable(), $t->unsignedBigInteger('fee_structure_id')->nullable(), $t->boolean('is_customized')->default(false), $t->string('fee_type'), $t->decimal('amount', 12, 2), $t->decimal('amount_paid', 12, 2)->default(0), $t->decimal('balance', 12, 2)->default(0), $t->date('due_date')->nullable(), $t->string('status')->default('pending'), $t->text('description')->nullable(), $t->unsignedBigInteger('academic_year_id')->nullable(), $t->unsignedBigInteger('term_id')->nullable(), $t->timestamp('last_reminded_at')->nullable(), $t->timestamps()],
            'fee_items' => fn ($t) => [$t->id(), $t->unsignedBigInteger('fee_id'), $t->string('name'), $t->decimal('amount', 12, 2), $t->timestamps()],
            'invoices' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id'), $t->unsignedBigInteger('student_id'), $t->unsignedBigInteger('guardian_id')->nullable(), $t->string('invoice_number')->unique(), $t->date('invoice_date'), $t->date('due_date'), $t->decimal('subtotal', 10, 2)->default(0), $t->decimal('tax_amount', 10, 2)->default(0), $t->decimal('discount_amount', 10, 2)->default(0), $t->decimal('total_amount', 10, 2)->default(0), $t->string('status')->default('draft'), $t->string('payment_terms')->nullable(), $t->text('notes')->nullable(), $t->json('billing_address')->nullable(), $t->json('shipping_address')->nullable(), $t->unsignedBigInteger('created_by')->nullable(), $t->timestamp('sent_at')->nullable(), $t->timestamp('paid_at')->nullable(), $t->timestamp('cancelled_at')->nullable(), $t->string('cancellation_reason')->nullable(), $t->timestamps()],
            'invoice_items' => fn ($t) => [$t->id(), $t->unsignedBigInteger('invoice_id'), $t->string('description'), $t->decimal('quantity', 10, 2)->default(1), $t->decimal('unit_price', 10, 2)->default(0), $t->decimal('total_price', 10, 2)->default(0), $t->timestamps()],
            'audit_logs' => fn ($t) => [$t->id(), $t->string('subject_type')->nullable(), $t->unsignedBigInteger('subject_id')->nullable(), $t->string('action')->nullable(), $t->text('summary')->nullable(), $t->json('changes')->nullable(), $t->unsignedBigInteger('user_id')->nullable(), $t->string('user_name')->nullable(), $t->string('user_role')->nullable(), $t->timestamps()],
        ];
        foreach ($tables as $n => $d) {
            Schema::dropIfExists($n);
            Schema::create($n, $d);
        }
        DB::table('schools')->insert(['id' => 1, 'name' => 'Quiver']);
        DB::table('classes')->insert([['id' => 1, 'name' => 'Creche'], ['id' => 2, 'name' => 'Nursery 1']]);
        DB::table('terms')->insert(['id' => 1, 'name' => 'First Term', 'is_current' => true]);
        DB::table('academic_years')->insert(['id' => 1, 'name' => '2026/2027', 'is_current' => true]);
        DB::table('students')->insert([['id' => 1, 'class_id' => 1, 'first_name' => 'Ada'], ['id' => 2, 'class_id' => 1, 'first_name' => 'Bola'], ['id' => 3, 'class_id' => 2, 'first_name' => 'Chidi']]);
        foreach ([[1, 'school_admin'], [2, 'accountant']] as [$id, $role]) {
            User::forceCreate(['id' => $id, 'name' => "U{$id}", 'email' => "u{$id}@x.test", 'password' => 'x', 'role' => $role]);
        }
        // An existing fee from before this feature: the migration invoices it.
        DB::table('fees')->insert(['id' => 50, 'school_id' => 1, 'student_id' => 3, 'fee_type' => 'Tuition', 'amount' => 100000, 'balance' => 100000, 'due_date' => now()->addDays(10)->toDateString(), 'created_at' => now()]);
        (require database_path('migrations/tenant/2026_10_26_000001_link_invoices_to_fees.php'))->up();
        (require database_path('migrations/tenant/2026_10_26_000002_create_recurring_invoices.php'))->up();
    }

    private function req(int $uid, string $method = 'GET', array $data = []): Request
    {
        $u = User::find($uid);
        $this->actingAs($u);
        $r = Request::create('/', $method, $data);
        $r->setUserResolver(fn () => $u);

        return $r;
    }

    public function test_fees_become_invoices_and_stay_in_step(): void
    {
        $this->assertSame(1, DB::table('invoices')->where('fee_id', 50)->count(), 'existing fee invoiced by the migration');

        // A fee assigned the usual way, lines added afterwards.
        $fee = Fee::create(['school_id' => 1, 'student_id' => 1, 'class_id' => 1, 'fee_type' => 'Term fees', 'amount' => 150000, 'amount_paid' => 0, 'balance' => 150000, 'due_date' => now()->addWeek(), 'status' => 'pending']);
        $fee->items()->create(['name' => 'Tuition', 'amount' => 160000]);
        $fee->items()->create(['name' => 'Sibling discount', 'amount' => -10000]);
        FeeInvoices::flush();
        $inv = DB::table('invoices')->where('fee_id', $fee->id)->first();
        $this->assertSame('sent', $inv->status);
        $this->assertEquals(150000, $inv->total_amount);
        $this->assertEquals(10000, $inv->discount_amount);
        $this->assertSame(2, DB::table('invoice_items')->where('invoice_id', $inv->id)->count());

        // A part payment, then the rest.
        $fee->update(['amount_paid' => 50000, 'balance' => 100000, 'status' => 'partial']);
        FeeInvoices::flush();
        $this->assertSame('partial', DB::table('invoices')->where('fee_id', $fee->id)->value('status'));
        $fee->update(['amount_paid' => 150000, 'balance' => 0, 'status' => 'paid']);
        FeeInvoices::flush();
        $this->assertSame('paid', DB::table('invoices')->where('fee_id', $fee->id)->value('status'));

        // Deleting a paid invoice is refused; only the school administrator may delete at all.
        $c = new InvoiceController();
        $this->assertSame(403, $c->destroy($this->req(2, 'DELETE'), $inv->id)->getStatusCode());
        $this->assertSame(422, $c->destroy($this->req(1, 'DELETE', ['reason' => 'duplicate']), $inv->id)->getStatusCode());
        $unpaid = DB::table('invoices')->where('fee_id', 50)->value('id');
        $this->assertSame(200, $c->destroy($this->req(1, 'DELETE', ['reason' => 'billed twice']), $unpaid)->getStatusCode());
        $this->assertNull(DB::table('fees')->find(50), 'the fee goes with its invoice');
        $this->assertTrue(DB::table('audit_logs')->where('subject_type', 'invoice')->where('action', 'deleted')->exists());
    }

    public function test_invoice_a_whole_class(): void
    {
        $res = (new InvoiceController())->store($this->req(2, 'POST', [
            'class_ids' => [1], 'due_date' => now()->addWeek()->toDateString(), 'title' => 'Excursion',
            'items' => [['description' => 'Bus', 'quantity' => 1, 'unit_price' => 5000], ['description' => 'Lunch', 'quantity' => 2, 'unit_price' => 1500]],
        ]));
        $this->assertSame(201, $res->getStatusCode(), $res->getContent());
        $this->assertSame(2, $res->getData(true)['count']);
        $this->assertSame([1, 2], DB::table('fees')->where('fee_type', 'Excursion')->orderBy('student_id')->pluck('student_id')->map(fn ($i) => (int) $i)->all());
        $this->assertEquals(8000, DB::table('invoices')->where('student_id', 1)->value('total_amount'));
    }

    public function test_recurring_plans_bill_on_their_date(): void
    {
        $c = new RecurringInvoiceController();
        $c->store($this->req(2, 'POST', ['name' => 'Creche', 'lines' => [['name' => 'Creche care', 'amount' => 40000]], 'class_ids' => [1],
            'frequency' => 'monthly', 'next_run_on' => now()->addDays(3)->toDateString(), 'due_in_days' => 5, 'send_email' => false]));
        $this->assertSame(0, RecurringInvoices::runDue()['invoices'], 'not due yet');
        DB::table('recurring_invoices')->update(['next_run_on' => now()->toDateString()]);
        $this->assertSame(2, RecurringInvoices::runDue()['invoices']);
        $this->assertSame(2, DB::table('invoices')->where('total_amount', 40000)->count());
        $this->assertSame(now()->addMonthNoOverflow()->toDateString(), DB::table('recurring_invoices')->value('next_run_on'), 'next month');
        $this->assertSame(0, RecurringInvoices::runDue()['invoices'], 'not twice in a day');
    }
}
