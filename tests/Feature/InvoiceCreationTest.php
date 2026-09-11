<?php

namespace Tests\Feature;

use App\Http\Controllers\InvoiceController;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers "can't create multiple items for the invoice" and what that report
 * led to: every invoice creation — regardless of item count — 500'd once the
 * response tried to serialize, because:
 *   1. Invoice::payments() assumes payments.invoice_id, a column that never
 *      existed (payments predates invoices; it only ever had fee_id).
 *   2. Even with that column added, getPaidAmount() filtered on a payment
 *      status of 'completed', which isn't one of the four real enum values
 *      (pending/confirmed/failed/refunded) — paid amounts always summed 0.
 *   3. InvoiceItem::$fillable listed 'total' instead of the real column
 *      'total_price', so every saved line item's total was silently
 *      dropped back to the column's 0 default.
 *   4. Invoice::isOverdue() read $this->status, which re-enters the very
 *      accessor (getStatusAttribute()) that calls isOverdue() — infinite
 *      recursion, previously masked because bugs 1/2 threw first.
 */
class InvoiceCreationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private int $studentId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenant.subdomain' => 'test-tenant']);

        Schema::create('students', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->string('first_name');
            $t->string('last_name');
            $t->string('email')->unique();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('guardians', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->timestamps();
        });

        Schema::create('invoices', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('guardian_id')->nullable();
            $t->string('invoice_number')->unique();
            $t->date('invoice_date');
            $t->date('due_date');
            $t->decimal('subtotal', 10, 2)->default(0);
            $t->decimal('tax_amount', 10, 2)->default(0);
            $t->decimal('discount_amount', 10, 2)->default(0);
            $t->decimal('total_amount', 10, 2)->default(0);
            $t->string('status')->default('draft');
            $t->string('payment_terms')->nullable();
            $t->text('notes')->nullable();
            $t->json('billing_address')->nullable();
            $t->json('shipping_address')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancellation_reason')->nullable();
            $t->timestamps();
        });

        Schema::create('invoice_items', function ($t) {
            $t->id();
            $t->unsignedBigInteger('invoice_id');
            $t->string('description');
            $t->decimal('quantity', 10, 2)->default(1);
            $t->decimal('unit_price', 10, 2)->default(0);
            $t->decimal('total_price', 10, 2)->default(0);
            $t->timestamps();
        });

        // Mirrors the real payments table (see
        // 2026_01_18_000001_create_financial_tables.php) plus the
        // invoice_id column added by this fix's migration.
        Schema::create('payments', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('fee_id')->nullable();
            $t->unsignedBigInteger('invoice_id')->nullable();
            $t->string('payment_reference')->unique();
            $t->decimal('amount', 10, 2);
            $t->string('payment_method');
            $t->date('payment_date');
            $t->string('status')->default('confirmed');
            $t->timestamps();
        });

        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->school = School::create(['tenant_id' => 'test-tenant', 'name' => 'Greenfield Academy']);

        $this->studentId = DB::table('students')->insertGetId([
            'school_id' => $this->school->id,
            'first_name' => 'Ada',
            'last_name' => 'Okafor',
            'email' => 'ada@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::create([
            'tenant_id' => 'test-tenant',
            'name' => 'Bursar',
            'email' => 'bursar@example.test',
            'password' => bcrypt('secret'),
            'role' => 'school_admin',
        ]);
        $this->actingAs($user);
    }

    private function makeRequest(array $items, array $overrides = []): Request
    {
        $request = Request::create('/', 'POST', array_merge([
            'student_id' => $this->studentId,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'items' => $items,
        ], $overrides));
        $request->attributes->set('school', $this->school);

        return $request;
    }

    public function test_creating_an_invoice_with_multiple_line_items_does_not_crash(): void
    {
        $response = (new InvoiceController())->store($this->makeRequest([
            ['description' => 'Tuition', 'quantity' => 1, 'unit_price' => 50000],
            ['description' => 'Books', 'quantity' => 2, 'unit_price' => 3000],
            ['description' => 'Uniform', 'quantity' => 1, 'unit_price' => 7500],
        ]));

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = $response->getData(true);
        $this->assertSame('Invoice created', $data['message']);
        $this->assertSame(63500.0, (float) $data['invoice']['total_amount']);
        $this->assertCount(3, $data['invoice']['items']);

        // getStatusAttribute()/isOverdue() must not recurse into a fatal
        // "Maximum function nesting level" / stack overflow.
        $this->assertSame('draft', $data['invoice']['status']);
    }

    public function test_line_item_total_price_is_persisted_correctly(): void
    {
        $response = (new InvoiceController())->store($this->makeRequest([
            ['description' => 'Books', 'quantity' => 2, 'unit_price' => 3000],
        ]));

        $itemId = $response->getData(true)['invoice']['items'][0]['id'];
        $item = InvoiceItem::find($itemId);

        // Was silently 0 before InvoiceItem::$fillable included total_price.
        $this->assertSame('6000.00', $item->total_price);
    }

    public function test_invoice_response_reflects_confirmed_payments_as_paid_amount(): void
    {
        $response = (new InvoiceController())->store($this->makeRequest([
            ['description' => 'Tuition', 'quantity' => 1, 'unit_price' => 10000],
        ]));
        $invoiceId = $response->getData(true)['invoice']['id'];
        $invoice = Invoice::find($invoiceId);

        DB::table('payments')->insert([
            'school_id' => $this->school->id,
            'student_id' => $this->studentId,
            'invoice_id' => $invoiceId,
            'payment_reference' => 'PMT-TEST-1',
            'amount' => 10000,
            'payment_method' => 'cash',
            'payment_date' => now()->toDateString(),
            'status' => 'confirmed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Was always 0 before: 'completed' isn't a real payments.status
        // value, and before the migration this query 500'd outright.
        $this->assertSame(10000.0, $invoice->getPaidAmount());
        $this->assertTrue($invoice->fresh()->isPaid());
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_creating_an_invoice_without_items_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        (new InvoiceController())->store($this->makeRequest([]));
    }
}
