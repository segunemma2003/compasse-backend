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
            ->with('teacher:id,first_name,last_name,employee_id')
            ->orderByDesc('active')
            ->orderBy('role')
            ->get();

        return response()->json(['signatures' => $signatures]);
    }

    /**
     * Upload and save a new signature image.
     *
     * Pass teacher_id to set this up as a specific teacher's own signature
     * for a role (e.g. their class_teacher signature on report cards),
     * rather than the school-wide default everyone with that role shares —
     * this is the same personalization a teacher can already do themselves
     * from "My Signature", surfaced here so an admin can set one up for a
     * teacher directly instead of relying on them to discover and use it.
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
            'teacher_id'     => 'nullable|exists:teachers,id',
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

        // Deactivate the other signature this same role+scope would
        // otherwise share — a school-wide default vs a specific teacher's
        // own are independent slots, so setting a teacher's signature
        // active must not touch (or be touched by) the shared default for
        // that role, and vice versa.
        if ($request->boolean('active', true)) {
            SchoolSignature::where('school_id', $school->id)
                ->where('role', $request->role)
                ->where('teacher_id', $request->teacher_id ?: null)
                ->update(['active' => false]);
        }

        $signature = SchoolSignature::create([
            'school_id'      => $school->id,
            'name'           => $request->name,
            'role'           => $request->role,
            'teacher_id'     => $request->teacher_id ?: null,
            'signature_path' => $url,
            'active'         => $request->boolean('active', true),
        ]);

        return response()->json([
            'message'   => 'Signature uploaded successfully',
            'signature' => $signature->load('teacher:id,first_name,last_name,employee_id'),
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

        // When activating this signature, deactivate peers in the same
        // role+scope only (this signature's own teacher_id, whether that's
        // a specific teacher or the shared null default) — not the other scope.
        if (isset($data['active']) && $data['active']) {
            $role = $data['role'] ?? $sig->role;
            SchoolSignature::where('school_id', $school?->id)
                ->where('role', $role)
                ->where('teacher_id', $sig->teacher_id)
                ->where('id', '!=', $id)
                ->update(['active' => false]);
        }

        $sig->update($data);

        return response()->json([
            'message'   => 'Signature updated',
            'signature' => $sig->fresh()->load('teacher:id,first_name,last_name,employee_id'),
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

    // ── Self-service: a teacher's own signature ─────────────────────────────
    // Report cards use this in place of the shared role-level "class teacher"
    // signature for whichever class this teacher actually teaches — see
    // SchoolSignature::resolveForReportCard(). Unlike store()/update() above,
    // this is not admin-only: any teacher may set their own.

    public function showMine(Request $request): JsonResponse
    {
        $school = $this->school($request);
        $teacher = $request->user()?->teacher;
        if (! $school || ! $teacher) {
            return response()->json(['signature' => null]);
        }

        $signature = SchoolSignature::where('school_id', $school->id)
            ->where('teacher_id', $teacher->id)
            ->first();

        return response()->json(['signature' => $signature]);
    }

    public function storeMine(Request $request): JsonResponse
    {
        $school = $this->school($request);
        $teacher = $request->user()?->teacher;
        if (! $school || ! $teacher) {
            return response()->json(['error' => 'No teacher profile linked to your account.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'signature_file' => 'required|file|mimes:png,jpg,jpeg,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $path = $request->file('signature_file')->store(
            "schools/{$school->id}/signatures/teachers",
            's3'
        );
        $url = Storage::disk('s3')->url($path);

        // One signature per teacher — replace rather than accumulate.
        $signature = SchoolSignature::updateOrCreate(
            ['school_id' => $school->id, 'teacher_id' => $teacher->id],
            [
                'role' => 'class_teacher',
                'name' => $request->user()->name,
                'signature_path' => $url,
                'active' => true,
            ]
        );

        return response()->json(['message' => 'Signature saved', 'signature' => $signature], 201);
    }

    public function destroyMine(Request $request): JsonResponse
    {
        $school = $this->school($request);
        $teacher = $request->user()?->teacher;
        if (! $school || ! $teacher) {
            return response()->json(['error' => 'No teacher profile linked to your account.'], 422);
        }

        $signature = SchoolSignature::where('school_id', $school->id)
            ->where('teacher_id', $teacher->id)
            ->first();

        if ($signature) {
            try {
                $parsed = parse_url($signature->signature_path);
                if ($parsed && isset($parsed['path'])) {
                    Storage::disk('s3')->delete(ltrim($parsed['path'], '/'));
                }
            } catch (\Throwable) {
            }
            $signature->delete();
        }

        return response()->json(['message' => 'Signature removed']);
    }
}
