<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Module;
use App\Models\Subscription;
use App\Models\School;
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

    // ─── Super Admin Methods ───────────────────────────────────────────────────

    /**
     * List all subscriptions across all schools (super admin)
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = Subscription::with(['school', 'plan'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('school_id')) {
            $query->where('school_id', $request->school_id);
        }

        $subscriptions = $query->paginate(20);

        return response()->json([
            'subscriptions' => $subscriptions->map(function ($sub) {
                return array_merge(method_exists($sub, 'getSummary') ? $sub->getSummary() : $sub->toArray(), [
                    'school_name' => $sub->school?->name,
                    'school_id'   => $sub->school_id,
                ]);
            }),
            'total'    => $subscriptions->total(),
            'per_page' => $subscriptions->perPage(),
            'page'     => $subscriptions->currentPage(),
        ]);
    }

    /**
     * Create subscription for a school (super admin)
     */
    public function adminCreate(Request $request, School $school): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'plan_id'    => 'required|exists:plans,id',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after:start_date',
            'status'     => 'nullable|in:active,suspended,expired,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        try {
            $plan = Plan::findOrFail($request->plan_id);
            $subscription = $this->subscriptionService->createSubscription($school, $plan, [
                'start_date' => $request->start_date ?? now(),
                'end_date'   => $request->end_date ?? now()->addYear(),
                'status'     => $request->status ?? 'active',
            ]);

            return response()->json([
                'message'      => 'Subscription created successfully',
                'subscription' => $subscription->getSummary(),
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update a subscription (super admin — change plan, dates, status)
     */
    public function adminUpdate(Request $request, Subscription $subscription): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'plan_id'    => 'nullable|exists:plans,id',
            'status'     => 'nullable|in:active,suspended,expired,cancelled',
            'end_date'   => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        try {
            if ($request->filled('plan_id')) {
                $subscription->plan_id = $request->plan_id;
            }
            if ($request->filled('status')) {
                $subscription->status = $request->status;
            }
            if ($request->filled('end_date')) {
                $subscription->end_date = $request->end_date;
            }
            $subscription->save();

            return response()->json([
                'message'      => 'Subscription updated',
                'subscription' => $subscription->fresh(['plan', 'school'])->getSummary(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Cancel a subscription immediately (super admin)
     */
    public function adminCancel(Subscription $subscription): JsonResponse
    {
        try {
            $subscription->update(['status' => 'cancelled']);
            return response()->json(['message' => 'Subscription cancelled']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Extend subscription end date by N days (super admin)
     */
    public function adminExtend(Request $request, Subscription $subscription): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'days' => 'required|integer|min:1|max:3650',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        try {
            $newEnd = \Carbon\Carbon::parse($subscription->end_date)->addDays($request->days);
            $subscription->update(['end_date' => $newEnd, 'status' => 'active']);

            return response()->json([
                'message'      => "Subscription extended by {$request->days} days",
                'new_end_date' => $newEnd->toDateString(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ─── Tenant-context Methods ────────────────────────────────────────────────

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
            $central = config('tenancy.database.central_connection');
            if (! \Illuminate\Support\Facades\Schema::connection($central)->hasTable('subscriptions')) {
                return response()->json([
                    'subscription' => [
                        'status'  => 'active',
                        'plan'    => null,
                        'modules' => [],
                        'message' => 'Subscriptions table not found on the main database. Using default active status.',
                    ],
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
        // In tenant context, just get the first (and only) school
        $school = School::first();

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
        // In tenant context, just get the first (and only) school
        $school = School::first();

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
