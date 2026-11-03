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
            $at = \App\Http\Controllers\StaffAttendanceController::local()->format('g:ia, D j M');
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
            if (! $phone && Schema::hasTable('staff')) {
                $phone = DB::table('staff')->where('user_id', $user->id)->value('phone');
            }
            if ($flag('notify_clock_sms') && $phone && SchoolIntegrationSettings::forSchool($school?->id)->hasSms()) {
                $sender = SchoolIntegrationSettings::forSchool($school?->id)->smsSenderId ?: substr(preg_replace('/[^A-Za-z0-9 ]/', '', $schoolName), 0, 11);
                SendSMSJob::dispatch(to: $phone, message: $text, senderId: $sender, schoolId: $school?->id ? (string) $school->id : null)->onQueue('sms');
            }
        } catch (\Throwable $e) {
            report($e); // a notification must never block clocking in
        }
    }

    /** A student scanned the school's QR code: tell their parents (same switches as staff). */
    public static function sendStudent($student, string $action, string $time): void
    {
        try {
            $school = School::first();
            $flag = fn (string $k) => (string) (DB::table('settings')->where('key', $k)->value('value') ?? '1') !== '0';
            $schoolName = $school?->name ?? 'School';
            $first = trim((string) $student->first_name) ?: 'Your child';
            $at = \Carbon\Carbon::createFromFormat('H:i', $time)->format('g:ia') . ', ' . \App\Http\Controllers\StaffAttendanceController::local()->format('D j M');
            $text = $action === 'clock_in'
                ? "{$first} arrived at {$schoolName} at {$at}."
                : "{$first} left {$schoolName} at {$at}.";
            $sms = SchoolIntegrationSettings::forSchool($school?->id);

            foreach ($student->guardians()->get() as $g) {
                if ($flag('notify_clock_email') && EmailAddress::isReal($g->email ?? null)) {
                    SendEmailJob::dispatch(
                        to: $g->email,
                        subject: ($action === 'clock_in' ? 'Arrived' : 'Left') . " — {$schoolName}",
                        body: $text,
                        schoolId: $school?->id ? (string) $school->id : null,
                        type: 'clock_in_out',
                    )->onQueue('emails');
                }
                if ($flag('notify_clock_sms') && ! empty($g->phone) && $sms->hasSms()) {
                    $sender = $sms->smsSenderId ?: substr(preg_replace('/[^A-Za-z0-9 ]/', '', $schoolName), 0, 11);
                    SendSMSJob::dispatch(to: $g->phone, message: $text, senderId: $sender, schoolId: $school?->id ? (string) $school->id : null)->onQueue('sms');
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
