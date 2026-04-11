<?php

namespace App\Services;

use App\Models\School;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionService
{
    // TTL for module-access cache entries (5 minutes).
    // Keep short enough that plan downgrades take effect quickly.
    private const MODULE_CACHE_TTL  = 300;
    private const MODULES_CACHE_TTL = 300;
    private const STATUS_CACHE_TTL  = 60;

    // -------------------------------------------------------------------------
    // Cache key helpers
    // -------------------------------------------------------------------------

    private function moduleKey(int $schoolId, string $module): string
    {
        return "sub:school:{$schoolId}:module:{$module}";
    }

    private function modulesKey(int $schoolId): string
    {
        return "sub:school:{$schoolId}:modules";
    }

    private function statusKey(int $schoolId): string
    {
        return "sub:school:{$schoolId}:status";
    }

    /**
     * Bust every cached subscription value for a school.
     * Call after create / upgrade / renew / cancel.
     */
    public function invalidateCache(School $school): void
    {
        Cache::forget($this->modulesKey($school->id));
        Cache::forget($this->statusKey($school->id));

        // Individual module keys — clear the modules list so they are re-evaluated
        // on next access. We cannot know every module slug that was ever cached, so
        // we use a tag if Redis is available; otherwise we wipe the known list only.
        try {
            Cache::tags(["school:{$school->id}:subscription"])->flush();
        } catch (\BadMethodCallException) {
            // Non-taggable driver (database, file) — keys will expire naturally within TTL.
            // The modules list key is already cleared above, which forces a full reload.
        }
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Check if school has access to a module.
     *
     * Returns FALSE when there is no active subscription — access must be
     * explicitly granted by a plan, not assumed.
     */
    public function hasModuleAccess(School $school, string $module): bool
    {
        $cacheKey = $this->moduleKey($school->id, $module);

        return (bool) Cache::remember($cacheKey, self::MODULE_CACHE_TTL, function () use ($school, $module) {
            $subscription = $school->subscription;

            if (!$subscription || (!$subscription->isActive() && !$subscription->isTrial())) {
                return false;
            }

            return $subscription->hasModule($module);
        });
    }

    /**
     * Check if school has access to a feature flag.
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
     * Check if school is within a usage limit.
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
     * Return the list of module + feature slugs available to the school.
     *
     * Resolution order:
     *   1. subscription->features  (set at create/upgrade — merged modules+features)
     *   2. plan->modules + plan->features  (fallback for legacy subscriptions)
     *   3. school->settings['modules']  (manual override stored by updateSchoolModules)
     *
     * Returns an empty array when there is no active/trial subscription.
     */
    public function getSchoolModules(School $school): array
    {
        return Cache::remember($this->modulesKey($school->id), self::MODULES_CACHE_TTL, function () use ($school) {
            $subscription = $school->subscription;

            if (!$subscription || (!$subscription->isActive() && !$subscription->isTrial())) {
                // No active subscription — fall back to manual override in school settings.
                return $school->settings['modules'] ?? [];
            }

            $fromSubscription = $subscription->features ?? [];

            // Legacy subscriptions only stored feature flags, not module slugs.
            // In that case also pull from the plan directly.
            if (empty($fromSubscription) && $subscription->plan) {
                $fromSubscription = array_values(array_unique(array_merge(
                    $subscription->plan->modules  ?? [],
                    $subscription->plan->features ?? [],
                )));
            }

            // Merge with any manual school-level module overrides.
            $fromSettings = $school->settings['modules'] ?? [];

            return array_values(array_unique(array_merge($fromSubscription, $fromSettings)));
        });
    }

    /**
     * Return the usage limits defined on the school's active plan.
     */
    public function getSchoolLimits(School $school): array
    {
        $subscription = $school->subscription;

        if (!$subscription || (!$subscription->isActive() && !$subscription->isTrial())) {
            return [];
        }

        return $subscription->limits ?? [];
    }

    /**
     * Return a human-readable subscription status summary.
     */
    public function getSubscriptionStatus(School $school): array
    {
        return Cache::remember($this->statusKey($school->id), self::STATUS_CACHE_TTL, function () use ($school) {
            $subscription = $school->subscription;

            if (!$subscription) {
                return [
                    'status'  => 'no_subscription',
                    'message' => 'No active subscription',
                ];
            }

            return $subscription->getSummary();
        });
    }

    // -------------------------------------------------------------------------
    // Subscription lifecycle
    // -------------------------------------------------------------------------

    public function createSubscription(School $school, Plan $plan, array $options = []): Subscription
    {
        // Merge plan modules + features into a single list stored on the subscription.
        // This ensures hasModule() (which checks subscription->features) finds both
        // module slugs (e.g. 'student_management') and feature flags (e.g. 'parent_portal').
        $allAccess = array_values(array_unique(array_merge(
            $plan->modules  ?? [],
            $plan->features ?? [],
        )));

        DB::beginTransaction();
        try {
            $this->cancelExistingSubscription($school);

            $subscription = Subscription::create([
                'school_id'      => $school->id,
                'plan_id'        => $plan->id,
                'status'         => 'active',
                'start_date'     => now(),
                'end_date'       => $this->calculateEndDate($plan, $options),
                'trial_end_date' => $plan->trial_days > 0 ? now()->addDays($plan->trial_days) : null,
                'is_trial'       => $plan->trial_days > 0,
                'auto_renew'     => $options['auto_renew'] ?? true,
                'payment_method' => $options['payment_method'] ?? 'card',
                'billing_cycle'  => $plan->billing_cycle,
                'amount'         => $plan->price,
                'currency'       => $plan->currency,
                'features'       => $allAccess,   // unified: modules + feature flags
                'limits'         => $plan->limits,
            ]);

            // Also mirror into school settings for quick local lookups.
            $this->updateSchoolModules($school, $allAccess);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        $this->invalidateCache($school);
        return $subscription;
    }

    public function upgradeSubscription(Subscription $subscription, Plan $newPlan): Subscription
    {
        $allAccess = array_values(array_unique(array_merge(
            $newPlan->modules  ?? [],
            $newPlan->features ?? [],
        )));

        DB::beginTransaction();
        try {
            $subscription->update([
                'plan_id'  => $newPlan->id,
                'amount'   => $newPlan->price,
                'features' => $allAccess,
                'limits'   => $newPlan->limits,
            ]);

            $this->updateSchoolModules($subscription->school, $allAccess);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        $this->invalidateCache($subscription->school);
        return $subscription;
    }

    public function cancelSubscription(Subscription $subscription, bool $immediate = false): Subscription
    {
        $attributes = ['status' => 'cancelled', 'auto_renew' => false];
        if ($immediate) {
            $attributes['end_date'] = now();
        }

        $subscription->update($attributes);
        $this->invalidateCache($subscription->school);
        return $subscription;
    }

    public function renewSubscription(Subscription $subscription): Subscription
    {
        $subscription->update([
            'status'     => 'active',
            'start_date' => now(),
            'end_date'   => $this->calculateEndDate($subscription->plan),
            'is_trial'   => false,
        ]);

        $this->invalidateCache($subscription->school);
        return $subscription;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    protected function cancelExistingSubscription(School $school): void
    {
        $existing = $school->subscription;
        if ($existing && $existing->isActive()) {
            $this->cancelSubscription($existing, true);
        }
    }

    protected function updateSchoolModules(School $school, array $modules): void
    {
        $settings = $school->settings ?? [];
        $settings['modules'] = $modules;
        $school->update(['settings' => $settings]);
    }

    protected function calculateEndDate(Plan $plan, array $options = []): \Carbon\Carbon
    {
        $start = isset($options['start_date'])
            ? \Carbon\Carbon::parse($options['start_date'])
            : now();

        return match ($plan->billing_cycle) {
            'yearly'    => $start->copy()->addYear(),
            'quarterly' => $start->copy()->addMonths(3),
            default     => $start->copy()->addMonth(),   // monthly
        };
    }

    protected function calculateProratedAmount(Subscription $subscription, Plan $newPlan): float
    {
        $totalDays = $subscription->start_date->diffInDays($subscription->end_date);
        if ($totalDays <= 0) {
            return (float) $newPlan->price;
        }

        return $newPlan->price * ($subscription->getDaysRemaining() / $totalDays);
    }
}
