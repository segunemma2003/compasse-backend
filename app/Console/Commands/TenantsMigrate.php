<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class TenantsMigrate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:migrate
                            {--tenant= : Run migrations for a specific tenant ID}
                            {--fresh : Drop all tables and re-run migrations}
                            {--seed : Seed the database after running migrations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run database migrations for all tenant databases';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tenantId = $this->option('tenant');
        $fresh = $this->option('fresh');
        $seed = $this->option('seed');

        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            if (!$tenant) {
                $this->error("Tenant with ID {$tenantId} not found.");
                return 1;
            }
            $tenants = collect([$tenant]);
            $this->info("Running migrations for tenant: {$tenant->name}");
        } else {
            $tenants = Tenant::all();
            $this->info("Found {$tenants->count()} tenant(s) to migrate.");
        }

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found. Please create a tenant first.');
            return 0;
        }

        $successCount = 0;
        $failCount = 0;

        foreach ($tenants as $tenant) {
            $this->line("");
            $this->info("Processing tenant: {$tenant->name} (ID: {$tenant->id})");

            try {
                // Ensure database exists
                $databaseName = $tenant->database_name;
                $databaseExists = DB::select(
                    'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
                    [$databaseName]
                );

                if (empty($databaseExists)) {
                    $this->warn("Database '{$databaseName}' does not exist. Creating...");
                    DB::statement("CREATE DATABASE `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $this->info("Database '{$databaseName}' created successfully.");
                }

                // Configure tenant connection
                $tenantUsername = $tenant->database_username ?: config('database.connections.mysql.username');
                $tenantPassword = $tenant->database_password ?: config('database.connections.mysql.password');

                config(['database.connections.tenant' => [
                    'driver' => 'mysql',
                    'host' => config('database.connections.mysql.host'),
                    'port' => config('database.connections.mysql.port'),
                    'database' => $databaseName,
                    'username' => $tenantUsername,
                    'password' => $tenantPassword,
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'prefix' => '',
                    'strict' => true,
                    'engine' => null,
                ]]);

                // Run migrations
                $this->line("Running migrations for database: {$databaseName}");

                $migrateCommand = 'migrate';
                if ($fresh) {
                    $migrateCommand = 'migrate:fresh';
                }

                $exitCode = Artisan::call($migrateCommand, [
                    '--database' => 'tenant',
                    '--path' => 'database/migrations/tenant',
                    '--force' => true,
                ]);

                if ($exitCode === 0) {
                    $this->info("✅ Migrations completed for {$tenant->name}");
                    $successCount++;

                    // Run seed if requested
                    if ($seed) {
                        $this->line("Seeding database...");
                        Artisan::call('db:seed', [
                            '--database' => 'tenant',
                            '--force' => true,
                        ]);
                        $this->info("✅ Database seeded for {$tenant->name}");
                    }
                } else {
                    $this->error("❌ Migration failed for {$tenant->name}");
                    $failCount++;
                }

            } catch (\Exception $e) {
                $this->error("❌ Error processing tenant {$tenant->name}: {$e->getMessage()}");
                $failCount++;
            }
        }

        $this->line("");
        $this->info("=========================================");
        $this->info("Migration Summary:");
        $this->info("✅ Successful: {$successCount}");
        $this->info("❌ Failed: {$failCount}");
        $this->info("=========================================");

        return $failCount > 0 ? 1 : 0;
    }
}
