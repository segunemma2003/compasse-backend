<?php

namespace App\Console\Commands;

use App\Models\OnlinePaymentIntent;
use App\Models\Tenant;
use App\Services\OnlineFeePaymentService;
use App\Services\PayHubService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for online fee payments: re-checks every school's pending
 * PayHub payments and records the ones that went through. Covers a payer
 * who paid but never came back to a signed-in Compasse page and a webhook
 * that never arrived — otherwise the fee stayed unpaid in Compasse.
 */
class ReconcileOnlinePayments extends Command
{
    protected $signature = 'payments:reconcile-online';

    protected $description = 'Confirm pending online (PayHub) fee payments for every school';

    public function handle(PayHubService $payhub): int
    {
        if (! $payhub->isConfigured()) {
            return self::SUCCESS;
        }

        foreach (Tenant::where('status', 'active')->get() as $tenant) {
            try {
                tenancy()->initialize($tenant);

                if (OnlinePaymentIntent::where('status', 'pending')->exists()) {
                    $counts = app(OnlineFeePaymentService::class)->reconcilePending();
                    if ($counts['success'] || $counts['failed'] || $counts['errors']) {
                        $this->info("{$tenant->subdomain}: " . json_encode($counts));
                        Log::info('Online payments reconciled', ['tenant' => $tenant->subdomain] + $counts);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Online payment reconcile failed for tenant', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
            } finally {
                tenancy()->end();
            }
        }

        return self::SUCCESS;
    }
}
