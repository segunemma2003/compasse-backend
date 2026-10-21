<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Onboarding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/** Daily: each school's timed onboarding emails (meet your teacher, first-day reminder, first week). */
class SendOnboardingEmails extends Command
{
    protected $signature = 'onboarding:send-due';

    protected $description = 'Send onboarding emails that are due (days before / after resumption)';

    public function handle(): int
    {
        $total = 0;
        foreach (Tenant::where('status', 'active')->get() as $tenant) {
            try {
                tenancy()->initialize($tenant);
                if (Schema::hasTable('onboarding_steps') && Schema::hasTable('applicants')) {
                    $total += Onboarding::sendDue();
                }
            } catch (\Throwable $e) {
                report($e);
            } finally {
                tenancy()->end();
            }
        }
        $this->info("Sent {$total} onboarding email(s).");

        return self::SUCCESS;
    }
}
