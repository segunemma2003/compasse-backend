<?php

namespace App\Support;

use App\Jobs\SendEmailJob;
use App\Jobs\SendSMSJob;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff clock-in / clock-out confirmation by SMS and email (Settings → Integrations can
 * switch either off: settings keys notify_clock_sms / notify_clock_email, on by default).
 * SMS goes only when the school's SMS provider is set up and the person has a phone.
 */
class ClockNotify
{
    public static function send($user, $attendance, string $action): void
    {
        try {
            $school = School::first();
            $flag = fn (string $k) => (string) (DB::table('settings')->where('key', $k)->value('value') ?? '1') !== '0';
            $at = now()->format('g:ia, D j M');
            $late = $action === 'clock_in' && method_exists($attendance, 'getLateMinutes') && $attendance->getLateMinutes() > 0
                ? ' (' . $attendance->getLateMinutes() . ' min late)' : '';
            $what = $action === 'clock_in' ? 'clocked in' : 'clocked out';
            $hours = $action === 'clock_out' && $attendance->total_hours ? ' Hours today: ' . round((float) $attendance->total_hours, 1) . '.' : '';
            $name = trim(explode(' ', (string) $user->name)[0] ?? '') ?: 'there';
            $schoolName = $school?->name ?? 'School';
            $text = "Hi {$name}, you {$what} at {$schoolName} at {$at}{$late}.{$hours}";

            if ($flag('notify_clock_email') && EmailAddress::isReal($user->email ?? null)) {
                SendEmailJob::dispatch(
                    to: $user->email,
                    subject: ucfirst($what) . " — {$schoolName}",
                    body: $text . "\n\nIf this wasn't you, please tell the school office.",
                    schoolId: $school?->id ? (string) $school->id : null,
                    type: 'clock_in_out',
                )->onQueue('emails');
            }

            $phone = $user->phone ?? null;
            if (! $phone && Schema::hasTable('teachers')) {
                $phone = DB::table('teachers')->where('user_id', $user->id)->value('phone');
            }
            if ($flag('notify_clock_sms') && $phone && SchoolIntegrationSettings::forSchool($school?->id)->hasSms()) {
                $sender = SchoolIntegrationSettings::forSchool($school?->id)->smsSenderId ?: substr(preg_replace('/[^A-Za-z0-9 ]/', '', $schoolName), 0, 11);
                SendSMSJob::dispatch(to: $phone, message: $text, senderId: $sender, schoolId: $school?->id ? (string) $school->id : null)->onQueue('sms');
            }
        } catch (\Throwable $e) {
            report($e); // a notification must never block clocking in
        }
    }
}
