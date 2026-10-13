<?php

namespace App\Console\Commands;

use App\Models\EventReminder;
use App\Models\Tenant;
use App\Services\EventReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Sends scheduled event reminders that are due, for every school. */
class SendEventReminders extends Command
{
    protected $signature = 'events:send-reminders';

    protected $description = 'Send scheduled event reminders that are due';

    public function handle(): int
    {
        foreach (Tenant::where('status', 'active')->get() as $tenant) {
            try {
                tenancy()->initialize($tenant);
                if (! Schema::hasTable('event_reminders')) {
                    continue;
                }
                $due = EventReminder::where('status', 'pending')->whereNotNull('send_at')->where('send_at', '<=', now())->get();
                foreach ($due as $reminder) {
                    $count = app(EventReminderService::class)->send($reminder);
                    $this->info("{$tenant->subdomain}: reminder #{$reminder->id} sent to {$count}");
                }
            } catch (\Throwable $e) {
                Log::warning('Event reminders failed for tenant', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
            } finally {
                tenancy()->end();
            }
        }

        return self::SUCCESS;
    }
}
