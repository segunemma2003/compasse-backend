<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Exception;

class TenantService
{
    /**
     * Create a new tenant with database
     */
    public function createTenant(array $data): Tenant
    {
        DB::beginTransaction();
        
        try {
            // Create tenant record
            $tenant = Tenant::create([
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
            ]);

            // Create database for tenant
            $this->createTenantDatabase($tenant);

            // Create school for tenant
            if (isset($data['school'])) {
                $this->createSchoolForTenant($tenant, $data['school']);
            }

            DB::commit();
            return $tenant;

        } catch (Exception $e) {
            DB::rollBack();
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

        return School::create([
            'tenant_id' => $tenant->id,
            'name' => $schoolData['name'],
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
        $this->switchToTenant($tenant);
        
        return [
            'tenant' => $tenant->getStats(),
            'schools' => School::count(),
            'users' => DB::table('users')->count(),
            'students' => DB::table('students')->count(),
            'teachers' => DB::table('teachers')->count(),
        ];
    }
}
