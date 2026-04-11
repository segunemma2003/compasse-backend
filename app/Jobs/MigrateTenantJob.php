<?php

namespace App\Jobs;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class MigrateTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 300;

    public function __construct(public readonly string $tenantId) {}

    public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId);

        if (!$tenant) {
            Log::warning('MigrateTenantJob: tenant not found', ['tenant_id' => $this->tenantId]);
            return;
        }

        try {
            tenancy()->initialize($tenant);

            Artisan::call('migrate', [
                '--force' => true,
                '--path'  => 'database/migrations/tenant',
            ]);

            tenancy()->end();

            Log::info('MigrateTenantJob: migrations ran successfully', ['tenant_id' => $this->tenantId]);

        } catch (\Throwable $e) {
            try { tenancy()->end(); } catch (\Throwable $ignored) {}

            Log::error('MigrateTenantJob failed', [
                'tenant_id' => $this->tenantId,
                'error'     => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
