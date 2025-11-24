<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class SettingController extends Controller
{
    /**
     * Get settings
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = DB::table('settings');

            if ($request->has('category')) {
                $query->where('category', $request->category);
            }

            if ($request->has('school_id')) {
                $query->where('school_id', $request->school_id);
            }

            $settings = $query->get()->pluck('value', 'key');

            return response()->json(['settings' => $settings]);
        } catch (\Exception $e) {
            return response()->json(['settings' => []]);
        }
    }

    /**
     * Update settings
     */
    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'settings' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $schoolId = $request->school_id ?? null;

        foreach ($request->settings as $key => $value) {
            DB::table('settings')->updateOrInsert(
                [
                    'key' => $key,
                    'school_id' => $schoolId
                ],
                [
                    'value' => is_array($value) ? json_encode($value) : $value,
                    'type' => $this->getValueType($value),
                    'updated_at' => now(),
                ]
            );
        }

        return response()->json([
            'message' => 'Settings updated successfully'
        ]);
    }

    /**
     * Get school settings
     */
    public function getSchoolSettings(Request $request): JsonResponse
    {
        $schoolId = $request->school_id ?? 1;

        $settings = DB::table('settings')
            ->where('school_id', $schoolId)
            ->get()
            ->pluck('value', 'key');

        return response()->json([
            'school_id' => $schoolId,
            'settings' => $settings
        ]);
    }

    /**
     * Update school settings
     */
    public function updateSchoolSettings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'settings' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        // Auto-get school_id from tenant context
        $schoolId = $this->getSchoolIdFromTenant($request);
        if (!$schoolId) {
            return response()->json([
                'error' => 'School not found',
                'message' => 'Unable to determine school from tenant context'
            ], 400);
        }

        foreach ($request->settings as $key => $value) {
            DB::table('settings')->updateOrInsert(
                [
                    'key' => $key,
                    'school_id' => $schoolId
                ],
                [
                    'value' => is_array($value) ? json_encode($value) : $value,
                    'type' => $this->getValueType($value),
                    'category' => 'school',
                    'updated_at' => now(),
                ]
            );
        }

        return response()->json([
            'message' => 'School settings updated successfully'
        ]);
    }

    /**
     * Get value type
     */
    protected function getValueType($value): string
    {
        if (is_bool($value)) return 'boolean';
        if (is_int($value)) return 'integer';
        if (is_array($value) || (is_string($value) && json_decode($value) !== null)) return 'json';
        return 'string';
    }
}
