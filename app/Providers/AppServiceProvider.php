<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind custom subdomain resolver for stancl/tenancy
        // Must be in register() to be available when middleware is resolved
        $this->app->bind(
            \Stancl\Tenancy\Resolvers\RequestDataTenantResolver::class,
            \App\Resolvers\SubdomainTenantResolver::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Copy deleted payments, fees, classes… into the recycle bin so they can be restored.
        \App\Support\RecycleBin::register();

        // Configure stancl/tenancy to use X-Subdomain header for tenant resolution
        \Stancl\Tenancy\Middleware\InitializeTenancyByRequestData::$header = 'X-Subdomain';
        
        // Ensure Sanctum uses the tenant-aware PersonalAccessToken model
        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);

        $this->invalidateCachesOnChange();
    }

    /**
     * Cached lists (teachers, exams, assignments, attendance, livestreams)
     * and the dashboard counts used to be cleared only by the particular
     * controller actions that remembered to — bulk uploads, bulk actions,
     * and edits from other screens left them stale for 5–10 minutes (the
     * teachers page stayed empty after a successful upload; an edited or
     * deleted exam stayed on the exam list). Clearing them whenever the
     * underlying record is saved, deleted or restored covers every path.
     */
    private function invalidateCachesOnChange(): void
    {
        $groups = [
            \App\Models\Teacher::class     => ['teachers:*', 'teacher:*', 'attendance:*'],
            \App\Models\Student::class     => ['attendance:*'],
            \App\Models\Staff::class       => [],
            \App\Models\Guardian::class    => [],
            \App\Models\Exam::class        => ['exams:*', 'exam:*'],
            \App\Models\Result::class      => ['exams:*', 'exam:*'],
            \App\Models\CAScore::class     => [],
            \App\Models\Assignment::class  => ['assignments:*', 'assignment:*'],
            \App\Models\Attendance::class  => ['attendance:*'],
            \App\Models\Livestream::class  => ['livestreams:*'],
            \App\Models\ClassModel::class  => ['teachers:*', 'attendance:*', 'exams:*'],
            \App\Models\Arm::class         => ['attendance:*'],
            \App\Models\Subject::class     => ['teachers:*', 'exams:*'],
            \App\Models\Department::class  => ['teachers:*'],
            \App\Modules\Financial\Models\Fee::class     => [],
            \App\Modules\Financial\Models\Payment::class => [],
        ];

        foreach ($groups as $model => $patterns) {
            $clear = function () use ($patterns) {
                try {
                    $cache = app(\App\Services\CacheService::class);
                    foreach ($patterns as $pattern) {
                        $cache->invalidateByPattern($pattern);
                    }
                    \App\Http\Controllers\DashboardController::bustCache();
                } catch (\Throwable $e) {
                    // A cache hiccup must never fail the save itself.
                }
            };

            $model::saved($clear);
            $model::deleted($clear);
            if (method_exists($model, 'restored')) {
                $model::restored($clear);
            }
        }
    }
}
