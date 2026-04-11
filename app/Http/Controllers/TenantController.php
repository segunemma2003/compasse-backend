<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\TenantService;
use App\Jobs\ProvisionTenantJob;
use App\Jobs\MigrateTenantJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    protected $tenantService;

    public function __construct(TenantService $tenantService)
    {
        $this->tenantService = $tenantService;
    }

    /**
     * Create a new tenant
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'domain' => 'nullable|string|max:255',
            'subdomain' => 'required|string|max:255|unique:tenants,subdomain',
            'school' => 'required|array',
            'school.name' => 'required|string|max:255',
            'school.address' => 'nullable|string',
            'school.phone' => 'nullable|string|max:20',
            'school.email' => 'nullable|email|max:255',
            'school.website' => 'nullable|url|max:255',
            'school.admin_name' => 'nullable|string|max:255',
            'school.admin_email' => 'nullable|email|max:255|unique:users,email',
            'school.admin_password' => 'nullable|string|min:8',
            'settings' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->tenantService->createTenant($request->all());
            $tenant = $result['tenant'];

            return response()->json([
                'message' => 'Tenant provisioning started. The school database is being set up in the background. Login credentials will be emailed to the admin once ready.',
                'tenant'  => $tenant,
                'status'  => 'provisioning',
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to create tenant',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get tenant by subdomain
     */
    public function getBySubdomain(string $subdomain): JsonResponse
    {
        $tenant = $this->tenantService->getTenantBySubdomain($subdomain);

        if (!$tenant) {
            return response()->json([
                'error' => 'Tenant not found'
            ], 404);
        }

        return response()->json([
            'tenant' => $tenant,
            'schools' => $tenant->schools
        ]);
    }

    /**
     * Verify if tenant exists (public endpoint, no auth required)
     */
    public function verify(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subdomain' => 'nullable|string|max:255',
            'domain' => 'nullable|string|max:255',
            'tenant_id' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $tenant = null;

        if ($request->has('subdomain')) {
            $tenant = Tenant::where('subdomain', $request->subdomain)->first();
        } elseif ($request->has('domain')) {
            $tenant = Tenant::where('domain', $request->domain)->first();
        } elseif ($request->has('tenant_id')) {
            $tenant = Tenant::find($request->tenant_id);
        } else {
            return response()->json([
                'error' => 'Validation failed',
                'message' => 'Please provide subdomain, domain, or tenant_id'
            ], 422);
        }

        if (!$tenant) {
            return response()->json([
                'exists' => false,
                'message' => 'Tenant not found'
            ], 200);
        }

        return response()->json([
            'exists' => true,
            'tenant_id' => $tenant->id,
            'name' => $tenant->name,
            'subdomain' => $tenant->subdomain,
            'domain' => $tenant->domain,
            'status' => $tenant->status,
        ], 200);
    }

    /**
     * Get tenant statistics
     */
    public function stats(Tenant $tenant): JsonResponse
    {
        try {
            $stats = $this->tenantService->getTenantStats($tenant);

            return response()->json([
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to get tenant statistics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show a single tenant
     */
    public function show(Tenant $tenant): JsonResponse
    {
        $tenant->loadCount('schools');

        return response()->json([
            'tenant' => $tenant,
            'schools_count' => $tenant->schools_count,
        ]);
    }

    /**
     * Update tenant
     */
    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'domain' => 'nullable|string|max:255',
            'subdomain' => 'sometimes|string|max:255|unique:tenants,subdomain,' . $tenant->id,
            'status' => 'sometimes|in:active,inactive,suspended',
            'settings' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $tenant->update($request->only(['name', 'domain', 'subdomain', 'status', 'settings']));

            return response()->json([
                'message' => 'Tenant updated successfully',
                'tenant' => $tenant
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update tenant',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete tenant
     */
    public function destroy(Tenant $tenant): JsonResponse
    {
        try {
            $this->tenantService->deleteTenant($tenant);

            return response()->json([
                'message' => 'Tenant deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete tenant',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get provisioning status, DB existence, and pending migrations for a tenant.
     */
    public function provisionStatus(Tenant $tenant): JsonResponse
    {
        $dbName   = $tenant->database_name;
        $dbExists = false;
        $pendingMigrations = null;

        if ($dbName) {
            $result   = DB::select(
                'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
                [$dbName]
            );
            $dbExists = !empty($result);
        }

        if ($dbExists) {
            try {
                tenancy()->initialize($tenant);
                $migrator = app('migrator');

                if ($migrator->repositoryExists()) {
                    $ran          = $migrator->getRepository()->getRan();
                    $files        = $migrator->getMigrationFiles(database_path('migrations/tenant'));
                    $pending      = array_diff(array_keys($files), $ran);
                    $pendingMigrations = count($pending);
                }

                tenancy()->end();
            } catch (\Throwable $e) {
                try { tenancy()->end(); } catch (\Throwable $ignored) {}
                Log::warning('provisionStatus migration check failed', [
                    'tenant_id' => $tenant->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'tenant_status'      => $tenant->status,
            'db_exists'          => $dbExists,
            'db_name'            => $dbName,
            'pending_migrations' => $pendingMigrations,
        ]);
    }

    /**
     * Re-provision a failed or stuck tenant (drops orphaned DB, resets status, re-dispatches job).
     */
    public function reprovision(Tenant $tenant): JsonResponse
    {
        if (!in_array($tenant->status, ['failed', 'provisioning'])) {
            return response()->json([
                'error' => 'Tenant must be in failed or provisioning state to re-provision'
            ], 422);
        }

        // Drop orphaned DB if one was created previously
        if ($tenant->database_name) {
            $this->tenantService->dropDatabaseSafe($tenant->database_name);
        }

        // Generate a fresh DB name so there's no collision
        $newDbName = now()->format('YmdHis') . '_' . Str::slug($tenant->name, '_');
        $tenant->update([
            'status'        => 'provisioning',
            'database_name' => $newDbName,
        ]);

        // Retrieve stored school data (saved at original creation time)
        $settings   = $tenant->settings ?? [];
        $schoolData = $settings['pending_school_data'] ?? ['name' => $tenant->name];

        ProvisionTenantJob::dispatch($tenant->id, $schoolData)
            ->onQueue('tenant-provisioning');

        return response()->json([
            'message' => 'Re-provisioning started in the background',
            'status'  => 'provisioning',
        ]);
    }

    /**
     * Dispatch a background job to run any pending migrations on a tenant DB.
     */
    public function runMigrations(Tenant $tenant): JsonResponse
    {
        if ($tenant->status !== 'active') {
            return response()->json(['error' => 'Tenant must be active to run migrations'], 422);
        }

        MigrateTenantJob::dispatch($tenant->id)
            ->onQueue('tenant-provisioning');

        return response()->json([
            'message' => 'Migration job dispatched — check back in a few seconds',
        ]);
    }

    /**
     * List all tenants (for super admin)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Tenant::query();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('subdomain', 'like', "%{$search}%")
                  ->orWhere('domain', 'like', "%{$search}%");
            });
        }

        // Use withCount to avoid fetching all school records — avoids unbounded payloads.
        $tenants = $query->withCount('schools')
                         ->paginate($request->get('per_page', 15));

        return response()->json([
            'tenants' => $tenants
        ]);
    }
}
