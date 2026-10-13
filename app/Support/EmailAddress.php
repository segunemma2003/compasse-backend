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
}
