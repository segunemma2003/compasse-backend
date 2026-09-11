<?php

namespace Tests\Feature;

use App\Http\Controllers\SubscriptionController;
use App\Models\Plan;
use App\Models\School;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression test for the production bug found 2026-09-12: a subscription's
 * `status` column never moved off 'active' once end_date passed — nothing
 * ever wrote to it. Subscription::isActive()/getStatus() are expiry-aware
 * (module access was never actually left open), but the super-admin
 * Subscriptions list read the raw column directly, so 3 of 7 real tenants
 * showed as "active" days to months after actually expiring. Confirmed live
 * before this fix: subscriptions.status stayed 'active' with end_date in the
 * past on rolexcollegeiba, christ and highstone.
 */
class ExpireSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // SubscriptionService::invalidateCache() resolves the *tenant-side*
        // copy of a school for cache-key purposes by fully switching
        // tenancy to it (tenancy()->initialize()) — real, useful behaviour
        // against a real provisioned tenant, but there's no such thing here
        // (this test's "tenants" are bare rows, not real provisioned
        // databases), so it fails trying to open a database file that was
        // never created. Cache-key scoping isn't what's under test — stub
        // it out rather than fight tenancy internals for it.
        $this->partialMock(\App\Services\SubscriptionService::class, function ($mock) {
            $mock->shouldReceive('invalidateCache')->andReturnNull();
            $mock->shouldReceive('invalidateCacheForSubscription')->andReturnNull();
        });
    }

    private function makeSubscription(School $school, Plan $plan, string $status, \DateTimeInterface $endDate): Subscription
    {
        return Subscription::create([
            'school_id' => $school->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'start_date' => now()->subYear(),
            'end_date' => $endDate,
            'billing_cycle' => 'monthly',
            'amount' => 5000,
            'currency' => 'NGN',
        ]);
    }

    private function makeSchoolAndPlan(): array
    {
        DB::table('tenants')->insert(['id' => 'test-tenant-' . uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        $tenantId = DB::table('tenants')->orderByDesc('id')->value('id');
        $school = School::create(['tenant_id' => $tenantId, 'name' => 'Test School']);
        $plan = Plan::create(['name' => 'Basic', 'type' => 'basic', 'price' => 5000, 'billing_cycle' => 'monthly']);
        return [$school, $plan];
    }

    public function test_command_expires_active_subscriptions_past_their_end_date(): void
    {
        [$school, $plan] = $this->makeSchoolAndPlan();
        $stale = $this->makeSubscription($school, $plan, 'active', now()->subMonths(4));

        [$school2, $plan2] = $this->makeSchoolAndPlan();
        $current = $this->makeSubscription($school2, $plan2, 'active', now()->addMonths(2));

        Artisan::call('subscriptions:expire');

        $this->assertSame('expired', $stale->fresh()->status);
        $this->assertSame('active', $current->fresh()->status, 'a subscription that has not expired yet must not be touched');
    }

    public function test_command_does_not_touch_already_cancelled_subscriptions(): void
    {
        [$school, $plan] = $this->makeSchoolAndPlan();
        $cancelled = $this->makeSubscription($school, $plan, 'cancelled', now()->subMonths(1));

        Artisan::call('subscriptions:expire');

        $this->assertSame('cancelled', $cancelled->fresh()->status, 'only active subscriptions should be reconsidered');
    }

    public function test_admin_subscriptions_list_reports_the_computed_status_not_the_stale_column(): void
    {
        [$school, $plan] = $this->makeSchoolAndPlan();
        // Reproduces the exact live incident: DB column still says 'active'
        // though end_date is long past — i.e. the cron in the test above
        // hasn't run yet for this row.
        $this->makeSubscription($school, $plan, 'active', now()->subMonths(4));

        $response = (new SubscriptionController(app(\App\Services\SubscriptionService::class)))
            ->adminIndex(Request::create('/admin/subscriptions', 'GET'));
        $data = $response->getData(true)['subscriptions'][0];

        $this->assertSame('expired', $data['status'], 'the list must show the real, expiry-aware status even before the sweep job has run');
        $this->assertSame('active', $data['raw_status'], 'the raw column is still exposed for debugging, just not as "status"');
    }
}
