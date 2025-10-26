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
        $school = $request->attributes->get('school');

        if (!$school) {
            return response()->json([
                'error' => 'School not found',
                'message' => 'Unable to determine school context.'
            ], 404);
        }

        // Check if school has access to the module
        if (!$this->subscriptionService->hasModuleAccess($school, $module)) {
            return response()->json([
                'error' => 'Module access denied',
                'message' => "This school does not have access to the {$module} module. Please upgrade your subscription.",
                'module' => $module,
                'upgrade_required' => true
            ], 403);
        }

        return $next($request);
    }
}
