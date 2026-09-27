<?php

namespace Tests\Feature;

use App\Http\Controllers\SubscriptionController;
use App\Models\Plan;
use App\Models\School;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentIntent;
use App\Services\FlutterwaveService;
use App\Services\PaystackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Make sure subscriptions renewal go through" — traced the payment ->
 * webhook/verify -> activatePlanForSchool() path and found it never
 * actually renews: whenever a school already has an active subscription
 * (the normal case for a renewal, as opposed to a brand-new sign-up),
 * activatePlanForSchool() unconditionally calls upgradeSubscription() -
 * which only exists to swap plan/features when moving to a genuinely
 * different plan, and deliberately never touches start_date/end_date/
 * status. Paying to renew the SAME plan while still active would
 * therefore succeed (money moves, a completed SubscriptionPaymentIntent
 * is recorded) while silently leaving the subscription expiring on its
 * original date - indistinguishable from a no-op to anyone but someone
 * checking end_date before and after.
 *
 * Fixed by treating "paid for the plan they already have, while active"
 * as a renewal (calls SubscriptionService::renewSubscription()) rather
 * than an upgrade, and by having renewSubscription() extend from
 * whichever is later - now, or the current end_date - so renewing a few
 * days before expiry doesn't forfeit the remaining paid-for days to a
 * cycle that restarts from today.
 */
class SubscriptionRenewalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same rationale as ExpireSubscriptionsTest: cache invalidation
        // resolves the tenant-side school via a real tenancy switch, which
        // needs a genuinely provisioned tenant database this test has no
        // use for — not what's under test here.
        // upgradeSubscription() (exercised by the "different plan" test below)
        // separately does its own real tenancy switch via
        // tenantSchoolFromCentralSchool() to mirror the new modules onto the
        // tenant-side school row — same "no real tenant DB in this test"
        // problem as the cache invalidation calls, stubbed the same way.
        $this->partialMock(\App\Services\SubscriptionService::class, function ($mock) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('invalidateCache')->andReturnNull();
            $mock->shouldReceive('invalidateCacheForSubscription')->andReturnNull();
            $mock->shouldReceive('tenantSchoolFromCentralSchool')->andReturnNull();
            // createSubscription() (the already-expired-subscription test)
            // mirrors the plan's modules onto the tenant-side school's
            // settings column — real, useful behaviour, but there's no real
            // tenant database here for it to write into either.
            $mock->shouldReceive('updateSchoolModules')->andReturnNull();
        });
    }

    private function makeSchoolAndPlan(): array
    {
        DB::table('tenants')->insert(['id' => 'test-tenant-' . uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        $tenantId = DB::table('tenants')->orderByDesc('id')->value('id');
        $school = School::create(['tenant_id' => $tenantId, 'name' => 'Test School']);
        $plan = Plan::create(['name' => 'Basic', 'type' => 'basic', 'price' => 5000, 'billing_cycle' => 'monthly']);

        return [$school, $plan];
    }

    private function verifyPaymentRequest(School $school, SubscriptionPaymentIntent $intent, PaystackService $paystack): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/', 'POST', ['reference' => $intent->reference]);
        $request->attributes->set('school', $school);

        return (new SubscriptionController(app(\App\Services\SubscriptionService::class)))
            ->verifyPayment($request, $paystack, app(FlutterwaveService::class));
    }

    public function test_renewing_the_same_plan_while_active_extends_end_date_and_stays_active(): void
    {
        [$school, $plan] = $this->makeSchoolAndPlan();

        $subscription = Subscription::create([
            'school_id' => $school->id, 'plan_id' => $plan->id, 'status' => 'active',
            'start_date' => now()->subDays(20), 'end_date' => now()->addDays(10),
            'billing_cycle' => 'monthly', 'amount' => 5000, 'currency' => 'NGN',
        ]);
        $originalEndDate = $subscription->end_date->copy();

        $intent = SubscriptionPaymentIntent::create([
            'school_id' => $school->id, 'plan_id' => $plan->id, 'amount' => 5000,
            'currency' => 'NGN', 'reference' => 'ref_renew_1', 'provider' => 'paystack', 'status' => 'pending',
        ]);

        $paystack = $this->partialMock(PaystackService::class, function ($mock) use ($intent) {
            $mock->shouldReceive('verify')->with($intent->reference)->andReturn([
                'status' => 'success', 'amount' => 5000, 'reference' => $intent->reference,
            ]);
        });

        $response = $this->verifyPaymentRequest($school, $intent, $paystack);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $subscription->refresh();
        $this->assertSame('active', $subscription->status);
        $this->assertTrue(
            $subscription->end_date->greaterThan($originalEndDate),
            'renewing must push end_date forward, not leave it untouched'
        );
        // Renewed 10 days early on a monthly plan -> should keep those 10
        // days, landing at original end_date + 1 month, not now() + 1 month.
        $this->assertEqualsWithDelta(
            $originalEndDate->copy()->addMonth()->timestamp, $subscription->end_date->timestamp,
            5, 'early renewal must extend from the current end_date, not forfeit the remaining days'
        );
    }

    public function test_renewing_an_already_expired_subscription_gives_a_fresh_full_cycle_from_today(): void
    {
        [$school, $plan] = $this->makeSchoolAndPlan();

        // Note: an already-*expired* subscription (status flipped by the
        // subscriptions:expire sweep) is a different code path — it's no
        // longer `status = active`, so activatePlanForSchool() falls
        // through to createSubscription() rather than renew/upgrade. Covered
        // here for completeness: this must also land as active with a full
        // fresh cycle from today, not from the stale past end_date.
        Subscription::create([
            'school_id' => $school->id, 'plan_id' => $plan->id, 'status' => 'expired',
            'start_date' => now()->subMonths(2), 'end_date' => now()->subDays(5),
            'billing_cycle' => 'monthly', 'amount' => 5000, 'currency' => 'NGN',
        ]);

        $intent = SubscriptionPaymentIntent::create([
            'school_id' => $school->id, 'plan_id' => $plan->id, 'amount' => 5000,
            'currency' => 'NGN', 'reference' => 'ref_renew_2', 'provider' => 'paystack', 'status' => 'pending',
        ]);

        $paystack = $this->partialMock(PaystackService::class, function ($mock) use ($intent) {
            $mock->shouldReceive('verify')->with($intent->reference)->andReturn([
                'status' => 'success', 'amount' => 5000, 'reference' => $intent->reference,
            ]);
        });

        $response = $this->verifyPaymentRequest($school, $intent, $paystack);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $new = Subscription::where('school_id', $school->id)->where('status', 'active')->first();
        $this->assertNotNull($new, 'an expired subscription must be reactivatable by paying again');
        $this->assertTrue($new->end_date->isFuture());
    }

    public function test_paying_for_a_genuinely_different_plan_while_active_still_upgrades_not_renews(): void
    {
        [$school, $oldPlan] = $this->makeSchoolAndPlan();
        $newPlan = Plan::create(['name' => 'Premium', 'type' => 'premium', 'price' => 15000, 'billing_cycle' => 'monthly']);

        $subscription = Subscription::create([
            'school_id' => $school->id, 'plan_id' => $oldPlan->id, 'status' => 'active',
            'start_date' => now()->subDays(20), 'end_date' => now()->addDays(10),
            'billing_cycle' => 'monthly', 'amount' => 5000, 'currency' => 'NGN',
        ]);
        $originalEndDate = $subscription->end_date->copy();

        $intent = SubscriptionPaymentIntent::create([
            'school_id' => $school->id, 'plan_id' => $newPlan->id, 'amount' => 15000,
            'currency' => 'NGN', 'reference' => 'ref_upgrade_1', 'provider' => 'paystack', 'status' => 'pending',
        ]);

        $paystack = $this->partialMock(PaystackService::class, function ($mock) use ($intent) {
            $mock->shouldReceive('verify')->with($intent->reference)->andReturn([
                'status' => 'success', 'amount' => 15000, 'reference' => $intent->reference,
            ]);
        });

        $response = $this->verifyPaymentRequest($school, $intent, $paystack);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $subscription->refresh();
        $this->assertSame($newPlan->id, $subscription->plan_id, 'plan must switch to the one actually paid for');
        $this->assertEquals(
            $originalEndDate->timestamp, $subscription->end_date->timestamp,
            'an upgrade to a different plan must keep the existing billing anniversary, unlike a renewal'
        );
    }
}
