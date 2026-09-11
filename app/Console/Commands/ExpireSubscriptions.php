<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

/**
 * Subscription::isActive()/getStatus() are already expiry-aware (they check
 * end_date, not just the status column), so module access was never actually
 * left open past expiry. But nothing ever wrote 'expired' back to the status
 * column itself, so anything that reads it directly — the super-admin
 * Subscriptions list, `?status=` filtering there, reports — kept showing
 * "active" indefinitely. Confirmed live on 2026-09-12: 3 of 7 tenants had a
 * subscription whose end_date had passed (one four months earlier) still
 * marked 'active' in the database.
 */
class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire';
    protected $description = 'Mark active subscriptions whose end_date has passed as expired';

    public function handle(SubscriptionService $subscriptionService): int
    {
        $subscriptions = Subscription::with('school')
            ->where('status', 'active')
            ->where('end_date', '<=', now())
            ->get();

        foreach ($subscriptions as $subscription) {
            $subscription->update(['status' => 'expired']);
            $subscriptionService->invalidateCacheForSubscription($subscription);
            $this->line("  Expired subscription #{$subscription->id} ({$subscription->school?->name}) — ended {$subscription->end_date->toDateString()}");
        }

        $this->info(count($subscriptions) . ' subscription(s) expired.');

        return self::SUCCESS;
    }
}
