<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Module;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class SubscriptionController extends Controller
{
    protected $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    /**
     * Get all available plans
     */
    public function getPlans(): JsonResponse
    {
        $plans = Plan::where('is_active', true)
                    ->orderBy('sort_order')
                    ->get()
                    ->map(function ($plan) {
                        return $plan->getSummary();
                    });

        return response()->json([
            'plans' => $plans
        ]);
    }

    /**
     * Get all available modules
     */
    public function getModules(): JsonResponse
    {
        $modules = Module::where('is_active', true)
                        ->orderBy('sort_order')
                        ->get()
                        ->map(function ($module) {
                            return $module->getSummary();
                        });

        return response()->json([
            'modules' => $modules
        ]);
    }

    /**
     * Get school's subscription status
     */
    public function getSubscriptionStatus(Request $request): JsonResponse
    {
        $school = $request->attributes->get('school');

        if (!$school) {
            return response()->json([
                'error' => 'School not found'
            ], 404);
        }

        $status = $this->subscriptionService->getSubscriptionStatus($school);

        return response()->json([
            'subscription' => $status
        ]);
    }

    /**
     * Create subscription for school
     */
    public function createSubscription(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'plan_id' => 'required|exists:plans,id',
            'payment_method' => 'nullable|string',
            'auto_renew' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $school = $request->attributes->get('school');
            $plan = Plan::findOrFail($request->plan_id);

            $subscription = $this->subscriptionService->createSubscription($school, $plan, [
                'payment_method' => $request->payment_method,
                'auto_renew' => $request->auto_renew ?? true,
            ]);

            return response()->json([
                'message' => 'Subscription created successfully',
                'subscription' => $subscription->getSummary()
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create subscription',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upgrade subscription
     */
    public function upgradeSubscription(Request $request, Subscription $subscription): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'plan_id' => 'required|exists:plans,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $newPlan = Plan::findOrFail($request->plan_id);
            $updatedSubscription = $this->subscriptionService->upgradeSubscription($subscription, $newPlan);

            return response()->json([
                'message' => 'Subscription upgraded successfully',
                'subscription' => $updatedSubscription->getSummary()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to upgrade subscription',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription(Request $request, Subscription $subscription): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'immediate' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $cancelledSubscription = $this->subscriptionService->cancelSubscription(
                $subscription,
                $request->immediate ?? false
            );

            return response()->json([
                'message' => 'Subscription cancelled successfully',
                'subscription' => $cancelledSubscription->getSummary()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to cancel subscription',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check module access
     */
    public function checkModuleAccess(Request $request, string $module): JsonResponse
    {
        $school = $request->attributes->get('school');

        if (!$school) {
            return response()->json([
                'error' => 'School not found'
            ], 404);
        }

        $hasAccess = $this->subscriptionService->hasModuleAccess($school, $module);

        return response()->json([
            'module' => $module,
            'has_access' => $hasAccess
        ]);
    }

    /**
     * Check feature access
     */
    public function checkFeatureAccess(Request $request, string $feature): JsonResponse
    {
        $school = $request->attributes->get('school');

        if (!$school) {
            return response()->json([
                'error' => 'School not found'
            ], 404);
        }

        $hasAccess = $this->subscriptionService->hasFeatureAccess($school, $feature);

        return response()->json([
            'feature' => $feature,
            'has_access' => $hasAccess
        ]);
    }

    /**
     * Get school's modules
     */
    public function getSchoolModules(Request $request): JsonResponse
    {
        $school = $request->attributes->get('school');

        if (!$school) {
            return response()->json([
                'error' => 'School not found'
            ], 404);
        }

        $modules = $this->subscriptionService->getSchoolModules($school);

        return response()->json([
            'modules' => $modules
        ]);
    }

    /**
     * Get school's limits
     */
    public function getSchoolLimits(Request $request): JsonResponse
    {
        $school = $request->attributes->get('school');

        if (!$school) {
            return response()->json([
                'error' => 'School not found'
            ], 404);
        }

        $limits = $this->subscriptionService->getSchoolLimits($school);

        return response()->json([
            'limits' => $limits
        ]);
    }
}
