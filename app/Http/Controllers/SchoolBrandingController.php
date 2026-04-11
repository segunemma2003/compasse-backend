<?php

namespace App\Http\Controllers;

use App\Models\SchoolBranding;
use App\Models\SchoolHomepageSection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SchoolBrandingController extends Controller
{
    // Get branding info for a school
    public function show($schoolId)
    {
        $branding = SchoolBranding::where('school_id', $schoolId)->with('sections')->first();
        return response()->json($branding);
    }

    // Update branding info (title, content, logo, signature)
    public function update(Request $request, $schoolId)
    {
        $branding = SchoolBranding::updateOrCreate(
            ['school_id' => $schoolId],
            [
                'homepage_title' => $request->input('homepage_title'),
                'homepage_content' => $request->input('homepage_content'),
            ]
        );

        // Handle logo upload
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('school_logos', 'public');
            $branding->logo_path = $path;
            $branding->save();
        }
        // Handle signature upload
        if ($request->hasFile('signature')) {
            $path = $request->file('signature')->store('school_signatures', 'public');
            $branding->signature_path = $path;
            $branding->save();
        }
        return response()->json($branding);
    }

    // Add or update homepage section
    public function upsertSection(Request $request, $schoolId)
    {
        $section = SchoolHomepageSection::updateOrCreate(
            [
                'school_id' => $schoolId,
                'id' => $request->input('id'),
            ],
            [
                'title' => $request->input('title'),
                'content' => $request->input('content'),
                'order' => $request->input('order', 0),
            ]
        );
        // Handle section image upload
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('school_sections', 'public');
            $section->image_path = $path;
            $section->save();
        }
        return response()->json($section);
    }

    // Delete a homepage section
    public function deleteSection($schoolId, $sectionId)
    {
        $section = SchoolHomepageSection::where('school_id', $schoolId)->where('id', $sectionId)->firstOrFail();
        $section->delete();
        return response()->json(['success' => true]);
    }
}
