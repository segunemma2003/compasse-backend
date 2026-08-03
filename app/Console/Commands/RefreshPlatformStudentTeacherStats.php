<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RefreshPlatformStudentTeacherStats extends Command
{
    protected $signature = 'platform:refresh-student-teacher-stats';

    protected $description = 'Sum students/teachers across every tenant database and cache the totals for the super-admin dashboard';

    /**
     * Cache TTL — longer than the schedule interval so a delayed run never
     * leaves the super-admin dashboard reading a fully expired value.
     */
    public const CACHE_KEY = 'platform:students_teachers';
    private const CACHE_TTL_HOURS = 2;

    public function handle(): int
    {
        $totalStudents = 0;
        $totalTeachers = 0;

        foreach (Tenant::all() as $tenant) {
            try {
                tenancy()->initialize($tenant);

                $counts = DB::selectOne('
                    SELECT
                        (SELECT COUNT(*) FROM students) AS students,
                        (SELECT COUNT(*) FROM teachers) AS teachers
                ');

                $totalStudents += (int) ($counts->students ?? 0);
                $totalTeachers += (int) ($counts->teachers ?? 0);

                tenancy()->end();
            } catch (\Throwable $e) {
                try { tenancy()->end(); } catch (\Throwable $ignored) {}
                Log::warning('platform:refresh-student-teacher-stats: failed to count students/teachers for tenant', [
                    'tenant_id' => $tenant->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        Cache::put(self::CACHE_KEY, [
            'total_students' => $totalStudents,
            'total_teachers' => $totalTeachers,
        ], now()->addHours(self::CACHE_TTL_HOURS));

        $this->info("Cached platform totals: {$totalStudents} students, {$totalTeachers} teachers.");

        return self::SUCCESS;
    }
}
