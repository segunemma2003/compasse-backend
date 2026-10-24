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
        if ($denied = $this->requireCapability($request, 'settings.manage')) {
            return $denied;
        }

        // Accept either direct key-value pairs or nested in "settings"
        $settingsData = $request->has('settings') ? $request->input('settings') : $request->all();
        
        if (empty($settingsData)) {
            return response()->json([
                'error' => 'No settings provided'
            ], 422);
        }

        $schoolId = $request->school_id ?? null;

        foreach ($settingsData as $key => $value) {
            // Skip non-setting fields
            if (in_array($key, ['school_id', '_token', '_method'])) {
                continue;
            }
            
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
        // The tenant's own school (it was hard-coded to 1 when no school_id was passed).
        $schoolId = $this->getSchoolIdFromTenant($request) ?? $request->school_id ?? 1;

        $settings = DB::table('settings')
            ->where('school_id', $schoolId)
            ->get()
            ->pluck('value', 'key');

        // Everyone can read school settings, but credentials only go to those who manage them.
        if (! $this->roleCan($request, 'settings.manage')) {
            $settings = $settings->reject(fn ($v, $k) => (bool) preg_match('/password|secret|api_key|token|smtp_user/i', (string) $k));
        }

        return response()->json([
            'school_id' => $schoolId,
            'settings' => $settings
        ]);
    }

    /**
     * Update school settings
     */
    /** POST /settings/sms/test {to} — send one SMS now with the saved provider and show its reply. */
    public function testSms(Request $request): JsonResponse
    {
        $v = $request->validate(['to' => 'required|string|max:20']);
        $school = \App\Models\School::first();
        $settings = \App\Support\SchoolIntegrationSettings::forSchool($school?->id);
        if (! $settings->hasSms()) {
            return response()->json(['error' => 'Choose the SMS provider and enter its credentials, then save, before sending a test.'], 422);
        }
        try {
            (new \App\Jobs\SendSMSJob($v['to'], 'Test message from ' . ($school?->name ?? 'Compasse') . ' — SMS is working.', $settings->smsSenderId ?: 'Compasse', $school?->id ? (string) $school->id : null))->handle();
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Test SMS sent to {$v['to']} via {$settings->smsProvider}."]);
    }

    public function updateSchoolSettings(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'settings.manage')) {
            return $denied;
        }

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
