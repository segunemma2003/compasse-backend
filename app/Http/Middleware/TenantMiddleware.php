<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\TenantService;
use App\Models\Tenant;
use Illuminate\Support\Facades\Config;

class TenantMiddleware
{
    protected $tenantService;

    public function __construct(TenantService $tenantService)
    {
        $this->tenantService = $tenantService;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $host = $request->getHost();
        $tenant = null;

        if ($this->isExcludedDomain($host)) {
            $headerTenantId = $request->header('X-Tenant-ID');
            if ($headerTenantId) {
                $tenant = Tenant::find($headerTenantId);
            }

            if (!$tenant) {
                // Use main database for excluded domains without tenant context
                Config::set('database.default', 'mysql');
                return $next($request);
            }
        } else {
            $tenant = $this->resolveTenant($request);
        }

        if (!$tenant) {
            return response()->json([
                'error' => 'Tenant not found',
                'message' => 'The requested school or organization could not be found.'
            ], 404);
        }

        if (!$tenant->isActive()) {
            return response()->json([
                'error' => 'Tenant inactive',
                'message' => 'This school or organization is currently inactive.'
            ], 403);
        }

        // Switch to tenant database
        $this->tenantService->switchToTenant($tenant);

        // Store tenant in request for easy access
        $request->attributes->set('tenant', $tenant);

        // Set tenant context in config
        Config::set('tenant', $tenant->toArray());

        return $next($request);
    }

    /**
     * Resolve tenant from request
     */
    protected function resolveTenant(Request $request): ?Tenant
    {
        $host = $request->getHost();

        // Exclude API domain from tenant resolution
        if ($this->isExcludedDomain($host)) {
            return null;
        }

        // Method 1: From subdomain
        $subdomain = $this->extractSubdomain($host);

        if ($subdomain) {
            $tenant = $this->tenantService->getTenantBySubdomain($subdomain);
            if ($tenant) {
                return $tenant;
            }
        }

        // Method 2: From custom domain
        $tenant = $this->tenantService->getTenantByDomain($host);
        if ($tenant) {
            return $tenant;
        }

        // Method 3: From header (for API requests)
        $tenantId = $request->header('X-Tenant-ID');
        if ($tenantId) {
            return Tenant::find($tenantId);
        }

        // Method 4: From school_id parameter (for frontend integration)
        $schoolId = $request->get('school_id') ?? $request->header('X-School-ID');
        if ($schoolId) {
            $school = \App\Models\School::find($schoolId);
            return $school ? $school->tenant : null;
        }

        return null;
    }

    /**
     * Check if domain should be excluded from tenant resolution
     */
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
     * Extract subdomain from host
     */
    protected function extractSubdomain(string $host): ?string
    {
        $parts = explode('.', $host);

        // If we have at least 3 parts (subdomain.domain.tld)
        if (count($parts) >= 3) {
            return $parts[0];
        }

        return null;
    }
}
