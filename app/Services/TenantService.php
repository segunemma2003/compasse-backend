<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Exception;

class TenantService
{
    /**
     * Create a new tenant with database using stancl/tenancy.
     *
     * Returns an array:
     *   ['tenant' => Tenant, 'admin_credentials' => ['email' => ..., 'password' => ..., 'role' => ...]]
     *
     * The plain-text password is returned only in this response and is NEVER stored
     * on the model or persisted anywhere in plain text.
     *
     * Only super admin can call this.
     */
    public function createTenant(array $data): array
    {
        $tenantId    = Str::uuid()->toString();
        $schoolName  = $data['school']['name'] ?? $data['name'];
        $databaseName = now()->format('YmdHis') . '_' . Str::slug($schoolName);

        $adminCredentials = null;

        // Wrap the central-DB writes in a transaction so they are rolled back if
        // anything fails after the tenant record is created.
        DB::connection('mysql')->beginTransaction();

        try {
            $tenant = Tenant::create([
                'id'                => $tenantId,
                'name'              => $data['name'],
                'domain'            => $data['domain'] ?? null,
                'subdomain'         => $data['subdomain'] ?? Str::slug($data['name']),
                'database_name'     => $databaseName,
                'database_host'     => config('database.connections.mysql.host'),
                'database_port'     => config('database.connections.mysql.port'),
                'database_username' => config('database.connections.mysql.username'),
                'database_password' => config('database.connections.mysql.password'),
                'status'            => 'active',
            ]);

            if (isset($data['school'])) {
                // Initialize the tenant database (creates DB, runs migrations, seeds).
                $this->createTenantDatabase($tenant);

                // Switch context so subsequent Eloquent calls hit the tenant DB.
                tenancy()->initialize($tenant);

                $schoolData = $data['school'];

                $school = School::create([
                    'name'         => $schoolData['name'],
                    'code'         => $this->generateSchoolCode($schoolData['name']),
                    'address'      => $schoolData['address'] ?? null,
                    'phone'        => $schoolData['phone'] ?? null,
                    'email'        => $schoolData['email'] ?? null,
                    'website'      => $schoolData['website'] ?? null,
                    'status'       => 'active',
                    'academic_year' => date('Y') . '-' . (date('Y') + 1),
                    'term'         => 'First Term',
                ]);

                $adminEmail    = $schoolData['admin_email'] ?? $this->generateAdminEmail($schoolData['name'], $tenant->subdomain);
                $adminPassword = $schoolData['admin_password'] ?? $this->generateDefaultPassword();

                User::create([
                    'name'              => $schoolData['admin_name'] ?? 'School Administrator',
                    'email'             => $adminEmail,
                    'password'          => Hash::make($adminPassword),
                    'role'              => 'school_admin',
                    'status'            => 'active',
                    'email_verified_at' => now(),
                ]);

                $this->seedAcademicData($school);
                $this->enableDefaultModules($tenant);

                tenancy()->end();

                // Mirror the school in the central DB for super-admin queries.
                \App\Models\School::on('mysql')->create([
                    'tenant_id' => $tenant->id,
                    'name'      => $schoolData['name'],
                    'code'      => $school->code,
                    'address'   => $schoolData['address'] ?? null,
                    'phone'     => $schoolData['phone'] ?? null,
                    'email'     => $schoolData['email'] ?? null,
                    'website'   => $schoolData['website'] ?? null,
                    'status'    => 'active',
                ]);

                // Credentials returned to the caller but never stored in plain text.
                $adminCredentials = [
                    'email'    => $adminEmail,
                    'password' => $adminPassword,
                    'role'     => 'school_admin',
                ];
            }

            DB::connection('mysql')->commit();

            return [
                'tenant'            => $tenant,
                'admin_credentials' => $adminCredentials,
            ];

        } catch (Exception $e) {
            DB::connection('mysql')->rollBack();

            // Make sure tenancy is ended so the connection is released.
            try { tenancy()->end(); } catch (Exception $ignored) {}

            // Drop the orphaned tenant database if it was already created.
            if (isset($tenant)) {
                try {
                    $this->dropDatabase($tenant->database_name);
                    $tenant->delete();
                } catch (Exception $cleanupError) {
                    Log::error('Failed to clean up tenant after creation error', [
                        'tenant_id' => $tenant->id ?? null,
                        'error'     => $cleanupError->getMessage(),
                    ]);
                }
            }

            Log::error('Tenant creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Create and migrate the tenant database synchronously.
     */
    public function createTenantDatabase(Tenant $tenant): void
    {
        if ($tenant->database_name) {
            $tenant->setInternal('db_name', $tenant->database_name);
            if ($tenant->database_host)     { $tenant->setInternal('db_host', $tenant->database_host); }
            if ($tenant->database_port)     { $tenant->setInternal('db_port', $tenant->database_port); }
            if ($tenant->database_username) { $tenant->setInternal('db_username', $tenant->database_username); }
            if ($tenant->database_password) { $tenant->setInternal('db_password', $tenant->database_password); }
            $tenant->save();
        }

        $databaseManager = app(DatabaseManager::class);
        (new CreateDatabase($tenant))->handle($databaseManager);

        $databaseName = $tenant->database_name;
        if ($databaseName) {
            Config::set('database.connections.tenant', [
                'driver'    => 'mysql',
                'host'      => $tenant->database_host     ?? config('database.connections.mysql.host'),
                'port'      => $tenant->database_port     ?? config('database.connections.mysql.port'),
                'database'  => $databaseName,
                'username'  => $tenant->database_username ?? config('database.connections.mysql.username'),
                'password'  => $tenant->database_password ?? config('database.connections.mysql.password'),
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => '',
                'strict'    => true,
                'engine'    => null,
            ]);
            DB::purge('tenant');
        }

        $exitCode = Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path'     => 'database/migrations/tenant',
            '--force'    => true,
        ]);

        if ($exitCode !== 0) {
            throw new \Exception("Tenant migrations failed (exit code: {$exitCode})");
        }

        tenancy()->initialize($tenant);

        try {
            Artisan::call('db:seed', [
                '--class'    => 'TenantSeeder',
                '--database' => 'tenant',
                '--force'    => true,
            ]);
        } catch (\Exception $e) {
            Log::warning('Tenant seeding failed (non-fatal): ' . $e->getMessage(), [
                'tenant_id' => $tenant->id,
            ]);
        } finally {
            tenancy()->end();
        }
    }

    /**
     * Switch to tenant database using stancl/tenancy.
     */
    public function switchToTenant(Tenant $tenant): void
    {
        tenancy()->initialize($tenant);
    }

    /**
     * Get tenant by subdomain (active only).
     */
    public function getTenantBySubdomain(string $subdomain): ?Tenant
    {
        return Tenant::where('subdomain', $subdomain)
                     ->where('status', 'active')
                     ->first();
    }

    /**
     * Get tenant by custom domain (active only).
     */
    public function getTenantByDomain(string $domain): ?Tenant
    {
        return Tenant::where('domain', $domain)
                     ->where('status', 'active')
                     ->first();
    }

    /**
     * Delete tenant record and drop its database.
     */
    public function deleteTenant(Tenant $tenant): bool
    {
        try {
            $databaseName = $tenant->database_name;
            $tenant->delete();

            if ($databaseName) {
                $this->dropDatabase($databaseName);
            }

            return true;

        } catch (Exception $e) {
            Log::error('Failed to delete tenant', [
                'tenant_id' => $tenant->id,
                'error'     => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Drop a database by name.
     * The name is strictly validated against a safe pattern before use.
     */
    protected function dropDatabase(string $databaseName): void
    {
        // Allow only safe characters to prevent SQL injection.
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $databaseName)) {
            Log::error('Refusing to drop database with unsafe name', ['name' => $databaseName]);
            return;
        }

        try {
            DB::statement("DROP DATABASE IF EXISTS `{$databaseName}`");
        } catch (Exception $e) {
            Log::warning('Failed to drop tenant database', [
                'database_name' => $databaseName,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get tenant statistics.
     * Always ends tenancy context before returning.
     */
    public function getTenantStats(Tenant $tenant): array
    {
        $this->switchToTenant($tenant);

        try {
            $stats = [
                'schools'     => $this->tableCount('schools'),
                'users'       => $this->tableCount('users'),
                'students'    => $this->tableCount('students'),
                'teachers'    => $this->tableCount('teachers'),
                'classes'     => $this->tableCount('classes'),
                'subjects'    => $this->tableCount('subjects'),
                'departments' => $this->tableCount('departments'),
                'modules'     => $tenant->getSetting('modules', []),
            ];
        } finally {
            // Always restore central DB context.
            tenancy()->end();
        }

        return [
            'tenant' => [
                'id'                => $tenant->id,
                'name'              => $tenant->name,
                'status'            => $tenant->status,
                'subscription_plan' => $tenant->getSubscriptionPlan(),
                'database'          => $tenant->database_name,
            ],
            'stats' => $stats,
        ];
    }

    /**
     * Safely count records in a tenant table.
     */
    protected function tableCount(string $table): int
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable($table)) {
            return 0;
        }

        try {
            return DB::table($table)->count();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Seed initial academic year and terms for a newly created school.
     */
    protected function seedAcademicData(School $school): void
    {
        try {
            $currentYear = (int) date('Y');
            $nextYear    = $currentYear + 1;

            $academicYear = \App\Models\AcademicYear::create([
                'school_id'  => $school->id,
                'name'       => "{$currentYear}-{$nextYear}",
                'start_date' => "{$currentYear}-09-01",
                'end_date'   => "{$nextYear}-07-31",
                'status'     => 'active',
                'is_current' => true,
            ]);

            $terms = [
                ['name' => '1st Term', 'start_date' => "{$currentYear}-09-01", 'end_date' => "{$currentYear}-12-15"],
                ['name' => '2nd Term', 'start_date' => "{$nextYear}-01-05",    'end_date' => "{$nextYear}-04-10"],
                ['name' => '3rd Term', 'start_date' => "{$nextYear}-04-20",    'end_date' => "{$nextYear}-07-31"],
            ];

            foreach ($terms as $index => $termData) {
                \App\Models\Term::create([
                    'school_id'        => $school->id,
                    'academic_year_id' => $academicYear->id,
                    'name'             => $termData['name'],
                    'start_date'       => $termData['start_date'],
                    'end_date'         => $termData['end_date'],
                    'status'           => 'active',
                    'is_current'       => $index === 0,
                ]);
            }

        } catch (Exception $e) {
            Log::error('Failed to seed academic data', [
                'school_id' => $school->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Enable all default modules for a new tenant.
     */
    protected function enableDefaultModules(Tenant $tenant): void
    {
        try {
            $defaultModules = [
                'academic_management' => ['name' => 'Academic Management',    'enabled' => true, 'features' => ['academic_years', 'terms', 'classes', 'subjects']],
                'student_management'  => ['name' => 'Student Management',     'enabled' => true, 'features' => ['students', 'enrollment', 'credentials']],
                'teacher_management'  => ['name' => 'Teacher Management',     'enabled' => true, 'features' => ['teachers', 'assignments', 'schedules']],
                'staff_management'    => ['name' => 'Staff Management',       'enabled' => true, 'features' => ['staff', 'payroll', 'attendance']],
                'exam_management'     => ['name' => 'Exam Management',        'enabled' => true, 'features' => ['exams', 'results', 'report_cards']],
                'cbt'                 => ['name' => 'Computer Based Testing', 'enabled' => true, 'features' => ['online_exams', 'assessments', 'quizzes']],
                'library'             => ['name' => 'Library Management',     'enabled' => true, 'features' => ['books', 'borrowing', 'fines']],
                'finance'             => ['name' => 'Finance & Accounting',   'enabled' => true, 'features' => ['fees', 'payments', 'invoices', 'expenses']],
                'communication'       => ['name' => 'Communication',          'enabled' => true, 'features' => ['announcements', 'messages', 'notifications']],
            ];

            $tenant->data = array_merge($tenant->data ?? [], ['modules' => $defaultModules]);
            $tenant->save();

        } catch (Exception $e) {
            Log::error('Failed to enable default modules', [
                'tenant_id' => $tenant->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate admin email using the configured main domain.
     */
    protected function generateAdminEmail(string $schoolName, string $subdomain): string
    {
        $mainDomain = config('tenant.subdomain.main_domain', 'samschool.com');
        return "admin@{$subdomain}.{$mainDomain}";
    }

    /**
     * Generate a unique school code from the school name.
     */
    protected function generateSchoolCode(string $schoolName): string
    {
        $base = Str::upper(Str::slug($schoolName, '_'));
        return $base . '_' . Str::upper(Str::random(4));
    }

    /**
     * Generate a secure random password with mixed case, digits, and symbols.
     */
    protected function generateDefaultPassword(): string
    {
        // Str::password() ships with Laravel 9.x+.
        // Arguments: length, letters, numbers, symbols, spaces.
        return Str::password(14, true, true, true, false);
    }
}
