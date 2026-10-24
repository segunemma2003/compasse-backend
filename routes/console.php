<?php

use App\Models\SchedulerLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep the super-admin dashboard's cross-tenant student/teacher totals warm
// so the request path never has to hop into every tenant's database live.
Schedule::command('platform:refresh-student-teacher-stats')->everyFifteenMinutes();

// Flip subscriptions whose end_date has passed from active -> expired. Access
// control never depended on this (Subscription::isActive() already checks
// end_date), but the raw status column is what the super-admin subscriptions
// list and its ?status= filter read, and nothing else ever kept it current.
Schedule::command('subscriptions:expire')->hourly();

// Confirm online fee payments whose payer never returned to the app and
// whose provider webhook didn't arrive.
Schedule::command('payments:reconcile-online')->everyFiveMinutes()->withoutOverlapping();

// Scheduled event reminders that are due.
Schedule::command('events:send-reminders')->everyMinute()->withoutOverlapping();

// Send subscription expiry reminders daily at 8 AM
$reminderLog = null;
Schedule::command('timetable:send-period-reminders')->everyMinute();

// Onboarding emails timed around resumption (meet your teacher, first-day reminder, first week).
Schedule::command('onboarding:send-due')->dailyAt('07:00')->withoutOverlapping();

// Recurring billing plans (crèche, lunch…): create and email the invoices that are due today.
Schedule::command('invoices:run-recurring')->dailyAt('06:30')->withoutOverlapping();

Schedule::command('subscriptions:send-reminders')
    ->dailyAt('08:00')
    ->before(function () use (&$reminderLog) {
        $reminderLog = SchedulerLog::create([
            'command'    => 'subscriptions:send-reminders',
            'status'     => 'running',
            'started_at' => now(),
        ]);
    })
    ->onSuccess(function () use (&$reminderLog) {
        $reminderLog?->update([
            'status'      => 'success',
            'finished_at' => now(),
            'duration_ms' => $reminderLog->started_at->diffInMilliseconds(now()),
        ]);
    })
    ->onFailure(function () use (&$reminderLog) {
        $reminderLog?->update([
            'status'      => 'failed',
            'finished_at' => now(),
            'duration_ms' => $reminderLog->started_at->diffInMilliseconds(now()),
        ]);
    });
