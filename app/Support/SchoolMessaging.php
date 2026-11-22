<?php

namespace App\Support;

/**
 * Attendance messaging (SMS / WhatsApp) sold by Compasse: the super admin switches it on for a school
 * and sets the name messages go out under. While on, the school's messages use the platform's SMS and
 * WhatsApp accounts (Super admin → Platform settings) and the school does not enter any credentials.
 *
 * Stored centrally as platform setting "messaging:{tenant id}" = {"enabled": bool, "sender_name": "…"}.
 */
class SchoolMessaging
{
    private static function key(string $tenantId): string
    {
        return "messaging:{$tenantId}";
    }

    /** @return array{enabled: bool, sender_name: ?string} */
    public static function forTenant(?string $tenantId): array
    {
        if (! $tenantId) {
            return ['enabled' => false, 'sender_name' => null];
        }
        $raw = json_decode((string) PlatformSettings::get(self::key($tenantId)), true);

        return [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'sender_name' => ($n = trim((string) ($raw['sender_name'] ?? ''))) !== '' ? mb_substr($n, 0, 11) : null,
        ];
    }

    public static function set(string $tenantId, bool $enabled, ?string $senderName): void
    {
        PlatformSettings::set(self::key($tenantId), json_encode([
            'enabled' => $enabled,
            'sender_name' => $senderName !== null && trim($senderName) !== '' ? mb_substr(trim($senderName), 0, 11) : null,
        ]));
    }

    /** The school this request / job runs for. */
    public static function current(): array
    {
        $tenantId = function_exists('tenant') && tenant() ? (string) tenant('id') : null;

        return self::forTenant($tenantId);
    }

    /** Compasse provides this school's messaging. */
    public static function managed(): bool
    {
        return self::current()['enabled'];
    }
}
