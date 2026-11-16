<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personal clock-in PINs (6 digits, unique in the school). Found by a keyed hash, so a PIN alone
 * identifies the person; stored encrypted as well so the school can print the PIN list.
 * A card (admission number / staff ID) works the same way at the gate kiosk.
 */
class ClockPin
{
    public static function ready(): bool
    {
        return Schema::hasColumn('users', 'clock_pin_hash');
    }

    public static function hash(string $pin): string
    {
        return hash_hmac('sha256', trim($pin), (string) config('app.key'));
    }

    /** Give the user a new unique PIN (or the one asked for) and return it. */
    public static function set(User $user, ?string $pin = null): string
    {
        if ($pin === null) {
            do {
                $pin = (string) random_int(100000, 999999);
            } while (DB::table('users')->where('clock_pin_hash', self::hash($pin))->exists());
        }
        DB::table('users')->where('id', $user->id)->update(['clock_pin_hash' => self::hash($pin), 'clock_pin' => Crypt::encryptString($pin)]);

        return $pin;
    }

    public static function taken(string $pin, ?int $exceptUserId = null): bool
    {
        return DB::table('users')->where('clock_pin_hash', self::hash($pin))->when($exceptUserId, fn ($q) => $q->where('id', '!=', $exceptUserId))->exists();
    }

    public static function show(?string $encrypted): ?string
    {
        try {
            return $encrypted ? Crypt::decryptString($encrypted) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** The person for a PIN, or for a card (student admission number / staff employee ID). */
    public static function find(string $code): ?User
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        if (self::ready() && ($u = User::where('clock_pin_hash', self::hash($code))->first())) {
            return $u;
        }
        $uid = DB::table('students')->whereNull('deleted_at')->where('admission_number', $code)->value('user_id');
        foreach (['teachers', 'staff'] as $table) {
            if (! $uid && Schema::hasTable($table) && Schema::hasColumn($table, 'employee_id')) {
                $uid = DB::table($table)->where('employee_id', $code)
                    ->when(Schema::hasColumn($table, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))->value('user_id');
            }
        }

        return $uid ? User::find($uid) : null;
    }
}
