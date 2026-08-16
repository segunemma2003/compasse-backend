<?php

namespace App\Http\Controllers;

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
}
