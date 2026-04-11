<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    /**
     * List all plans (super admin sees all, public sees only active)
     */
    public function index(Request $request): JsonResponse
    {
        $plans = Plan::orderBy('sort_order')->orderBy('price')->get();

        return response()->json(['plans' => $plans]);
    }

    /**
     * Create a new plan
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255|unique:plans,name',
            'description'   => 'nullable|string',
            'type'          => ['required', Rule::in(['free', 'basic', 'premium', 'enterprise'])],
            'price'         => 'required|numeric|min:0',
            'currency'      => 'sometimes|string|size:3',
            'billing_cycle' => ['sometimes', Rule::in(['monthly', 'yearly', 'quarterly'])],
            'trial_days'    => 'sometimes|integer|min:0',
            'features'      => 'sometimes|array',
            'features.*'    => 'string',
            'modules'       => 'sometimes|array',
            'modules.*'     => 'string',
            'limits'        => 'sometimes|array',
            'is_active'     => 'sometimes|boolean',
            'is_popular'    => 'sometimes|boolean',
            'sort_order'    => 'sometimes|integer|min:0',
        ]);

        $plan = Plan::create($validated);

        return response()->json(['message' => 'Plan created successfully', 'plan' => $plan], 201);
    }

    /**
     * Show a single plan
     */
    public function show(Plan $plan): JsonResponse
    {
        return response()->json(['plan' => $plan]);
    }

    /**
     * Update a plan
     */
    public function update(Request $request, Plan $plan): JsonResponse
    {
        $validated = $request->validate([
            'name'          => ['sometimes', 'string', 'max:255', Rule::unique('plans', 'name')->ignore($plan->id)],
            'description'   => 'sometimes|nullable|string',
            'type'          => ['sometimes', Rule::in(['free', 'basic', 'premium', 'enterprise'])],
            'price'         => 'sometimes|numeric|min:0',
            'currency'      => 'sometimes|string|size:3',
            'billing_cycle' => ['sometimes', Rule::in(['monthly', 'yearly', 'quarterly'])],
            'trial_days'    => 'sometimes|integer|min:0',
            'features'      => 'sometimes|array',
            'features.*'    => 'string',
            'modules'       => 'sometimes|array',
            'modules.*'     => 'string',
            'limits'        => 'sometimes|array',
            'is_active'     => 'sometimes|boolean',
            'is_popular'    => 'sometimes|boolean',
            'sort_order'    => 'sometimes|integer|min:0',
        ]);

        $plan->update($validated);

        return response()->json(['message' => 'Plan updated successfully', 'plan' => $plan->fresh()]);
    }

    /**
     * Delete a plan (only if no active subscriptions)
     */
    public function destroy(Plan $plan): JsonResponse
    {
        $activeCount = $plan->subscriptions()->whereIn('status', ['active', 'trial'])->count();

        if ($activeCount > 0) {
            return response()->json([
                'error' => 'Cannot delete plan with active subscriptions',
                'active_subscriptions' => $activeCount,
            ], 422);
        }

        $plan->delete();

        return response()->json(['message' => 'Plan deleted successfully']);
    }
}
