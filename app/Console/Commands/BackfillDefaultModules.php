<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackfillDefaultModules extends Command
{
    protected $signature = 'modules:backfill-defaults {--dry-run : List affected schools without writing changes}';

    protected $description = 'One-time fix: grant the default module set to schools provisioned before '
        . 'TenantService::enableDefaultModules() was corrected to write settings.modules '
        . '(previously it wrote to Tenant->data, which SubscriptionService never reads, so every '
        . 'module-gated feature silently 403\'d for these schools regardless of subscription state)';

    private const DEFAULT_MODULES = [
        'academic_management', 'attendance_management', 'cbt', 'email_integration',
        'event_management', 'fee_management', 'health_management', 'hostel_management',
        'inventory_management', 'livestream', 'sms_integration', 'student_management',
        'teacher_management', 'transport_management', 'staff_management', 'exam_management',
        'library', 'finance', 'communication',
    ];

    public function handle(SubscriptionService $subscriptionService): int
    {
        $dryRun  = (bool) $this->option('dry-run');
        $fixed   = 0;
        $skipped = 0;

        foreach (Tenant::all() as $tenant) {
            try {
                tenancy()->initialize($tenant);

                $school = School::first();
                if (! $school) {
                    tenancy()->end();
                    continue;
                }

                $settings = $school->settings ?? [];

                // Already has an explicit module list (e.g. manually configured, or an active
                // subscription with its own features) — don't clobber it.
                if (! empty($settings['modules'])) {
                    $skipped++;
                    tenancy()->end();
                    continue;
                }

                $this->line("Fixing school #{$school->id} ({$school->name}) on tenant {$tenant->id}" . ($dryRun ? ' [dry-run]' : ''));

                if (! $dryRun) {
                    $school->settings = array_merge($settings, ['modules' => self::DEFAULT_MODULES]);
                    $school->save();
                    $subscriptionService->invalidateCache($school);
                }

                $fixed++;
                tenancy()->end();
            } catch (\Throwable $e) {
                try {
                    tenancy()->end();
                } catch (\Throwable) {
                }
                Log::warning('modules:backfill-defaults tenant failed', [
                    'tenant_id' => $tenant->id,
                    'error'     => $e->getMessage(),
                ]);
                $this->warn("  Skipped tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . "Done. Fixed: {$fixed}, already configured: {$skipped}.");

        return self::SUCCESS;
    }
}
