<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

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
            'settings' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $tenant = $this->tenantService->createTenant($request->all());
            
            return response()->json([
                'message' => 'Tenant created successfully',
                'tenant' => $tenant,
                'school' => $tenant->schools()->first()
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create tenant',
                'message' => $e->getMessage()
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

        $tenants = $query->with('schools')
                        ->paginate($request->get('per_page', 15));

        return response()->json([
            'tenants' => $tenants
        ]);
    }
}
