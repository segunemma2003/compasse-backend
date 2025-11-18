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
        try {
            $plans = Plan::where('is_active', true)
                        ->orderBy('sort_order')
                        ->get()
                        ->map(function ($plan) {
                            return method_exists($plan, 'getSummary') ? $plan->getSummary() : $plan;
                        });

            return response()->json([
                'plans' => $plans
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'plans' => []
            ]);
        }
    }

    /**
     * Get all available modules
     */
    public function getModules(): JsonResponse
    {
        try {
            $modules = Module::where('is_active', true)
                            ->orderBy('sort_order')
                            ->get()
                            ->map(function ($module) {
                                return method_exists($module, 'getSummary') ? $module->getSummary() : $module;
                            });

            return response()->json([
                'modules' => $modules
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'modules' => []
            ]);
        }
    }

    /**
     * Get school's subscription status
     */
    public function getSubscriptionStatus(Request $request): JsonResponse
    {
        $school = $this->getSchoolFromRequest($request);

        if (!$school) {
            return response()->json([
                'error' => 'School not found',
                'message' => 'Unable to determine school context.'
            ], 404);
        }

        try {
            // Check if subscriptions table exists
            $tableExists = false;
            try {
                $tableExists = \Illuminate\Support\Facades\Schema::hasTable('subscriptions');
            } catch (\Exception $e) {
                $tableExists = false;
            }
            
            if (!$tableExists) {
                return response()->json([
                    'subscription' => [
                        'status' => 'active',
                        'plan' => null,
                        'modules' => [],
                        'message' => 'Subscriptions table not found. Using default active status.'
                    ]
                ]);
            }

            $status = $this->subscriptionService->getSubscriptionStatus($school);
            return response()->json([
                'subscription' => $status
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'subscription' => [
                    'status' => 'active',
                    'plan' => null,
                    'modules' => [],
                    'message' => 'Failed to get subscription status: ' . $e->getMessage()
                ]
            ]);
        }
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
            $school = $this->getSchoolFromRequest($request);
            if (!$school) {
                return response()->json([
                    'error' => 'School not found',
                    'message' => 'Unable to determine school context.'
                ], 404);
            }
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
        $school = $this->getSchoolFromRequest($request);

        if (!$school) {
            return response()->json([
                'error' => 'School not found',
                'message' => 'Unable to determine school context.'
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
        $school = $this->getSchoolFromRequest($request);

        if (!$school) {
            return response()->json([
                'error' => 'School not found',
                'message' => 'Unable to determine school context.'
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
        $school = $this->getSchoolFromRequest($request);

        if (!$school) {
            return response()->json([
                'error' => 'School not found',
                'message' => 'Unable to determine school context.'
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
        $school = $this->getSchoolFromRequest($request);

        if (!$school) {
            return response()->json([
                'error' => 'School not found',
                'message' => 'Unable to determine school context.'
            ], 404);
        }

        $limits = $this->subscriptionService->getSchoolLimits($school);

        return response()->json([
            'limits' => $limits
        ]);
    }

    /**
     * List subscriptions
     */
    public function index(Request $request): JsonResponse
    {
        $school = $this->getSchoolFromRequest($request);

        if (!$school) {
            return response()->json([
                'error' => 'School not found',
                'message' => 'Unable to determine school context.'
            ], 404);
        }

        $subscriptions = Subscription::where('school_id', $school->id)
            ->with(['plan'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'subscriptions' => $subscriptions->map(function ($sub) {
                return $sub->getSummary();
            })
        ]);
    }

    /**
     * Get subscription details
     */
    public function show($id): JsonResponse
    {
        $subscription = Subscription::with(['plan', 'school'])->find($id);

        if (!$subscription) {
            return response()->json([
                'error' => 'Subscription not found'
            ], 404);
        }

        return response()->json([
            'subscription' => $subscription->getSummary()
        ]);
    }

    /**
     * Renew subscription
     */
    public function renewSubscription(Request $request, Subscription $subscription): JsonResponse
    {
        try {
            $renewedSubscription = $this->subscriptionService->renewSubscription($subscription);

            return response()->json([
                'message' => 'Subscription renewed successfully',
                'subscription' => $renewedSubscription->getSummary()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to renew subscription',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
