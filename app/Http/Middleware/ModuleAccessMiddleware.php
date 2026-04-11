<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\SubscriptionService;

class ModuleAccessMiddleware
{
    protected $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    public function handle(Request $request, Closure $next, string $module)
    {
        $school = $this->resolveSchool($request);

        if (!$school) {
            // No school context at all — deny rather than silently allow.
            return response()->json([
                'error'   => 'School context required',
                'message' => 'Could not determine which school this request belongs to.',
            ], 400);
        }

        try {
            if (!$this->subscriptionService->hasModuleAccess($school, $module)) {
                return response()->json([
                    'error'            => 'Module access denied',
                    'message'          => "This school does not have access to the {$module} module. Please upgrade your subscription.",
                    'module'           => $module,
                    'upgrade_required' => true,
                ], 403);
            }
        } catch (\Exception $e) {
            // Subscription check threw an unexpected error — fail CLOSED (deny access).
            Log::error('Module access check failed', [
                'module'    => $module,
                'school_id' => $school->id,
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'error'   => 'Service unavailable',
                'message' => 'Unable to verify module access. Please try again later.',
            ], 503);
        }

        $request->attributes->set('school', $school);

        return $next($request);
    }

    /**
     * Resolve the school for this request.
     *
     * Priority: request attribute → explicit school_id header/param → single tenant school.
     * Results are cached for 60 s per tenant DB to avoid a query on every request.
     */
    protected function resolveSchool(Request $request): ?\App\Models\School
    {
        // Already resolved by a prior middleware (e.g. school-specific middleware).
        $school = $request->attributes->get('school');
        if ($school) {
            return $school;
        }

        // Use the active DB name as a cache namespace so tenant data never bleeds across.
        try {
            $dbNs = DB::connection()->getDatabaseName();
        } catch (\Throwable $e) {
            $dbNs = 'unknown';
        }

        // Explicit school_id in the request.
        $schoolId = $request->get('school_id') ?? $request->header('X-School-ID');
        if ($schoolId) {
            try {
                return Cache::remember("school:id:{$dbNs}:{$schoolId}", 60, fn () =>
                    \App\Models\School::find($schoolId)
                );
            } catch (\Exception $e) {
                return null;
            }
        }

        // Tenant DB: fall back to the single school. Stancl's InitializeTenancyByRequestData
        // does not set $request->attributes['tenant'], so we must also check tenancy()->initialized
        // (same pattern as Controller::getSchoolFromRequest).
        $tenant = $request->attributes->get('tenant');
        $inTenantContext = $tenant || (function_exists('tenancy') && tenancy()->initialized);

        if ($inTenantContext) {
            try {
                return Cache::remember("school:first:{$dbNs}", 60, function () use ($tenant) {
                    $tid = $tenant?->id ?? tenancy()->tenant?->getTenantKey();
                    $count = \App\Models\School::count();
                    if ($count > 1) {
                        Log::warning('ModuleAccessMiddleware: tenant has multiple schools but no school_id was provided; using first active record.', [
                            'tenant_id'    => $tid,
                            'school_count' => $count,
                        ]);
                    }

                    return \App\Models\School::where('status', 'active')->first()
                        ?? \App\Models\School::first();
                });
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }
}
