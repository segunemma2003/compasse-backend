<?php

namespace App\Services;

use App\Models\School;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Module;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    /**
     * Create subscription for school
     */
    public function createSubscription(School $school, Plan $plan, array $options = []): Subscription
    {
        DB::beginTransaction();

        try {
            // Cancel existing subscription if any
            $this->cancelExistingSubscription($school);

            $subscription = Subscription::create([
                'school_id' => $school->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'start_date' => now(),
                'end_date' => $this->calculateEndDate($plan, $options),
                'trial_end_date' => $plan->trial_days > 0 ? now()->addDays($plan->trial_days) : null,
                'is_trial' => $plan->trial_days > 0,
                'auto_renew' => $options['auto_renew'] ?? true,
                'payment_method' => $options['payment_method'] ?? 'card',
                'billing_cycle' => $plan->billing_cycle,
                'amount' => $plan->price,
                'currency' => $plan->currency,
                'features' => $plan->features,
                'limits' => $plan->limits,
            ]);

            // Update school settings with modules
            $this->updateSchoolModules($school, $plan->modules);

            DB::commit();
            return $subscription;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Upgrade subscription
     */
    public function upgradeSubscription(Subscription $subscription, Plan $newPlan): Subscription
    {
        DB::beginTransaction();

        try {
            $oldPlan = $subscription->plan;

            // Calculate prorated amount
            $proratedAmount = $this->calculateProratedAmount($subscription, $newPlan);

            // Update subscription
            $subscription->update([
                'plan_id' => $newPlan->id,
                'amount' => $newPlan->price,
                'features' => $newPlan->features,
                'limits' => $newPlan->limits,
            ]);

            // Update school modules
            $this->updateSchoolModules($subscription->school, $newPlan->modules);

            DB::commit();
            return $subscription;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription(Subscription $subscription, bool $immediate = false): Subscription
    {
        if ($immediate) {
            $subscription->update([
                'status' => 'cancelled',
                'end_date' => now(),
                'auto_renew' => false,
            ]);
        } else {
            $subscription->update([
                'status' => 'cancelled',
                'auto_renew' => false,
            ]);
        }

        return $subscription;
    }

    /**
     * Renew subscription
     */
    public function renewSubscription(Subscription $subscription): Subscription
    {
        $plan = $subscription->plan;

        $subscription->update([
            'status' => 'active',
            'start_date' => now(),
            'end_date' => $this->calculateEndDate($plan),
            'is_trial' => false,
        ]);

        return $subscription;
    }

    /**
     * Check if school has access to module
     */
    public function hasModuleAccess(School $school, string $module): bool
    {
        $subscription = $school->subscription;

        if (!$subscription) {
            return false;
        }

        return $subscription->hasModule($module);
    }

    /**
     * Check if school has access to feature
     */
    public function hasFeatureAccess(School $school, string $feature): bool
    {
        $subscription = $school->subscription;

        if (!$subscription) {
            return false;
        }

        return $subscription->hasFeature($feature);
    }

    /**
     * Check if school is within limits
     */
    public function isWithinLimits(School $school, string $limit, int $currentUsage): bool
    {
        $subscription = $school->subscription;

        if (!$subscription) {
            return false;
        }

        return !$subscription->isLimitExceeded($limit, $currentUsage);
    }

    /**
     * Get school's available modules
     */
    public function getSchoolModules(School $school): array
    {
        $subscription = $school->subscription;

        if (!$subscription) {
            return [];
        }

        return $subscription->features ?? [];
    }

    /**
     * Get school's limits
     */
    public function getSchoolLimits(School $school): array
    {
        $subscription = $school->subscription;

        if (!$subscription) {
            return [];
        }

        return $subscription->limits ?? [];
    }

    /**
     * Get subscription status
     */
    public function getSubscriptionStatus(School $school): array
    {
        $subscription = $school->subscription;

        if (!$subscription) {
            return [
                'status' => 'no_subscription',
                'message' => 'No active subscription',
            ];
        }

        return $subscription->getSummary();
    }

    /**
     * Cancel existing subscription
     */
    protected function cancelExistingSubscription(School $school): void
    {
        $existingSubscription = $school->subscription;

        if ($existingSubscription && $existingSubscription->isActive()) {
            $this->cancelSubscription($existingSubscription, true);
        }
    }

    /**
     * Update school modules
     */
    protected function updateSchoolModules(School $school, array $modules): void
    {
        $settings = $school->settings ?? [];
        $settings['modules'] = $modules;
        $school->update(['settings' => $settings]);
    }

    /**
     * Calculate end date
     */
    protected function calculateEndDate(Plan $plan, array $options = []): \DateTime
    {
        $startDate = $options['start_date'] ?? now();

        switch ($plan->billing_cycle) {
            case 'monthly':
                return $startDate->addMonth();
            case 'yearly':
                return $startDate->addYear();
            case 'quarterly':
                return $startDate->addMonths(3);
            default:
                return $startDate->addMonth();
        }
    }

    /**
     * Calculate prorated amount
     */
    protected function calculateProratedAmount(Subscription $subscription, Plan $newPlan): float
    {
        $daysRemaining = $subscription->getDaysRemaining();
        $totalDays = $subscription->start_date->diffInDays($subscription->end_date);

        if ($totalDays <= 0) {
            return $newPlan->price;
        }

        $proratedRatio = $daysRemaining / $totalDays;
        return $newPlan->price * $proratedRatio;
    }
}
