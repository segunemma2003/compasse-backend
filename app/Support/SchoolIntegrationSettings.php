<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-school SMTP/SMS credentials stored in the tenant settings table.
 */
class SchoolIntegrationSettings
{
    public function __construct(
        public readonly ?string $smtpHost,
        public readonly ?string $smtpPort,
        public readonly ?string $smtpUser,
        public readonly ?string $smtpPassword,
        public readonly ?string $smtpFrom,
        public readonly ?string $smsProvider,
        public readonly ?string $smsApiKey,
        public readonly ?string $smsSenderId,
    ) {}

    public static function forSchool(?int $schoolId): self
    {
        if (! $schoolId || ! Schema::hasTable('settings')) {
            return self::empty();
        }

        $keys = [
            'smtp_host', 'smtp_port', 'smtp_user', 'smtp_password', 'smtp_from',
            'sms_provider', 'sms_api_key', 'sms_sender_id',
        ];

        $rows = DB::table('settings')
            ->where('school_id', $schoolId)
            ->whereIn('key', $keys)
            ->pluck('value', 'key');

        return new self(
            smtpHost: self::str($rows['smtp_host'] ?? null),
            smtpPort: self::str($rows['smtp_port'] ?? null),
            smtpUser: self::str($rows['smtp_user'] ?? null),
            smtpPassword: self::str($rows['smtp_password'] ?? null),
            smtpFrom: self::str($rows['smtp_from'] ?? null),
            smsProvider: self::str($rows['sms_provider'] ?? null),
            smsApiKey: self::str($rows['sms_api_key'] ?? null),
            smsSenderId: self::str($rows['sms_sender_id'] ?? null),
        );
    }

    public function hasSmtp(): bool
    {
        return (bool) ($this->smtpHost && $this->smtpUser);
    }

    public function hasSms(): bool
    {
        return (bool) ($this->smsProvider && $this->smsApiKey);
    }

    public function smtpMailConfig(): array
    {
        return [
            'transport'  => 'smtp',
            'host'       => $this->smtpHost,
            'port'       => (int) ($this->smtpPort ?: 587),
            'encryption' => ((int) ($this->smtpPort ?: 587) === 465) ? 'ssl' : 'tls',
            'username'   => $this->smtpUser,
            'password'   => $this->smtpPassword,
            'timeout'    => null,
        ];
    }

    private static function empty(): self
    {
        return new self(null, null, null, null, null, null, null, null);
    }

    private static function str(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return trim((string) $v);
    }
}
