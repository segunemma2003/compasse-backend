<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Module;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentIntent;
use App\Models\School;
use App\Services\FlutterwaveService;
use App\Services\PaystackService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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

        $perPage = min(100, max(1, (int) $request->input('per_page', 50)));
        $subscriptions = $query->paginate($perPage);

        return response()->json([
            'subscriptions' => $subscriptions->map(function ($sub) {
                // Do not rely on getSummary() alone — it omits id/start/end, which breaks the super-admin UI (React keys, filters).
                $summary = $sub->getSummary();

                return array_merge($summary, [
                    'id'           => $sub->id,
                    'school_id'    => $sub->school_id,
                    'school_name'  => $sub->school?->name ?? 'Unknown school',
                    'status'       => $sub->status,
                    'start_date'   => $sub->start_date?->toIso8601String(),
                    'end_date'     => $sub->end_date?->toIso8601String(),
                    'plan'         => $sub->plan?->name,
                    'plan_id'      => $sub->plan_id,
                ]);
            }),
            'total'    => $subscriptions->total(),
            'per_page' => $subscriptions->perPage(),
            'page'     => $subscriptions->currentPage(),
            'last_page'=> $subscriptions->lastPage(),
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

    // -------------------------------------------------------------------------
    // Paid plan checkout (Paystack / Flutterwave)
    //
    // createSubscription()/upgradeSubscription() above activate a plan
    // immediately with no charge — used by super admins granting a plan
    // directly, and here as the final step once a real payment is verified.
    // Everything below drives the school-initiated "pay to upgrade" flow.
    // -------------------------------------------------------------------------

    public function paymentGatewayConfig(PaystackService $paystack, FlutterwaveService $flutterwave): JsonResponse
    {
        return response()->json([
            'default_provider'       => config('services.payments.default_provider', 'paystack'),
            'paystack_enabled'       => $paystack->isConfigured(),
            'paystack_public_key'    => $paystack->isConfigured() ? config('services.paystack.public_key') : null,
            'flutterwave_enabled'    => $flutterwave->isConfigured(),
            'flutterwave_public_key' => $flutterwave->isConfigured() ? config('services.flutterwave.public_key') : null,
            'currency'               => config('services.paystack.currency', 'NGN'),
        ]);
    }

    /**
     * Start a paid checkout for a plan. Free plans (price 0, e.g. a trial tier)
     * activate immediately with no gateway involved.
     */
    public function initializePayment(Request $request, PaystackService $paystack, FlutterwaveService $flutterwave): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'plan_id'  => 'required|exists:plans,id',
            'provider' => 'nullable|in:paystack,flutterwave',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $school = $this->getSchoolFromRequest($request);
        if (!$school) {
            return response()->json(['error' => 'School not found', 'message' => 'Unable to determine school context.'], 404);
        }

        $plan = Plan::findOrFail($request->plan_id);

        if ((float) $plan->price <= 0) {
            $subscription = $this->activatePlanForSchool($school, $plan);

            return response()->json([
                'status'       => 'success',
                'free'         => true,
                'subscription' => $subscription->getSummary(),
            ]);
        }

        $provider = $request->input('provider', config('services.payments.default_provider', 'paystack'));
        if ($provider === 'paystack' && !$paystack->isConfigured()) {
            $provider = $flutterwave->isConfigured() ? 'flutterwave' : null;
        }
        if ($provider === 'flutterwave' && !$flutterwave->isConfigured()) {
            $provider = $paystack->isConfigured() ? 'paystack' : null;
        }
        if (!$provider) {
            return response()->json(['error' => 'Online payments are not enabled for this school'], 503);
        }

        $email = Auth::user()->email ?? null;
        if (!$email) {
            return response()->json(['error' => 'No email on file for payment receipt'], 422);
        }

        try {
            $centralSchoolId = $this->subscriptionService->resolveSubscriptionSchoolId($school);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $reference = $provider === 'flutterwave'
            ? FlutterwaveService::generateReference()
            : PaystackService::generateReference();

        SubscriptionPaymentIntent::create([
            'school_id' => $centralSchoolId,
            'plan_id'   => $plan->id,
            'amount'    => $plan->price,
            'currency'  => $plan->currency ?? 'NGN',
            'reference' => $reference,
            'provider'  => $provider,
            'status'    => 'pending',
            'meta'      => [
                'initiated_by' => Auth::id(),
                'subdomain'    => $request->attributes->get('tenant')?->subdomain
                    ?? (function_exists('tenant') && tenant() ? tenant('subdomain') : null),
            ],
        ]);

        if ($provider === 'flutterwave') {
            $checkout = $flutterwave->initialize((float) $plan->price, $email, $reference, [
                'plan_id'      => $plan->id,
                'redirect_url' => config('app.frontend_url', config('app.url')) . '/school/subscription?payment=verify',
            ]);

            return response()->json([
                'provider'          => 'flutterwave',
                'authorization_url' => $checkout['link'],
                'reference'         => $checkout['reference'],
            ]);
        }

        $checkout = $paystack->initialize((float) $plan->price, $email, $reference, ['plan_id' => $plan->id]);

        return response()->json([
            'provider'          => 'paystack',
            'authorization_url' => $checkout['authorization_url'],
            'reference'         => $checkout['reference'],
            'access_code'       => $checkout['access_code'],
        ]);
    }

    /**
     * Confirm a checkout and activate the plan. Called by the frontend right
     * after the gateway popup/redirect returns.
     */
    public function verifyPayment(Request $request, PaystackService $paystack, FlutterwaveService $flutterwave): JsonResponse
    {
        $reference      = $request->input('reference');
        $transactionId  = $request->input('transaction_id');

        if (!$reference && !$transactionId) {
            return response()->json(['error' => 'reference or transaction_id is required'], 422);
        }

        $intent = $reference ? SubscriptionPaymentIntent::where('reference', $reference)->first() : null;

        if ($intent && $intent->status === 'success' && $intent->subscription_id) {
            return response()->json([
                'status'       => 'success',
                'subscription' => Subscription::find($intent->subscription_id)?->getSummary(),
            ]);
        }

        $provider = $intent?->provider ?? $request->input('provider', 'paystack');
        $ok       = false;
        $ref      = $reference ?? $intent?->reference;

        if ($provider === 'flutterwave' && $transactionId) {
            $verified = $flutterwave->verifyByTransactionId($transactionId);
            $ok       = $verified['status'] === 'success';
            $ref      = $verified['reference'] ?: $ref;
            if (!$intent && $ref) {
                $intent = SubscriptionPaymentIntent::where('reference', $ref)->first();
            }
        } elseif ($ref) {
            $verified = $paystack->verify($ref);
            $ok       = $verified['status'] === 'success';
        }

        if (!$intent) {
            return response()->json(['error' => 'Payment intent not found'], 404);
        }

        if (!$ok) {
            $intent->update(['status' => 'failed']);

            return response()->json(['status' => 'failed', 'message' => 'Payment was not successful'], 402);
        }

        $school = $this->getSchoolFromRequest($request);
        if (!$school) {
            return response()->json(['error' => 'School not found', 'message' => 'Unable to determine school context.'], 404);
        }

        return response()->json($this->completeIntent($intent, $school));
    }

    /**
     * Paystack server webhook (charge.success) — reconciles payments the user's
     * browser never returned to verify() for (closed tab, network drop, etc).
     */
    public function paystackWebhook(Request $request, PaystackService $paystack): JsonResponse
    {
        $reference = $request->input('data.reference') ?? $request->input('reference');
        if (!$reference) {
            return response()->json(['ok' => true]);
        }

        $intent = SubscriptionPaymentIntent::where('reference', $reference)->first();
        if (!$intent || $intent->status === 'success') {
            return response()->json(['ok' => true]);
        }

        $subdomain = is_array($intent->meta) ? ($intent->meta['subdomain'] ?? null) : null;
        if ($subdomain) {
            $this->switchTenantBySubdomain($subdomain);
        }

        try {
            $verified = $paystack->verify($reference);
            if ($verified['status'] === 'success') {
                $school = School::first();
                if ($school) {
                    $this->completeIntent($intent, $school);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Subscription Paystack webhook verify failed', ['ref' => $reference, 'error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Activate the intent's plan for the school and record the result on the intent.
     * Upgrades an existing active subscription in place rather than layering a new one.
     */
    protected function completeIntent(SubscriptionPaymentIntent $intent, School $school): array
    {
        if ($intent->status === 'success' && $intent->subscription_id) {
            return [
                'status'       => 'success',
                'subscription' => Subscription::find($intent->subscription_id)?->getSummary(),
            ];
        }

        $plan = Plan::find($intent->plan_id);
        $subscription = $this->activatePlanForSchool($school, $plan, ['payment_method' => $intent->provider]);

        $intent->update(['status' => 'success', 'subscription_id' => $subscription->id]);

        return [
            'status'       => 'success',
            'subscription' => $subscription->getSummary(),
        ];
    }

    protected function activatePlanForSchool(School $school, Plan $plan, array $options = []): Subscription
    {
        $centralSchoolId = $this->subscriptionService->resolveSubscriptionSchoolId($school);
        $existing = Subscription::where('school_id', $centralSchoolId)->where('status', 'active')->first();

        if ($existing) {
            return $this->subscriptionService->upgradeSubscription($existing, $plan);
        }

        return $this->subscriptionService->createSubscription($school, $plan, array_merge([
            'auto_renew' => true,
        ], $options));
    }

    protected function switchTenantBySubdomain(string $subdomain): void
    {
        $tenant = app(\App\Services\TenantService::class)->getTenantBySubdomain($subdomain);
        if ($tenant) {
            \Illuminate\Support\Facades\DB::purge('tenant');
            app(\App\Services\TenantService::class)->switchToTenant($tenant);
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
