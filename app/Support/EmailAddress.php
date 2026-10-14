<?php

namespace App\Support;

/**
 * Tells a real mailbox from the stand-in addresses the system makes up for
 * people who have no email (e.g. "ada.eze12@school.compasse.net"), so
 * mass emails don't waste sending quota on addresses nobody reads.
 */
class EmailAddress
{
    public static function isReal(?string $email): bool
    {
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $root = preg_quote(config('app.root_domain', 'compasse.net'), '/');

        return ! preg_match("/@([a-z0-9-]+\\.)?{$root}$|^temp\\.|@temp\\.samschool\\.com$|\\.samschool\\.com$/i", $email);
    }

    /**
     * A made-up address on a school's own subdomain (or a temp import address) — nobody reads it.
     * Unlike isReal(), addresses directly on the root domain (support@, noreply@) are not placeholders.
     */
    public static function isPlaceholder(?string $email): bool
    {
        $root = preg_quote(config('app.root_domain', 'compasse.net'), '/');

        return (bool) preg_match("/@[a-z0-9-]+\\.{$root}$|^temp\\.|@temp\\.samschool\\.com$|\\.samschool\\.com$/i", (string) $email);
    }
}
