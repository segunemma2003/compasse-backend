<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\SubscriptionService;

class ModuleAccessMiddleware
{
    protected $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $module)
    {
        // Try to get school from request attributes (set by middleware)
        $school = $request->attributes->get('school');

        // If not in attributes, try to get from tenant database
        if (!$school) {
            $tenant = $request->attributes->get('tenant');
            if ($tenant) {
                try {
                    // In tenant database, there's typically one school
                    $school = \App\Models\School::first();
                } catch (\Exception $e) {
                    // Table doesn't exist or query failed
                }
            }
        }

        // If still no school, try to get from school_id
        if (!$school) {
            $schoolId = $request->get('school_id') ?? $request->header('X-School-ID');
            if ($schoolId) {
                try {
                    $school = \App\Models\School::find($schoolId);
                } catch (\Exception $e) {
                    // Table doesn't exist
                }
            }
        }

        // If school is found, check module access (if subscription service is available)
        if ($school) {
            try {
                if (!$this->subscriptionService->hasModuleAccess($school, $module)) {
                    return response()->json([
                        'error' => 'Module access denied',
                        'message' => "This school does not have access to the {$module} module. Please upgrade your subscription.",
                        'module' => $module,
                        'upgrade_required' => true
                    ], 403);
                }
            } catch (\Exception $e) {
                // Subscription service unavailable or module check failed
                // Allow access to continue (graceful degradation)
            }
        }

        // Store school in request attributes for controllers to use
        if ($school) {
            $request->attributes->set('school', $school);
        }

        // Allow request to proceed even if school is not found
        // Controllers will handle missing school context appropriately
        return $next($request);
    }
}
