<?php

namespace App\Support;

use App\Models\School;
use Illuminate\Support\Str;

/**
 * The domain used for logins the system makes up (students and parents without an email):
 * the school's website, else the school's own email domain, else <school>.compasse.net.
 * Never plain compasse.net, so every made-up address carries the school's name.
 */
class SchoolEmailDomain
{
    private const PUBLIC_PROVIDERS = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'ymail.com', 'outlook.com', 'hotmail.com',
        'live.com', 'msn.com', 'icloud.com', 'me.com', 'aol.com', 'proton.me', 'protonmail.com', 'zoho.com', 'mail.com',
    ];

    public static function for(?School $school = null): string
    {
        $school ??= self::currentSchool();

        if ($school?->website) {
            $host = strtolower(preg_replace('/^(https?:\/\/)?(www\.)?/i', '', (string) $school->website));
            $host = rtrim(explode('/', $host)[0] ?? '', '/');
            if ($host !== '' && str_contains($host, '.')) {
                return $host;
            }
        }

        $emailDomain = strtolower((string) substr(strrchr((string) $school?->email, '@') ?: '', 1));
        $root = config('app.root_domain', 'compasse.net');
        if ($emailDomain !== '' && ! in_array($emailDomain, self::PUBLIC_PROVIDERS, true)
            && $emailDomain !== $root && ! str_ends_with($emailDomain, '.' . $root)) {
            return $emailDomain;
        }

        $sub = null;
        try {
            $sub = (function_exists('tenant') ? tenant()?->subdomain : null) ?? $school?->tenant?->subdomain;
        } catch (\Throwable) {
        }
        $sub = $sub ?: Str::slug((string) $school?->name, '') ?: 'school';

        return strtolower($sub) . '.' . $root;
    }

    /** True for an address in the shape the system generates (name.surname12@<this school's domain>). */
    public static function looksGenerated(?string $email, ?School $school = null): bool
    {
        $email = strtolower((string) $email);
        $local = strstr($email, '@', true) ?: '';
        $domain = substr(strrchr($email, '@') ?: '', 1);
        if (! preg_match('/^[a-z]+\.[a-z]+\d+(\.[a-z0-9]+)?$/', $local)) {
            return false;
        }
        $root = config('app.root_domain', 'compasse.net');

        return $domain === self::for($school) || $domain === $root || str_ends_with($domain, '.' . $root) || str_ends_with($domain, 'samschool.com');
    }

    private static function currentSchool(): ?School
    {
        try {
            return function_exists('tenancy') && tenancy()->initialized ? School::first() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
