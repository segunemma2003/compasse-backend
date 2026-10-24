<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\RecurringInvoices;
use Illuminate\Console\Command;

/** Daily: each school's recurring billing plans that are due (crèche, lunch, transport…). */
class RunRecurringInvoices extends Command
{
    protected $signature = 'invoices:run-recurring';

    protected $description = 'Create and email invoices for recurring billing plans that are due';

    public function handle(): int
    {
        $total = 0;
        foreach (Tenant::where('status', 'active')->get() as $tenant) {
            try {
                tenancy()->initialize($tenant);
                $total += RecurringInvoices::runDue()['invoices'];
            } catch (\Throwable $e) {
                report($e);
            } finally {
                tenancy()->end();
            }
        }
        $this->info("Created {$total} recurring invoice(s).");

        return self::SUCCESS;
    }
}
