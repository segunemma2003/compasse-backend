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
use Exception;

class TenantService
{
    /**
     * Create a new tenant with database
     */
    public function createTenant(array $data): Tenant
    {
        try {
            // Check if ID column is string (UUID) or integer (auto-increment)
            try {
                $idType = \Illuminate\Support\Facades\Schema::getColumnType('tenants', 'id');
            } catch (\Exception $e) {
                $idType = 'string'; // Default to string for stancl/tenancy
            }
            
            $tenantData = [
                'name' => $data['name'],
                'domain' => $data['domain'] ?? null,
                'subdomain' => $data['subdomain'] ?? Str::slug($data['name']),
                'database_name' => $this->generateDatabaseName($data['name']),
                'database_host' => config('database.connections.mysql.host'),
                'database_port' => config('database.connections.mysql.port'),
                'database_username' => config('database.connections.mysql.username'),
                'database_password' => config('database.connections.mysql.password'),
                'status' => 'active',
                'settings' => $data['settings'] ?? [],
            ];
            
            // Add UUID if ID column is string type (stancl/tenancy uses UUID)
            if ($idType === 'string' || $idType === 'varchar') {
                $tenantData['id'] = Str::uuid()->toString();
            }
            
            // Create tenant record
            $tenant = Tenant::create($tenantData);

            // Create database for tenant
            $this->createTenantDatabase($tenant);

            // Create school for tenant
            $school = null;
            $adminData = null;
            if (isset($data['school'])) {
            $schoolData = $data['school'];
            $tenantSchool = $this->createSchoolForTenant($tenant, $schoolData);

            // Create school admin user automatically
            $adminData = $this->createSchoolAdmin($tenant, $tenantSchool, $schoolData);

            // Ensure we are back on the primary connection before creating global school record
            Config::set('database.default', 'mysql');
            DB::setDefaultConnection('mysql');

            $school = $this->createMainSchoolRecord($tenant, $tenantSchool, $schoolData);
            }

            // Store admin data in tenant for retrieval
            if ($adminData) {
                $tenant->admin_data = $adminData;
            }

            // Reset default connection to primary database
            Config::set('database.default', 'mysql');
            DB::setDefaultConnection('mysql');

            return $tenant;

        } catch (Exception $e) {
            // Attempt to clean up tenant artifacts
            if (isset($tenant)) {
                try {
                    if (!empty($tenant->database_name)) {
                        DB::statement("DROP DATABASE IF EXISTS `{$tenant->database_name}`");
                    }
                } catch (Exception $dropException) {
                    // Ignore drop failures, we'll still delete tenant record
                }

                $tenant->delete();
            }

            // Ensure default connection is restored
            Config::set('database.default', 'mysql');
            DB::setDefaultConnection('mysql');
            throw $e;
        }
    }

    /**
     * Create database for tenant
     */
    protected function createTenantDatabase(Tenant $tenant): void
    {
        $connectionName = $tenant->getDatabaseConnectionName();
        $databaseName = $tenant->database_name;

        // Create database
        DB::statement("CREATE DATABASE IF NOT EXISTS `{$databaseName}`");

        // Configure tenant database connection
        Config::set("database.connections.{$connectionName}", [
            'driver' => 'mysql',
            'host' => $tenant->database_host,
            'port' => $tenant->database_port,
            'database' => $databaseName,
            'username' => $tenant->database_username,
            'password' => $tenant->database_password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ]);

        // Run migrations for tenant database
        $this->runTenantMigrations($connectionName);
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
     * Generate default password for school admin
     */
    protected function generateDefaultPassword(): string
    {
        // Generate a secure random password
        return Str::random(12);
    }

    /**
     * Switch to tenant database
     */
    public function switchToTenant(Tenant $tenant): void
    {
        $connectionName = $tenant->getDatabaseConnectionName();

        // Configure the connection if not already configured
        if (!Config::has("database.connections.{$connectionName}")) {
            Config::set("database.connections.{$connectionName}", [
                'driver' => 'mysql',
                'host' => $tenant->database_host,
                'port' => $tenant->database_port,
                'database' => $tenant->database_name,
                'username' => $tenant->database_username,
                'password' => $tenant->database_password,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
            ]);
        }

        // Set as default connection
        Config::set('database.default', $connectionName);
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
     * Generate unique database name
     */
    protected function generateDatabaseName(string $name): string
    {
        $prefix = config('database.tenant_prefix', 'tenant_');
        $slug = Str::slug($name);
        $timestamp = now()->format('YmdHis');

        return $prefix . $slug . '_' . $timestamp;
    }

    /**
     * Delete tenant and its database
     */
    public function deleteTenant(Tenant $tenant): bool
    {
        DB::beginTransaction();

        try {
            // Drop tenant database
            DB::statement("DROP DATABASE IF EXISTS `{$tenant->database_name}`");

            // Delete tenant record
            $tenant->delete();

            DB::commit();
            return true;

        } catch (Exception $e) {
            DB::rollBack();
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
        if (!\Schema::hasTable($table)) {
            return 0;
        }

        try {
            return DB::table($table)->count();
        } catch (Exception $e) {
            return 0;
        }
    }
}
