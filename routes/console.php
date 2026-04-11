<?php

use App\Models\SchedulerLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Send subscription expiry reminders daily at 8 AM
$reminderLog = null;
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
