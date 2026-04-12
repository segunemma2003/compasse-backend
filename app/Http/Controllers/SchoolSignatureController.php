<?php

namespace App\Http\Controllers;

use App\Models\SchoolSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SchoolSignatureController extends Controller
{
    /**
     * List all signatures for a school.
     */
    public function index(int $schoolId): JsonResponse
    {
        $signatures = SchoolSignature::where('school_id', $schoolId)
            ->orderBy('role')
            ->get()
            ->map(fn ($s) => $this->withUrl($s));

        return response()->json(['signatures' => $signatures]);
    }

    /**
     * Create or update a signature entry.
     *
     * Pass `id` in the body to update an existing record; omit it to create a new one.
     * Pass a `signature` file (image/jpeg, image/png, image/svg+xml, max 2 MB) to
     * upload/replace the image.
     */
    public function upsert(Request $request, int $schoolId): JsonResponse
    {
        $request->validate([
            'id'        => ['nullable', 'integer', 'exists:school_signatures,id'],
            'role'      => ['required', 'string', 'max:100'],
            'name'      => ['required', 'string', 'max:255'],
            'active'    => ['nullable', 'boolean'],
            'signature' => ['nullable', 'file', 'mimes:jpeg,png,svg', 'max:2048'],
        ]);

        if ($request->input('id')) {
            // Update an existing signature that belongs to this school
            $sig = SchoolSignature::where('school_id', $schoolId)
                ->where('id', $request->input('id'))
                ->firstOrFail();

            $sig->update([
                'role'   => $request->input('role'),
                'name'   => $request->input('name'),
                'active' => $request->boolean('active', $sig->active),
            ]);
        } else {
            $sig = SchoolSignature::create([
                'school_id'      => $schoolId,
                'role'           => $request->input('role'),
                'name'           => $request->input('name'),
                'active'         => $request->boolean('active', true),
                'signature_path' => '',
            ]);
        }

        if ($request->hasFile('signature')) {
            // Remove the previous image file to avoid orphaned files
            if ($sig->signature_path) {
                Storage::disk('public')->delete($sig->signature_path);
            }
            $path = $request->file('signature')->store('school_signatures', 'public');
            $sig->signature_path = $path;
            $sig->save();
        }

        return response()->json(['signature' => $this->withUrl($sig)], $request->input('id') ? 200 : 201);
    }

    /**
     * Delete a signature.
     */
    public function delete(int $schoolId, int $signatureId): JsonResponse
    {
        $sig = SchoolSignature::where('school_id', $schoolId)
            ->where('id', $signatureId)
            ->firstOrFail();

        if ($sig->signature_path) {
            Storage::disk('public')->delete($sig->signature_path);
        }

        $sig->delete();

        return response()->json(['message' => 'Signature deleted.']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function withUrl(SchoolSignature $sig): array
    {
        $data = $sig->toArray();
        $data['signature_url'] = $sig->signature_path
            ? Storage::disk('public')->url($sig->signature_path)
            : null;
        return $data;
    }
}
