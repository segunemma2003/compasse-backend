<?php

namespace Tests\Feature;

use App\Models\OnlinePaymentIntent;
use App\Models\School;
use App\Services\OnlineFeePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A parent paid ₦50,000 through PayHub, Paystack said success, but the fee
 * stayed unpaid in Compasse: nobody asked PayHub. Confirming (return page,
 * finance Refresh, or the scheduled reconcile) now records the payment.
 */
class OnlineFeePaymentConfirmTest extends TestCase
{
    use RefreshDatabase;

    private OnlinePaymentIntent $intent;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'services.payhub.base_url' => 'http://payhub.internal',
            'services.payhub.admin_email' => 'a@payhub.example.com',
            'services.payhub.admin_password' => 'x',
        ]);

        if (! Schema::hasColumn('schools', 'payhub_company_id')) {
            Schema::table('schools', function ($t) {
                $t->string('payhub_company_id')->nullable();
                $t->text('payhub_api_key')->nullable();
            });
        }
        Schema::dropIfExists('fees');
        Schema::create('fees', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('student_id');
            $t->string('fee_type');
            $t->decimal('amount', 10, 2);
            $t->decimal('amount_paid', 10, 2)->default(0);
            $t->decimal('balance', 10, 2);
            $t->string('status')->default('pending');
            $t->timestamps();
        });
        Schema::dropIfExists('payments');
        Schema::create('payments', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('guardian_id')->nullable();
            $t->unsignedBigInteger('fee_id')->nullable();
            $t->decimal('amount', 10, 2);
            $t->string('payment_method');
            $t->string('payment_reference')->unique();
            $t->date('payment_date')->nullable();
            $t->string('status')->default('confirmed');
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::dropIfExists('online_payment_intents');
        Schema::create('online_payment_intents', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('fee_id')->nullable();
            $t->decimal('amount', 12, 2);
            $t->string('reference')->unique();
            $t->string('provider', 32)->default('paystack');
            $t->string('status')->default('pending');
            $t->unsignedBigInteger('payment_id')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
        });

        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        $school = School::create(['tenant_id' => 'test-tenant', 'name' => 'Demo School']);
        $school->update(['payhub_company_id' => 'co_1', 'payhub_api_key' => 'sk_test_1']);

        DB::table('fees')->insert(['id' => 4, 'school_id' => $school->id, 'student_id' => 4, 'fee_type' => 'tuition',
            'amount' => 50000, 'amount_paid' => 0, 'balance' => 50000, 'created_at' => now(), 'updated_at' => now()]);

        $this->intent = OnlinePaymentIntent::create([
            'school_id' => $school->id, 'student_id' => 4, 'fee_id' => 4, 'amount' => 50000,
            'reference' => 'phb_abc', 'provider' => 'payhub', 'status' => 'pending',
            'meta' => ['reason' => 'School fees – Tuition – Halima Yusuf', 'tracking_id' => 'FEE-4-DEMO-STU-004'],
        ]);
    }

    private function payhubSays(string $status): void
    {
        Http::fake(['payhub.internal/api/v1/charges/phb_abc/verify' => Http::response(['status' => $status, 'amount' => '50000.00', 'reference' => 'phb_abc'])]);
    }

    public function test_a_paid_charge_is_recorded_once_and_clears_the_fee(): void
    {
        $this->payhubSays('success');
        $service = app(OnlineFeePaymentService::class);

        $this->assertSame('success', $service->confirm($this->intent));
        $this->assertSame('success', $service->confirm($this->intent->fresh()), 'confirming again is a no-op');

        $this->assertSame(1, DB::table('payments')->count());
        $payment = DB::table('payments')->first();
        $this->assertStringContainsString('Halima Yusuf', $payment->notes);
        $this->assertStringContainsString('FEE-4-DEMO-STU-004', $payment->notes);

        $fee = DB::table('fees')->find(4);
        $this->assertSame('paid', $fee->status);
        $this->assertEquals(50000, (float) $fee->amount_paid);
    }

    public function test_refresh_reconciles_pending_payments(): void
    {
        $this->payhubSays('success');

        $counts = app(OnlineFeePaymentService::class)->reconcilePending();

        $this->assertSame(1, $counts['success']);
        $this->assertSame('success', $this->intent->fresh()->status);
    }

    public function test_an_unpaid_charge_stays_pending_and_a_failed_one_is_marked_failed(): void
    {
        Http::fake(['payhub.internal/api/v1/charges/phb_abc/verify' => Http::sequence()
            ->push(['status' => 'pending', 'amount' => '50000.00', 'reference' => 'phb_abc'])
            ->push(['status' => 'failed', 'amount' => '50000.00', 'reference' => 'phb_abc'])]);

        $this->assertSame('pending', app(OnlineFeePaymentService::class)->confirm($this->intent));
        $this->assertSame(0, DB::table('payments')->count());

        $this->assertSame('failed', app(OnlineFeePaymentService::class)->confirm($this->intent->fresh()));
        $this->assertSame('failed', $this->intent->fresh()->status);
    }
}
