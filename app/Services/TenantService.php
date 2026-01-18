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
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;
use Stancl\Tenancy\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Exception;

class TenantService
{
    /**
     * Create a new tenant with database using stancl/tenancy
     * Only super admin can create tenants
     */
    public function createTenant(array $data): Tenant
    {
        try {
            // Generate unique ID for tenant
            $tenantId = Str::uuid()->toString();

            // Generate custom database name using timestamp + school name slug
            $schoolName = $data['school']['name'] ?? $data['name'];
            $databaseName = now()->format('YmdHis') . '_' . Str::slug($schoolName);

            // Tenant data for central database
            // Set custom database_name so stancl uses it instead of generating from prefix + tenant_id
            $tenantData = [
                'id' => $tenantId,
                'name' => $data['name'],
                'domain' => $data['domain'] ?? null,
                'subdomain' => $data['subdomain'] ?? Str::slug($data['name']),
                'database_name' => $databaseName,
                'database_host' => config('database.connections.mysql.host'),
                'database_port' => config('database.connections.mysql.port'),
                'database_username' => config('database.connections.mysql.username'),
                'database_password' => config('database.connections.mysql.password'),
                'status' => 'active',
            ];

            // Create tenant record in central DB
            // Stancl/tenancy will automatically (via TenantCreated event):
            // 1. Create the database (using database_name)
            // 2. Create MySQL user (if using PermissionControlledMySQLDatabaseManager)
            // 3. Run migrations (from database/migrations/tenant)
            // 4. Seed the database (using TenantSeeder from config/tenancy.php)
            $tenant = Tenant::create($tenantData);

            // Store school data if provided
            $adminEmail = null;
            if (isset($data['school'])) {
                // Initialize tenancy to work in tenant DB
                tenancy()->initialize($tenant);

            $schoolData = $data['school'];

                // Create school in tenant database
                $school = School::create([
                    'name' => $schoolData['name'],
                    'code' => $this->generateSchoolCode($schoolData['name']),
                    'address' => $schoolData['address'] ?? null,
                    'phone' => $schoolData['phone'] ?? null,
                    'email' => $schoolData['email'] ?? null,
                    'website' => $schoolData['website'] ?? null,
                    'status' => 'active',
                    'academic_year' => date('Y') . '-' . (date('Y') + 1),
                    'term' => 'First Term',
                ]);

                // Generate admin email
                $adminEmail = $schoolData['admin_email'] ?? $this->generateAdminEmail($schoolData['name'], $tenant->subdomain);

                // Create admin user in tenant database with default password
                $adminUser = User::create([
                    'name' => $schoolData['admin_name'] ?? 'School Administrator',
                    'email' => $adminEmail,
                    'password' => Hash::make('Password@12345'),
                    'role' => 'school_admin',
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]);
                
                // Seed academic year and terms
                $this->seedAcademicData($school);
                
                // Enable all modules for the new tenant
                $this->enableDefaultModules($tenant);

                // End tenancy to return to central DB
                tenancy()->end();

                // Create school reference in central DB
                $centralSchool = \App\Models\School::on('mysql')->create([
                    'tenant_id' => $tenant->id,
                    'name' => $schoolData['name'],
                    'code' => $school->code,
                    'address' => $schoolData['address'] ?? null,
                    'phone' => $schoolData['phone'] ?? null,
                    'email' => $schoolData['email'] ?? null,
                    'website' => $schoolData['website'] ?? null,
                    'status' => 'active',
                ]);

                // Store admin credentials for response
                $tenant->admin_credentials = [
                    'email' => $adminEmail,
                    'password' => 'Password@12345',
                    'role' => 'school_admin',
                ];
            }

            return $tenant;

        } catch (Exception $e) {
            // Clean up on failure
            if (isset($tenant)) {
                try {
                    // Delete tenant (stancl/tenancy will handle DB cleanup via events)
                    $tenant->delete();
                } catch (Exception $deleteException) {
                    Log::error('Failed to clean up tenant after error', [
                        'tenant_id' => $tenant->id ?? null,
                        'error' => $deleteException->getMessage()
                    ]);
            }
            }

            Log::error('Tenant creation failed', [
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Create database for tenant using stancl/tenancy jobs
     */
    public function createTenantDatabase(Tenant $tenant): void
    {
        // Use stancl/tenancy jobs to create database, run migrations, and seed
        // These jobs handle database creation with proper permissions

        // Ensure tenant has database_name set in both our format and stancl/tenancy's internal format
        if ($tenant->database_name) {
            // Set in stancl/tenancy's internal format (db_name)
            $tenant->setInternal('db_name', $tenant->database_name);

            // Also set database connection details if available
            if ($tenant->database_host) {
                $tenant->setInternal('db_host', $tenant->database_host);
            }
            if ($tenant->database_port) {
                $tenant->setInternal('db_port', $tenant->database_port);
            }
            if ($tenant->database_username) {
                $tenant->setInternal('db_username', $tenant->database_username);
            }
            if ($tenant->database_password) {
                $tenant->setInternal('db_password', $tenant->database_password);
            }

            // Save tenant to persist internal data
            $tenant->save();
        }

        // Get DatabaseManager instance (required by handle() method)
        $databaseManager = app(DatabaseManager::class);

        // Create database using stancl/tenancy CreateDatabase job
        $createDatabaseJob = new CreateDatabase($tenant);
        $createDatabaseJob->handle($databaseManager);

        // Configure tenant connection before running migrations
        $databaseName = $tenant->database_name;
        if ($databaseName) {
            Config::set('database.connections.tenant', [
                'driver' => 'mysql',
                'host' => $tenant->database_host ?? config('database.connections.mysql.host'),
                'port' => $tenant->database_port ?? config('database.connections.mysql.port'),
                'database' => $databaseName,
                'username' => $tenant->database_username ?? config('database.connections.mysql.username'),
                'password' => $tenant->database_password ?? config('database.connections.mysql.password'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'engine' => null,
            ]);

            // Clear connection cache to ensure new config is used
            DB::purge('tenant');
        }

        // Run migrations directly (more reliable than tenants:migrate command)
        try {
            $migrationExitCode = Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);

            if ($migrationExitCode !== 0) {
                Log::error("Tenant migrations failed", [
                    'tenant_id' => $tenant->id,
                    'database_name' => $databaseName,
                    'exit_code' => $migrationExitCode
                ]);
                throw new \Exception("Failed to run tenant migrations (exit code: {$migrationExitCode})");
            }

            Log::info("Tenant migrations completed successfully", [
                'tenant_id' => $tenant->id,
                'database_name' => $databaseName
            ]);
        } catch (\Exception $e) {
            Log::error("Tenant migration error", [
                'tenant_id' => $tenant->id,
                'database_name' => $databaseName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }

        // Initialize tenancy context for seeding
        tenancy()->initialize($tenant);

        try {
            // Run seeder manually with tenant context
            Artisan::call('db:seed', [
                '--class' => 'TenantSeeder',
                '--database' => 'tenant',
                '--force' => true,
            ]);

            Log::info("Tenant database seeded successfully", [
                'tenant_id' => $tenant->id,
                'database_name' => $databaseName
            ]);
        } catch (\Exception $e) {
            Log::warning("Tenant seeding failed (may be expected if no school exists yet): " . $e->getMessage(), [
                'tenant_id' => $tenant->id,
                'database_name' => $databaseName
            ]);
            // Don't throw - seeding can happen later when school is created
        } finally {
            // End tenancy context
            tenancy()->end();
        }
    }

    /**
     * Run migrations for tenant database
     */
    protected function runTenantMigrations(string $connectionName): void
    {
        // Set the connection for migrations
        Config::set('database.default', $connectionName);

        // Run tenant-specific migrations
        Artisan::call('migrate', [
            '--database' => $connectionName,
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);
    }

    /**
     * Run seeder for tenant database
     */
    protected function runTenantSeeder(string $connectionName, ?Tenant $tenant = null): void
    {
        // If tenant not provided, try to find it from connection name
        if (!$tenant) {
            // Connection name format is usually "tenant_{id}" or just the database name
            $databaseName = Config::get("database.connections.{$connectionName}.database");
            if ($databaseName) {
                $tenant = Tenant::where('database_name', $databaseName)->first();
            }
        }

        if (!$tenant) {
            // Can't find tenant, skip seeding
            return;
        }

        // Initialize tenancy context for the seeder
        // This ensures tenant() helper works in the seeder
        if (function_exists('tenancy')) {
            tenancy()->initialize($tenant);
        }

        // Set the connection for seeding
        Config::set('database.default', $connectionName);

        // Run tenant seeder to create admin account
        Artisan::call('db:seed', [
            '--database' => $connectionName,
            '--class' => 'TenantSeeder',
            '--force' => true,
        ]);

        // End tenancy context
        if (function_exists('tenancy')) {
            tenancy()->end();
        }
    }

    /**
     * Create school for tenant
     */
    protected function createSchoolForTenant(Tenant $tenant, array $schoolData): School
    {
        // Switch to tenant database
        $this->switchToTenant($tenant);

        $code = $schoolData['code'] ?? Str::upper(Str::slug($schoolData['name'], '_'));

        // Ensure code uniqueness within tenant context
        if (School::where('code', $code)->exists()) {
            $code = $code . '_' . Str::upper(Str::random(4));
        }

        return School::create([
            'tenant_id' => $tenant->id,
            'name' => $schoolData['name'],
            'code' => $code,
            'address' => $schoolData['address'] ?? '',
            'phone' => $schoolData['phone'] ?? '',
            'email' => $schoolData['email'] ?? '',
            'website' => $schoolData['website'] ?? '',
            'logo' => $schoolData['logo'] ?? '',
            'settings' => $schoolData['settings'] ?? [],
            'status' => 'active',
        ]);
    }

    /**
     * Create main database school record linked to tenant
     */
    protected function createMainSchoolRecord(Tenant $tenant, School $tenantSchool, array $schoolData): School
    {
        $payload = [
            'tenant_id' => $tenant->id,
            'name' => $tenantSchool->name,
            'address' => $tenantSchool->address,
            'phone' => $tenantSchool->phone,
            'email' => $tenantSchool->email,
            'website' => $tenantSchool->website,
            'logo' => $tenantSchool->logo,
            'settings' => $schoolData['settings'] ?? [],
            'status' => $tenantSchool->status,
            'principal_id' => $tenantSchool->principal_id ?? null,
            'vice_principal_id' => $tenantSchool->vice_principal_id ?? null,
            'academic_year' => $tenantSchool->academic_year ?? null,
            'term' => $tenantSchool->term ?? null,
        ];

        return School::on('mysql')->updateOrCreate(
            ['tenant_id' => $tenant->id],
            $payload
        );
    }

    /**
     * Create school admin user automatically
     */
    protected function createSchoolAdmin(Tenant $tenant, School $school, array $schoolData): array
    {
        // Generate admin email from school domain or use provided email
        $adminEmail = $schoolData['admin_email'] ?? $this->generateAdminEmail($school->name, $tenant->subdomain);

        // Generate default password (can be customized)
        $adminPassword = $schoolData['admin_password'] ?? $this->generateDefaultPassword();

        // Switch back to main database for user creation
        Config::set('database.default', 'mysql');

        // Create school admin user in main database
        $adminUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => $schoolData['admin_name'] ?? 'School Administrator',
            'email' => $adminEmail,
            'password' => Hash::make($adminPassword),
            'role' => 'school_admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        // Return user and plain password for response
        return [
            'user' => $adminUser,
            'password' => $adminPassword
        ];
    }

    /**
     * Generate admin email from school name and subdomain
     */
    protected function generateAdminEmail(string $schoolName, string $subdomain): string
    {
        // Generate email: admin@subdomain.samschool.com
        return "admin@{$subdomain}.samschool.com";
    }

    /**
     * Generate unique school code from school name
     */
    protected function generateSchoolCode(string $schoolName): string
    {
        $base = Str::upper(Str::slug($schoolName, '_'));
        $code = $base . '_' . Str::upper(Str::random(4));
        return $code;
    }

    /**
     * Generate default password for school admin
     */
    protected function generateDefaultPassword(): string
    {
        // Generate a secure random password
        return Str::random(12);
    }

    /**
     * Switch to tenant database using stancl/tenancy
     */
    public function switchToTenant(Tenant $tenant): void
    {
        // Use stancl/tenancy's initialization
        // This handles all the database connection setup automatically
        tenancy()->initialize($tenant);
    }

    /**
     * Get tenant by subdomain
     */
    public function getTenantBySubdomain(string $subdomain): ?Tenant
    {
        return Tenant::where('subdomain', $subdomain)
                    ->where('status', 'active')
                    ->first();
    }

    /**
     * Get tenant by domain
     */
    public function getTenantByDomain(string $domain): ?Tenant
    {
        return Tenant::where('domain', $domain)
                    ->where('status', 'active')
                    ->first();
    }

    /**
     * Generate unique database name in format: timestamp_school_name
     */
    protected function generateDatabaseName(string $name): string
    {
        $slug = Str::slug($name);
        $timestamp = now()->format('YmdHis');

        return $timestamp . '_' . $slug;
    }

    /**
     * Delete tenant and its database
     */
    public function deleteTenant(Tenant $tenant): bool
    {
        try {
            // Delete tenant record - stancl/tenancy will handle database cleanup via TenantDeleted event
            $tenant->delete();

            // Try to drop database silently (don't fail if it doesn't exist or has issues)
            try {
                if ($tenant->database_name) {
                    DB::statement("DROP DATABASE IF EXISTS `{$tenant->database_name}`");
                }
            } catch (Exception $dbError) {
                // Database deletion failed, but tenant record is deleted - log and continue
                Log::warning('Failed to drop tenant database', [
                    'tenant_id' => $tenant->id,
                    'database_name' => $tenant->database_name,
                    'error' => $dbError->getMessage()
                ]);
            }

            return true;

        } catch (Exception $e) {
            Log::error('Failed to delete tenant', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get tenant statistics
     */
    public function getTenantStats(Tenant $tenant): array
    {
        $originalConnection = Config::get('database.default', 'mysql');

        $this->switchToTenant($tenant);

        $stats = [
            'schools' => $this->tableCount('schools'),
            'users' => $this->tableCount('users'),
            'students' => $this->tableCount('students'),
            'teachers' => $this->tableCount('teachers'),
            'classes' => $this->tableCount('classes'),
            'subjects' => $this->tableCount('subjects'),
            'departments' => $this->tableCount('departments'),
            'modules' => $tenant->getSetting('modules', []),
        ];

        // Restore original connection
        Config::set('database.default', $originalConnection);
        DB::setDefaultConnection($originalConnection);

        return [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'status' => $tenant->status,
                'subscription_plan' => $tenant->getSubscriptionPlan(),
                'database' => $tenant->database_name,
            ],
            'stats' => $stats,
        ];
    }

    /**
     * Safely count records in a tenant table
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
     * Seed academic year and terms for a new school
     */
    protected function seedAcademicData($school): void
    {
        try {
            // Create current academic year
            $currentYear = date('Y');
            $nextYear = $currentYear + 1;
            
            $academicYear = \App\Models\AcademicYear::create([
                'school_id' => $school->id,
                'name' => "{$currentYear}-{$nextYear}",
                'start_date' => "{$currentYear}-09-01",
                'end_date' => "{$nextYear}-07-31",
                'status' => 'active',
                'is_current' => true,
            ]);
            
            Log::info("Academic year created for school", [
                'school_id' => $school->id,
                'academic_year' => "{$currentYear}-{$nextYear}"
            ]);
            
            // Create default terms
            $terms = [
                ['name' => '1st Term', 'start_date' => "{$currentYear}-09-01", 'end_date' => "{$currentYear}-12-15"],
                ['name' => '2nd Term', 'start_date' => "{$nextYear}-01-05", 'end_date' => "{$nextYear}-04-10"],
                ['name' => '3rd Term', 'start_date' => "{$nextYear}-04-20", 'end_date' => "{$nextYear}-07-31"],
            ];
            
            foreach ($terms as $index => $termData) {
                \App\Models\Term::create([
                    'school_id' => $school->id,
                    'academic_year_id' => $academicYear->id,
                    'name' => $termData['name'],
                    'start_date' => $termData['start_date'],
                    'end_date' => $termData['end_date'],
                    'status' => 'active',
                    'is_current' => $index === 0, // First term is current
                ]);
            }
            
            Log::info("Terms created for school", ['school_id' => $school->id, 'count' => count($terms)]);
            
        } catch (Exception $e) {
            Log::error("Failed to seed academic data", [
                'school_id' => $school->id,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Enable all default modules for a new tenant
     */
    protected function enableDefaultModules(Tenant $tenant): void
    {
        try {
            $defaultModules = [
                'academic_management' => [
                    'name' => 'Academic Management',
                    'enabled' => true,
                    'features' => ['academic_years', 'terms', 'classes', 'subjects']
                ],
                'student_management' => [
                    'name' => 'Student Management',
                    'enabled' => true,
                    'features' => ['students', 'enrollment', 'credentials']
                ],
                'teacher_management' => [
                    'name' => 'Teacher Management',
                    'enabled' => true,
                    'features' => ['teachers', 'assignments', 'schedules']
                ],
                'staff_management' => [
                    'name' => 'Staff Management',
                    'enabled' => true,
                    'features' => ['staff', 'payroll', 'attendance']
                ],
                'exam_management' => [
                    'name' => 'Exam Management',
                    'enabled' => true,
                    'features' => ['exams', 'results', 'report_cards']
                ],
                'cbt' => [
                    'name' => 'Computer Based Testing',
                    'enabled' => true,
                    'features' => ['online_exams', 'assessments', 'quizzes']
                ],
                'library' => [
                    'name' => 'Library Management',
                    'enabled' => true,
                    'features' => ['books', 'borrowing', 'fines']
                ],
                'finance' => [
                    'name' => 'Finance & Accounting',
                    'enabled' => true,
                    'features' => ['fees', 'payments', 'invoices', 'expenses']
                ],
                'communication' => [
                    'name' => 'Communication',
                    'enabled' => true,
                    'features' => ['announcements', 'messages', 'notifications']
                ],
            ];
            
            // Store modules in tenant data field
            $tenant->data = array_merge($tenant->data ?? [], [
                'modules' => $defaultModules
            ]);
            $tenant->save();
            
            Log::info("Default modules enabled for tenant", [
                'tenant_id' => $tenant->id,
                'modules_count' => count($defaultModules)
            ]);
            
        } catch (Exception $e) {
            Log::error("Failed to enable default modules", [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
