<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\TenantService;
use App\Models\Tenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class TenantMiddleware
{
    protected $tenantService;

    public function __construct(TenantService $tenantService)
    {
        $this->tenantService = $tenantService;
    }

    public function handle(Request $request, Closure $next)
    {
        $host   = $request->getHost();
        $tenant = null;

        if ($this->isExcludedDomain($host)) {
            $tenant = $this->resolveTenantFromApiRequest($request);

            if (!$tenant) {
                // No tenant context on the central domain — super-admin routes proceed normally.
                Config::set('database.default', 'mysql');
                return $next($request);
            }
        } else {
            $tenant = $this->resolveTenant($request);
        }

        if (!$tenant) {
            return response()->json([
                'error'   => 'Tenant not found',
                'message' => 'The requested school or organization could not be found.',
            ], 404);
        }

        if (!$tenant->isActive()) {
            return response()->json([
                'error'   => 'Tenant inactive',
                'message' => 'This school or organization is currently inactive.',
            ], 403);
        }

        // Purge the old tenant connection BEFORE switching so stale state is cleared.
        DB::purge('tenant');

        $this->tenantService->switchToTenant($tenant);

        // Store only non-sensitive tenant context for use in controllers.
        $request->attributes->set('tenant', $tenant);
        Config::set('tenant.id',        $tenant->id);
        Config::set('tenant.name',      $tenant->name);
        Config::set('tenant.subdomain', $tenant->subdomain);
        Config::set('tenant.status',    $tenant->status);

        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);

        return $next($request);
    }

    /**
     * Resolve tenant from a sub-domain request.
     * NOTE: isExcludedDomain() is handled by the caller — not repeated here.
     */
    protected function resolveTenant(Request $request): ?Tenant
    {
        $host = $request->getHost();

        // Method 1: subdomain (e.g. school.samschool.com)
        $subdomain = $this->extractSubdomain($host);
        if ($subdomain) {
            $tenant = $this->tenantService->getTenantBySubdomain($subdomain);
            if ($tenant) {
                return $tenant;
            }
        }

        // Method 2: custom domain
        $tenant = $this->tenantService->getTenantByDomain($host);
        if ($tenant) {
            return $tenant;
        }

        // Method 3: explicit header
        $tenantId = $request->header('X-Tenant-ID');
        if ($tenantId) {
            return Tenant::find($tenantId);
        }

        // Method 4: request body
        $tenantId = $request->input('tenant_id');
        if ($tenantId) {
            return Tenant::find($tenantId);
        }

        // Method 5: school_id
        $schoolId = $request->get('school_id') ?? $request->header('X-School-ID');
        if ($schoolId) {
            $school = \App\Models\School::find($schoolId);
            return $school ? $school->tenant : null;
        }

        return null;
    }

    protected function isExcludedDomain(string $host): bool
    {
        $excludedDomains = config('tenant.excluded_domains', [
            'api.compasse.net',
            'localhost',
            '127.0.0.1',
        ]);

        return in_array(strtolower($host), array_map('strtolower', $excludedDomains));
    }

    /**
     * Resolve tenant from an api.compasse.net request via headers/body.
     */
    protected function resolveTenantFromApiRequest(Request $request): ?Tenant
    {
        Config::set('database.default', 'mysql');
        DB::purge('mysql');

        try {
            DB::connection('mysql')->getPdo();
        } catch (\Exception $e) {
            return null;
        }

        $subdomain = $request->header('X-Subdomain') ?? $request->input('subdomain');
        if ($subdomain) {
            $tenant = $this->tenantService->getTenantBySubdomain($subdomain);
            if ($tenant) {
                return $tenant;
            }
        }

        $tenantId = $request->header('X-Tenant-ID') ?? $request->input('tenant_id');
        if ($tenantId) {
            $tenant = Tenant::on('mysql')->find($tenantId);
            if ($tenant) {
                return $tenant;
            }
        }

        $schoolName = $request->header('X-School-Name') ?? $request->input('school_name');
        if ($schoolName) {
            $school = \App\Models\School::on('mysql')->where('name', $schoolName)->first();
            if ($school && $school->tenant) {
                return $school->tenant;
            }
        }

        $schoolId = $request->header('X-School-ID') ?? $request->input('school_id');
        if ($schoolId) {
            $school = \App\Models\School::on('mysql')->find($schoolId);
            if ($school && $school->tenant) {
                return $school->tenant;
            }
        }

        return null;
    }

    protected function extractSubdomain(string $host): ?string
    {
        $parts = explode('.', $host);
        return count($parts) >= 3 ? $parts[0] : null;
    }
}
