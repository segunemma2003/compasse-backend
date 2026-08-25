<?php

namespace App\Http\Middleware;

use App\Models\School;
use App\Support\RoleCapabilityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route by the school-configurable capability matrix (RoleCapabilityService),
 * which — unlike the static `role:` middleware — can be adjusted per school and
 * overridden per individual user without touching route definitions or code.
 */
class CapabilityMiddleware
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized', 'message' => 'Authentication required'], 401);
        }

        $school = $this->resolveSchool($request);

        if (! RoleCapabilityService::userCan($user, $capability, $school?->id)) {
            return response()->json([
                'error'      => 'Forbidden',
                'message'    => 'You do not have permission for this action.',
                'capability' => $capability,
            ], 403);
        }

        return $next($request);
    }

    /**
     * Same resolution as ModuleAccessMiddleware: prefer whatever a prior
     * middleware already attached, otherwise fall back to the single school
     * in the current tenant DB.
     */
    protected function resolveSchool(Request $request): ?School
    {
        $school = $request->attributes->get('school');
        if ($school instanceof School) {
            return $school;
        }

        if (function_exists('tenancy') && tenancy()->initialized) {
            try {
                $school = School::where('status', 'active')->first() ?? School::first();
                if ($school) {
                    $request->attributes->set('school', $school);
                }

                return $school;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
