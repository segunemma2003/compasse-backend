<?php

namespace App\Http\Controllers;

use App\Jobs\SendSMSJob;
use App\Support\PlatformSettings;
use App\Support\SchoolIntegrationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Super admin → Platform settings: ALOC token and the default SMS account for every school. */
class PlatformSettingsController extends Controller
{
    private const SECRET = ['aloc_token', 'sms_api_key'];

    /** GET /admin/platform-settings — secrets come back masked. */
    public function show(): JsonResponse
    {
        $out = [];
        foreach (PlatformSettings::KEYS as $k) {
            $v = PlatformSettings::get($k);
            $out[$k] = in_array($k, self::SECRET, true) ? null : $v;
            if (in_array($k, self::SECRET, true)) {
                $out[$k . '_set'] = $v !== null;
                $out[$k . '_hint'] = $v ? '••••' . substr($v, -4) : null;
            }
        }

        return response()->json(['settings' => $out]);
    }

    /** PUT /admin/platform-settings — an empty secret keeps the saved one; "clear" removes it. */
    public function update(Request $request): JsonResponse
    {
        $v = $request->validate([
            'aloc_token' => 'nullable|string|max:500', 'sms_provider' => 'nullable|in:smslive247,80kobosms,termii',
            'sms_username' => 'nullable|string|max:200', 'sms_api_key' => 'nullable|string|max:500', 'sms_sender_id' => 'nullable|string|max:11',
            'clear' => 'nullable|array', 'clear.*' => 'string',
        ]);
        foreach (PlatformSettings::KEYS as $k) {
            if (in_array($k, $v['clear'] ?? [], true)) {
                PlatformSettings::set($k, null);
            } elseif (in_array($k, self::SECRET, true)) {
                if (filled($v[$k] ?? null)) {
                    PlatformSettings::set($k, $v[$k]);
                }
            } elseif (array_key_exists($k, $v)) {
                PlatformSettings::set($k, $v[$k]);
            }
        }

        return $this->show();
    }

    /** POST /admin/platform-settings/test-sms {to} */
    public function testSms(Request $request): JsonResponse
    {
        $v = $request->validate(['to' => 'required|string|max:20']);
        PlatformSettings::flush();
        $s = SchoolIntegrationSettings::forSchool(null);
        if (! $s->hasSms()) {
            return response()->json(['error' => 'Choose the SMS provider and enter its login and key, then save, before sending a test.'], 422);
        }
        try {
            (new SendSMSJob($v['to'], 'Test message from Compasse — the platform SMS account is working.', $s->smsSenderId ?: 'Compasse', null))->handle();
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Test SMS sent to {$v['to']} via {$s->smsProvider}."]);
    }
}
