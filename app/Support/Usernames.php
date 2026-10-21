<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every account gets a username to sign in with — so a parent (or anyone) without an email
 * still has a login: students their admission number, teachers / staff their staff ID,
 * everyone else a short name (ffelix, ffelix2, …).
 */
class Usernames
{
    public static function enabled(): bool
    {
        try {
            return Schema::hasColumn('users', 'username');
        } catch (\Throwable) {
            return false;
        }
    }

    /** The natural username for this person, before making it unique. */
    public static function baseFor(User $u): string
    {
        $id = null;
        if ($u->role === 'student' && Schema::hasTable('students')) {
            $id = DB::table('students')->where('user_id', $u->id)->value('admission_number');
        } elseif (Schema::hasTable('teachers') && ($t = DB::table('teachers')->where('user_id', $u->id)->value('employee_id'))) {
            $id = $t;
        } elseif (Schema::hasTable('staff') && ($t = DB::table('staff')->where('user_id', $u->id)->value('employee_id'))) {
            $id = $t;
        }
        if ($id) {
            return strtolower(preg_replace('/[^A-Za-z0-9._\/-]/', '', (string) $id));
        }
        $words = array_values(array_filter(preg_split('/\s+/', strtolower(preg_replace('/\b(mr|mrs|ms|miss|dr|prof|chief|engr|pastor|rev)\b\.?|&/i', ' ', (string) $u->name)))));
        $words = array_map(fn ($w) => preg_replace('/[^a-z0-9]/', '', $w), $words);
        $words = array_values(array_filter($words));
        if (! $words) {
            return 'user' . $u->id;
        }
        $last = end($words);

        return count($words) > 1 ? substr($words[0], 0, 1) . $last : $last;
    }

    /** Give $u a unique username if it has none. Returns the username (or null if usernames aren't available). */
    public static function ensure(User $u): ?string
    {
        if (! self::enabled()) {
            return null;
        }
        $current = DB::table('users')->where('id', $u->id)->value('username');
        if ($current) {
            return $current;
        }
        // A student's username is the admission number: wait until the student record is linked.
        if ($u->role === 'student' && Schema::hasTable('students') && ! DB::table('students')->where('user_id', $u->id)->exists()) {
            return null;
        }
        $base = substr(self::baseFor($u), 0, 40) ?: 'user' . $u->id;
        $name = $base;
        for ($i = 2; DB::table('users')->whereRaw('LOWER(username) = ?', [strtolower($name)])->where('id', '!=', $u->id)->exists(); $i++) {
            $name = $base . $i;
        }
        DB::table('users')->where('id', $u->id)->update(['username' => $name]);
        $u->setAttribute('username', $name);

        return $name;
    }

    /** Fill in every account that has no username yet. */
    public static function assignMissing(): int
    {
        if (! self::enabled()) {
            return 0;
        }
        $n = 0;
        User::whereNull('username')->orderBy('id')->chunkById(200, function ($users) use (&$n) {
            foreach ($users as $u) {
                if (self::ensure($u)) {
                    $n++;
                }
            }
        });

        return $n;
    }
}
