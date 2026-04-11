<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\School;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
     * School used for cache keys: always the tenant DB row when one exists (ids match UI / tenant context).
     */
    protected function cacheScopeSchool(School $school): School
    {
        $central = config('tenancy.database.central_connection');
        if ($school->getConnectionName() !== $central) {
            return $school;
        }

        $tenantSchool = $this->tenantSchoolFromCentralSchool($school);

        return $tenantSchool ?? $school;
    }

    /**
     * Bust every cached subscription value for a school.
     * Call after create / upgrade / renew / cancel.
     */
    public function invalidateCache(School $school): void
    {
        $scope = $this->cacheScopeSchool($school);
        $id    = $scope->id;

        Cache::forget($this->modulesKey($id));
        Cache::forget($this->statusKey($id));

        try {
            Cache::tags(["school:{$id}:subscription"])->flush();
        } catch (\BadMethodCallException) {
            // Non-taggable driver (database, file) — keys will expire naturally within TTL.
        }
    }

    public function invalidateCacheForSubscription(Subscription $subscription): void
    {
        $subscription->loadMissing('school');
        if ($subscription->school) {
            $this->invalidateCache($subscription->school);
        }
    }

    protected function tenantSchoolFromCentralSchool(?School $centralSchool): ?School
    {
        if (!$centralSchool?->tenant_id) {
            return null;
        }

        $tenant = Tenant::find($centralSchool->tenant_id);
        if (!$tenant) {
            return null;
        }

        tenancy()->initialize($tenant);
        try {
            return School::query()->first();
        } finally {
            tenancy()->end();
        }
    }

    /**
     * Central `schools.id` used in the subscriptions table.
     *
     * @throws \RuntimeException when the tenant has no mirror row in the central DB
     */
    protected function resolveSubscriptionSchoolId(School $school): int
    {
        $central = config('tenancy.database.central_connection');
        if ($school->getConnectionName() === $central) {
            return $school->id;
        }

        $tenantId = function_exists('tenant') && tenant() ? tenant('id') : null;
        if (!$tenantId) {
            throw new \RuntimeException('Cannot resolve subscription: tenant context is missing.');
        }

        $row = School::on($central)->where('tenant_id', $tenantId)->first();
        if (!$row) {
            throw new \RuntimeException(
                'This school is not registered in the main database yet. Sync the school for this tenant from the super admin panel, then try again.'
            );
        }

        return $row->id;
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

        $centralConn = config('tenancy.database.central_connection');
        $schoolId    = $this->resolveSubscriptionSchoolId($school);

        $subscription = DB::connection($centralConn)->transaction(function () use ($school, $plan, $options, $allAccess, $schoolId) {
            $this->cancelExistingSubscription($school);

            return Subscription::create([
                'school_id'      => $schoolId,
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
        });

        // Mirror into tenant school settings (tenant connection).
        $this->updateSchoolModules($school, $allAccess);

        $this->invalidateCache($school);

        return $subscription;
    }

    public function upgradeSubscription(Subscription $subscription, Plan $newPlan): Subscription
    {
        $allAccess = array_values(array_unique(array_merge(
            $newPlan->modules  ?? [],
            $newPlan->features ?? [],
        )));

        $centralConn = config('tenancy.database.central_connection');

        DB::connection($centralConn)->transaction(function () use ($subscription, $newPlan, $allAccess) {
            $subscription->update([
                'plan_id'  => $newPlan->id,
                'amount'   => $newPlan->price,
                'features' => $allAccess,
                'limits'   => $newPlan->limits,
            ]);

            $subscription->loadMissing('school');
            $tenantSchool = $this->tenantSchoolFromCentralSchool($subscription->school);
            if ($tenantSchool) {
                $this->updateSchoolModules($tenantSchool, $allAccess);
            }
        });

        $this->invalidateCacheForSubscription($subscription->fresh(['school']));

        return $subscription->fresh();
    }

    public function cancelSubscription(Subscription $subscription, bool $immediate = false): Subscription
    {
        $attributes = ['status' => 'cancelled', 'auto_renew' => false];
        if ($immediate) {
            $attributes['end_date'] = now();
        }

        $subscription->update($attributes);
        $this->invalidateCacheForSubscription($subscription->fresh(['school']));

        return $subscription;
    }

    public function renewSubscription(Subscription $subscription): Subscription
    {
        $subscription->loadMissing('plan');

        $subscription->update([
            'status'     => 'active',
            'start_date' => now(),
            'end_date'   => $this->calculateEndDate($subscription->plan),
            'is_trial'   => false,
        ]);

        $this->invalidateCacheForSubscription($subscription->fresh(['school']));

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
