<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The password the school issued to an account, kept encrypted (with the app key) so
 * login slips can be printed again without resetting anyone. Gone once the person
 * changes their password themselves.
 */
class InitialPassword
{
    private static ?bool $hasColumn = null;

    private static function ok(): bool
    {
        try {
            return self::$hasColumn ??= Schema::hasColumn('users', 'initial_password');
        } catch (\Throwable) {
            return false;
        }
    }

    public static function remember(?User $user, string $plain): void
    {
        if ($user && self::ok()) {
            DB::table('users')->where('id', $user->id)->update(['initial_password' => Crypt::encryptString($plain)]);
        }
    }

    public static function get(?User $user): ?string
    {
        if (! $user || ! self::ok()) {
            return null;
        }
        $enc = DB::table('users')->where('id', $user->id)->value('initial_password');
        try {
            return $enc ? Crypt::decryptString($enc) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function forget(?User $user): void
    {
        if ($user && self::ok()) {
            DB::table('users')->where('id', $user->id)->update(['initial_password' => null]);
        }
    }
}
