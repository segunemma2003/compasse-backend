<?php

use App\Models\SchedulerLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Helper: wrap a scheduled command with DB logging
function scheduledWithLog(string $command): \Illuminate\Console\Scheduling\Event
{
    $log = null;

    return Schedule::command($command)
        ->before(function () use ($command, &$log) {
            $log = SchedulerLog::create([
                'command'    => $command,
                'status'     => 'running',
                'started_at' => now(),
            ]);
        })
        ->onSuccess(function () use (&$log) {
            if ($log) {
                $log->update([
                    'status'      => 'success',
                    'finished_at' => now(),
                    'duration_ms' => $log->started_at->diffInMilliseconds(now()),
                ]);
            }
        })
        ->onFailure(function () use (&$log) {
            if ($log) {
                $log->update([
                    'status'      => 'failed',
                    'finished_at' => now(),
                    'duration_ms' => $log->started_at->diffInMilliseconds(now()),
                ]);
            }
        });
}

// Send subscription expiry reminders daily at 8 AM
scheduledWithLog('subscriptions:send-reminders')->dailyAt('08:00');
