<?php

namespace App\Http\Controllers;

use App\Models\SchoolSignature;
use Illuminate\Http\Request;

class SchoolSignatureController extends Controller
{
    // List all signatures for a school
    public function index($schoolId)
    {
        $signatures = SchoolSignature::where('school_id', $schoolId)->get();
        return response()->json($signatures);
    }

    // Store or update a signature
    public function upsert(Request $request, $schoolId)
    {
        $signature = SchoolSignature::updateOrCreate(
            [
                'school_id' => $schoolId,
                'id' => $request->input('id'),
            ],
            [
                'role' => $request->input('role'),
                'name' => $request->input('name'),
                'active' => $request->input('active', true),
            ]
        );
        // Handle signature image upload
        if ($request->hasFile('signature')) {
            $path = $request->file('signature')->store('school_signatures', 'public');
            $signature->signature_path = $path;
            $signature->save();
        }
        return response()->json($signature);
    }

    // Delete a signature
    public function delete($schoolId, $signatureId)
    {
        $signature = SchoolSignature::where('school_id', $schoolId)->where('id', $signatureId)->firstOrFail();
        $signature->delete();
        return response()->json(['success' => true]);
    }
}
