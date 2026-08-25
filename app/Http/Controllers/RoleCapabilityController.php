<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\RoleCapabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RoleCapabilityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $schoolId = $this->getSchoolIdFromTenant($request);
        if (! $schoolId) {
            return response()->json(['error' => 'School not found'], 400);
        }

        return response()->json(RoleCapabilityService::payloadForSchool($schoolId));
    }

    public function update(Request $request): JsonResponse
    {
        $schoolId = $this->getSchoolIdFromTenant($request);
        if (! $schoolId) {
            return response()->json(['error' => 'School not found'], 400);
        }

        $validator = Validator::make($request->all(), [
            'matrix' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        RoleCapabilityService::saveForSchool($schoolId, $request->input('matrix'));

        return response()->json([
            'message' => 'Role access settings saved',
            ...RoleCapabilityService::payloadForSchool($schoolId),
        ]);
    }

    /**
     * Get one user's capability overrides (grants/revokes beyond their role),
     * alongside their effective access so the UI can show what's inherited.
     */
    public function showForUser(Request $request, User $user): JsonResponse
    {
        $schoolId = $this->getSchoolIdFromTenant($request);
        if (! $schoolId) {
            return response()->json(['error' => 'School not found'], 400);
        }

        $capabilities = RoleCapabilityService::allCapabilities($schoolId);
        $overrides = RoleCapabilityService::overridesForUser($schoolId, $user->id);
        $effective = [];
        foreach (array_keys($capabilities) as $cap) {
            $effective[$cap] = RoleCapabilityService::userCan($user, $cap, $schoolId);
        }

        return response()->json([
            'capabilities' => $capabilities,
            'role'         => $user->role,
            'overrides'    => $overrides,
            'effective'    => $effective,
        ]);
    }

    /**
     * List built-in + this school's custom permissions.
     */
    public function indexPermissions(Request $request): JsonResponse
    {
        $schoolId = $this->getSchoolIdFromTenant($request);
        if (! $schoolId) {
            return response()->json(['error' => 'School not found'], 400);
        }

        $custom = \App\Models\CustomPermission::where('school_id', $schoolId)->orderBy('name')->get(['slug', 'name', 'description']);

        return response()->json([
            'built_in' => RoleCapabilityService::CAPABILITIES,
            'custom'   => $custom,
        ]);
    }

    /**
     * Define a new permission slug for this school — usable anywhere a built-in
     * capability is (role matrix, per-user overrides), for the school's own
     * workflows even where no built-in code gate exists for it yet.
     */
    public function storePermission(Request $request): JsonResponse
    {
        $schoolId = $this->getSchoolIdFromTenant($request);
        if (! $schoolId) {
            return response()->json(['error' => 'School not found'], 400);
        }

        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $permission = RoleCapabilityService::createCustomPermission(
            $schoolId,
            $request->input('name'),
            $request->input('description'),
            $request->user()?->id
        );

        return response()->json([
            'message'    => 'Permission created',
            'permission' => $permission,
        ], 201);
    }

    public function destroyPermission(Request $request, string $slug): JsonResponse
    {
        $schoolId = $this->getSchoolIdFromTenant($request);
        if (! $schoolId) {
            return response()->json(['error' => 'School not found'], 400);
        }

        if (isset(RoleCapabilityService::CAPABILITIES[$slug])) {
            return response()->json(['error' => 'Built-in permissions cannot be deleted'], 422);
        }

        $deleted = RoleCapabilityService::deleteCustomPermission($schoolId, $slug);
        if (! $deleted) {
            return response()->json(['error' => 'Permission not found'], 404);
        }

        return response()->json(['message' => 'Permission deleted']);
    }

    /**
     * Grant or revoke individual capabilities for this user, independent of
     * (and taking precedence over) their role. Pass null for a capability to
     * clear the override and fall back to the role default.
     */
    public function updateForUser(Request $request, User $user): JsonResponse
    {
        $schoolId = $this->getSchoolIdFromTenant($request);
        if (! $schoolId) {
            return response()->json(['error' => 'School not found'], 400);
        }

        $validator = Validator::make($request->all(), [
            'overrides'   => 'required|array',
            'overrides.*' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        RoleCapabilityService::saveUserOverrides($schoolId, $user->id, $request->input('overrides'));

        return response()->json([
            'message' => 'User permissions saved',
            ...$this->showForUser($request, $user)->getData(true),
        ]);
    }
}
