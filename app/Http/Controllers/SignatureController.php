<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Models\SchoolSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Manage school-level digital signatures used on official documents
 * (report cards, paystubs, payment receipts, fee vouchers).
 *
 * All routes require [auth:sanctum] + [role:school_admin,principal,admin].
 * Data lives in the TENANT database.
 */
class SignatureController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    /**
     * List all signatures for the school.
     */
    public function index(Request $request): JsonResponse
    {
        $school = $this->school($request);
        if (! $school) {
            return response()->json(['signatures' => []]);
        }

        $signatures = SchoolSignature::where('school_id', $school->id)
            ->orderByDesc('active')
            ->orderBy('role')
            ->get();

        return response()->json(['signatures' => $signatures]);
    }

    /**
     * Upload and save a new signature image.
     */
    public function store(Request $request): JsonResponse
    {
        $school = $this->school($request);
        if (! $school) {
            return response()->json(['error' => 'School context not found'], 400);
        }

        $validator = Validator::make($request->all(), [
            'name'           => 'required|string|max:255',
            'role'           => 'required|string|max:100',
            'signature_file' => 'required|file|mimes:png,jpg,jpeg,webp|max:2048',
            'active'         => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $path = $request->file('signature_file')->store(
            "schools/{$school->id}/signatures",
            's3'
        );

        $url = Storage::disk('s3')->url($path);

        // If this signature is being set active, deactivate others with the same role
        if ($request->boolean('active', true)) {
            SchoolSignature::where('school_id', $school->id)
                ->where('role', $request->role)
                ->update(['active' => false]);
        }

        $signature = SchoolSignature::create([
            'school_id'      => $school->id,
            'name'           => $request->name,
            'role'           => $request->role,
            'signature_path' => $url,
            'active'         => $request->boolean('active', true),
        ]);

        return response()->json([
            'message'   => 'Signature uploaded successfully',
            'signature' => $signature,
        ], 201);
    }

    /**
     * Update a signature's name, role, or active status.
     * To replace the image, delete and re-upload.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $school = $this->school($request);
        $sig    = SchoolSignature::where('id', $id)
            ->where('school_id', $school?->id)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'name'   => 'sometimes|string|max:255',
            'role'   => 'sometimes|string|max:100',
            'active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // When activating this signature, deactivate peers with the same role
        if (isset($data['active']) && $data['active']) {
            $role = $data['role'] ?? $sig->role;
            SchoolSignature::where('school_id', $school?->id)
                ->where('role', $role)
                ->where('id', '!=', $id)
                ->update(['active' => false]);
        }

        $sig->update($data);

        return response()->json([
            'message'   => 'Signature updated',
            'signature' => $sig->fresh(),
        ]);
    }

    /**
     * Delete a signature and remove the S3 object.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $school = $this->school($request);
        $sig    = SchoolSignature::where('id', $id)
            ->where('school_id', $school?->id)
            ->firstOrFail();

        // Try to remove the S3 object (non-fatal on failure)
        try {
            $parsed = parse_url($sig->signature_path);
            if ($parsed && isset($parsed['path'])) {
                Storage::disk('s3')->delete(ltrim($parsed['path'], '/'));
            }
        } catch (\Throwable) {
            // Ignore — file may already be gone
        }

        $sig->delete();

        return response()->json(['message' => 'Signature deleted']);
    }

    /**
     * Return the active signatures for each role — used by document renderers.
     */
    public function active(Request $request): JsonResponse
    {
        $school = $this->school($request);
        if (! $school) {
            return response()->json(['signatures' => []]);
        }

        $signatures = SchoolSignature::where('school_id', $school->id)
            ->where('active', true)
            ->get()
            ->keyBy('role');

        return response()->json(['signatures' => $signatures]);
    }
}
