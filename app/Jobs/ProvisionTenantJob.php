<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\School;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProvisionTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * No retries — database provisioning is not idempotent.
     * A failed attempt is cleaned up and the tenant marked as failed.
     */
    public int $tries = 1;

    /**
     * Allow up to 10 minutes for migrations on slow servers.
     */
    public int $timeout = 600;

    public function __construct(
        public readonly string $tenantId,
        public readonly array  $schoolData,
    ) {}

    public function handle(TenantService $tenantService): void
    {
        $tenant = Tenant::find($this->tenantId);

        if (!$tenant) {
            Log::error('ProvisionTenantJob: tenant not found', ['tenant_id' => $this->tenantId]);
            return;
        }

        try {
            // ── 1. Create & migrate the tenant database ───────────────────────
            $tenantService->createTenantDatabase($tenant);

            // ── 2. Switch context to tenant DB ───────────────────────────────
            tenancy()->initialize($tenant);

            $schoolData    = $this->schoolData;
            $adminEmail    = $schoolData['admin_email']    ?? $tenantService->generateAdminEmail($schoolData['name'], $tenant->subdomain);
            $adminPassword = $schoolData['admin_password'] ?? $tenantService->generateDefaultPassword();

            // ── 3. Create school record in tenant DB ──────────────────────────
            $school = School::create([
                'name'          => $schoolData['name'],
                'code'          => $tenantService->generateSchoolCode($schoolData['name']),
                'address'       => $schoolData['address']  ?? null,
                'phone'         => $schoolData['phone']    ?? null,
                'email'         => $schoolData['email']    ?? null,
                'website'       => $schoolData['website']  ?? null,
                'status'        => 'active',
                'academic_year' => date('Y') . '-' . (date('Y') + 1),
                'term'          => 'First Term',
            ]);

            // ── 4. Create school admin user in tenant DB ──────────────────────
            User::create([
                'name'              => $schoolData['admin_name']  ?? 'School Administrator',
                'email'             => $adminEmail,
                'password'          => Hash::make($adminPassword),
                'role'              => 'school_admin',
                'status'            => 'active',
                'email_verified_at' => now(),
            ]);

            // ── 5. Seed default academic data ─────────────────────────────────
            $tenantService->seedAcademicData($school);

            // ── 6. Enable default modules ─────────────────────────────────────
            $tenantService->enableDefaultModules($tenant);

            tenancy()->end();

            // ── 7. Mirror school in central DB ────────────────────────────────
            \App\Models\School::on('mysql')->updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'name'    => $schoolData['name'],
                    'code'    => $school->code,
                    'address' => $schoolData['address'] ?? null,
                    'phone'   => $schoolData['phone']   ?? null,
                    'email'   => $schoolData['email']   ?? null,
                    'website' => $schoolData['website'] ?? null,
                    'status'  => 'active',
                ]
            );

            // ── 8. Mark tenant as active ──────────────────────────────────────
            $tenant->update(['status' => 'active']);

            // ── 9. Send welcome email (already queued internally) ─────────────
            $tenantService->dispatchWelcomeEmail(
                $adminEmail,
                $adminPassword,
                $schoolData['admin_name'] ?? 'School Administrator',
                $schoolData['name'],
                $tenant->subdomain,
            );

            Log::info('Tenant provisioned successfully', [
                'tenant_id' => $tenant->id,
                'subdomain' => $tenant->subdomain,
            ]);

        } catch (\Throwable $e) {
            try { tenancy()->end(); } catch (\Throwable $ignored) {}

            Log::error('ProvisionTenantJob failed — cleaning up', [
                'tenant_id' => $this->tenantId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            // Drop the orphaned database if it was created.
            if ($tenant->database_name) {
                $tenantService->dropDatabaseSafe($tenant->database_name);
            }

            // Mark tenant as failed so the super admin can see it and retry.
            $tenant->update(['status' => 'failed']);

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProvisionTenantJob permanently failed', [
            'tenant_id' => $this->tenantId,
            'error'     => $e->getMessage(),
        ]);

        Tenant::where('id', $this->tenantId)->update(['status' => 'failed']);
    }
}
