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
    private const SECRET = ['aloc_token', 'sms_api_key', 'whatsapp_token'];

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
            'whatsapp_token' => 'nullable|string|max:1000', 'whatsapp_phone_number_id' => 'nullable|string|max:50',
            'whatsapp_template' => 'nullable|string|max:100', 'whatsapp_template_lang' => 'nullable|string|max:10',
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

    /** GET /admin/tenants/{tenant}/messaging — attendance messaging for a school (on/off, sender name). */
    public function tenantMessaging(string $tenant): JsonResponse
    {
        return response()->json(['messaging' => \App\Support\SchoolMessaging::forTenant($tenant)]);
    }

    /** PUT /admin/tenants/{tenant}/messaging {enabled, sender_name?} */
    public function updateTenantMessaging(Request $request, string $tenant): JsonResponse
    {
        if (! \App\Models\Tenant::whereKey($tenant)->exists()) {
            return response()->json(['error' => 'School not found'], 404);
        }
        $v = $request->validate(['enabled' => 'required|boolean', 'sender_name' => ['nullable', 'string', 'max:11', 'regex:/^[A-Za-z0-9 ]*$/']],
            ['sender_name.max' => 'A sender name is at most 11 letters or numbers.', 'sender_name.regex' => 'Use letters, numbers and spaces only.']);
        \App\Support\SchoolMessaging::set($tenant, (bool) $v['enabled'], $v['sender_name'] ?? null);

        return $this->tenantMessaging($tenant);
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
