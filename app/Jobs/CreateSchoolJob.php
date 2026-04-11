<?php

namespace App\Jobs;

use App\Models\School;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class CreateSchoolJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public readonly string $tenantId,
        public readonly array  $schoolData,
    ) {}

    public function handle(TenantService $tenantService): void
    {
        $tenant = Tenant::find($this->tenantId);

        if (!$tenant) {
            Log::error('CreateSchoolJob: tenant not found', ['tenant_id' => $this->tenantId]);
            return;
        }

        try {
            tenancy()->initialize($tenant);

            // One-school-per-tenant: skip silently if school already exists.
            if (DB::connection('tenant')->table('schools')->exists()) {
                Log::info('CreateSchoolJob: school already exists, skipping', [
                    'tenant_id' => $this->tenantId,
                ]);
                tenancy()->end();
                return;
            }

            $schoolName    = $this->schoolData['name'] ?? $tenant->name;
            // Prefer explicit admin_email → school contact email → auto-generated
            $adminEmail    = $this->schoolData['admin_email']
                ?? $this->schoolData['email']
                ?? $tenantService->generateAdminEmail($schoolName, $tenant->subdomain);
            $adminPassword = $this->schoolData['admin_password'] ?? $tenantService->generateDefaultPassword();
            $adminName     = $this->schoolData['admin_name'] ?? 'School Administrator';

            $school = School::create([
                'name'          => $schoolName,
                'code'          => $tenantService->generateSchoolCode($schoolName),
                'address'       => $this->schoolData['address'] ?? null,
                'phone'         => $this->schoolData['phone']   ?? null,
                'email'         => $this->schoolData['email']   ?? null,
                'website'       => $this->schoolData['website'] ?? null,
                'status'        => 'active',
                'academic_year' => date('Y') . '-' . (date('Y') + 1),
                'term'          => 'First Term',
            ]);

            // Only create an admin if the tenant DB has no admin-level user yet.
            $adminExists = User::whereIn('role', ['school_admin', 'admin'])->exists();
            if ($adminExists) {
                // Use the existing admin — don't reset their password here; use resendWelcome for that.
                $existing      = User::whereIn('role', ['school_admin', 'admin'])->orderBy('created_at')->first();
                $adminEmail    = $existing->email;
                $adminName     = $existing->name;
                $adminPassword = null;
            } else {
                User::create([
                    'name'              => $adminName,
                    'email'             => $adminEmail,
                    'password'          => Hash::make($adminPassword),
                    'role'              => 'school_admin',
                    'status'            => 'active',
                    'email_verified_at' => now(),
                ]);
            }

            $tenantService->seedAcademicData($school);
            $tenantService->enableDefaultModules($tenant);

            tenancy()->end();

            // Mirror to central DB.
            \App\Models\School::on('mysql')->updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'name'    => $school->name,
                    'code'    => $school->code,
                    'address' => $school->address,
                    'phone'   => $school->phone,
                    'email'   => $school->email,
                    'website' => $school->website ?? null,
                    'status'  => 'active',
                ]
            );

            // Send credentials only when we just created a new admin.
            if ($adminPassword !== null) {
                $tenantService->dispatchWelcomeEmail(
                    $adminEmail,
                    $adminPassword,
                    $adminName,
                    $school->name,
                    $tenant->subdomain,
                );
            }

            Log::info('CreateSchoolJob completed', [
                'tenant_id'   => $tenant->id,
                'school_name' => $school->name,
            ]);

        } catch (\Throwable $e) {
            try { tenancy()->end(); } catch (\Throwable $ignored) {}

            Log::error('CreateSchoolJob failed', [
                'tenant_id' => $this->tenantId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
