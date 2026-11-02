<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Super admin → Platform settings: keys for the whole platform (ALOC access token, the
 * default SMS account schools fall back to). Stored encrypted in the central database,
 * readable from inside a school's request too.
 */
class PlatformSettings
{
    public const KEYS = ['aloc_token', 'sms_provider', 'sms_username', 'sms_api_key', 'sms_sender_id'];

    private static ?array $cache = null;

    private static function table()
    {
        return DB::connection(config('tenancy.database.central_connection', config('database.default')))->table('platform_settings');
    }

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        try {
            if (! Schema::connection(config('tenancy.database.central_connection', config('database.default')))->hasTable('platform_settings')) {
                return self::$cache = [];
            }
            $out = [];
            foreach (self::table()->get(['key', 'value']) as $r) {
                try {
                    $out[$r->key] = $r->value === null || $r->value === '' ? null : Crypt::decryptString($r->value);
                } catch (\Throwable) {
                    $out[$r->key] = null;
                }
            }

            return self::$cache = $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function get(string $key): ?string
    {
        $v = self::all()[$key] ?? null;

        return $v === null || trim($v) === '' ? null : trim($v);
    }

    public static function set(string $key, ?string $value): void
    {
        self::table()->updateOrInsert(['key' => $key], [
            'value' => $value === null || trim($value) === '' ? null : Crypt::encryptString(trim($value)),
            'updated_at' => now(), 'created_at' => now(),
        ]);
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
