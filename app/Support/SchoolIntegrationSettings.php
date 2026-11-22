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
        public readonly ?string $smsUsername = null,
    ) {}

    public static function forSchool(?int $schoolId): self
    {
        if (! $schoolId || ! Schema::hasTable('settings')) {
            // No school (platform messages, super admin test): the platform SMS account.
            $e = self::empty();

            return new self(
                smtpHost: $e->smtpHost, smtpPort: $e->smtpPort, smtpUser: $e->smtpUser, smtpPassword: $e->smtpPassword, smtpFrom: $e->smtpFrom,
                smsProvider: PlatformSettings::get('sms_provider'), smsApiKey: PlatformSettings::get('sms_api_key'),
                smsSenderId: PlatformSettings::get('sms_sender_id'), smsUsername: PlatformSettings::get('sms_username'),
            );
        }

        $keys = [
            'smtp_host', 'smtp_port', 'smtp_user', 'smtp_password', 'smtp_from',
            'sms_provider', 'sms_api_key', 'sms_sender_id', 'sms_username',
        ];

        $rows = DB::table('settings')
            ->where('school_id', $schoolId)
            ->whereIn('key', $keys)
            ->pluck('value', 'key');

        // Attendance messaging from Compasse (switched on by the super admin): the platform's SMS
        // account under the school's sender name. Otherwise the school's own account, if it has one —
        // the platform's account is not used for schools that have not been given it.
        $managed = SchoolMessaging::current();
        $own = self::str($rows['sms_provider'] ?? null);

        return new self(
            smtpHost: self::str($rows['smtp_host'] ?? null),
            smtpPort: self::str($rows['smtp_port'] ?? null),
            smtpUser: self::str($rows['smtp_user'] ?? null),
            smtpPassword: self::str($rows['smtp_password'] ?? null),
            smtpFrom: self::str($rows['smtp_from'] ?? null),
            smsProvider: $managed['enabled'] ? PlatformSettings::get('sms_provider') : $own,
            smsApiKey: $managed['enabled'] ? PlatformSettings::get('sms_api_key') : ($own ? self::str($rows['sms_api_key'] ?? null) : null),
            smsSenderId: $managed['enabled'] ? ($managed['sender_name'] ?? PlatformSettings::get('sms_sender_id')) : self::str($rows['sms_sender_id'] ?? null),
            smsUsername: $managed['enabled'] ? PlatformSettings::get('sms_username') : ($own ? self::str($rows['sms_username'] ?? null) : null),
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
            'timeout'    => 30, // a mail server that hangs fails the attempt instead of holding the job
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
