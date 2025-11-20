<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if table already exists (from stancl/tenancy migration)
        if (Schema::hasTable('tenants')) {
            // Table exists, add missing columns if needed
            Schema::table('tenants', function (Blueprint $table) {
                // Check and add columns that might be missing
                if (!Schema::hasColumn('tenants', 'name')) {
                    $table->string('name')->nullable()->after('id');
                }
                if (!Schema::hasColumn('tenants', 'domain')) {
                    $table->string('domain')->nullable()->after('name');
                }
                if (!Schema::hasColumn('tenants', 'subdomain')) {
                    $table->string('subdomain')->nullable()->unique()->after('domain');
                }
                if (!Schema::hasColumn('tenants', 'database_name')) {
                    $table->string('database_name')->nullable()->after('subdomain');
                }
                if (!Schema::hasColumn('tenants', 'database_host')) {
                    $table->string('database_host')->nullable()->after('database_name');
                }
                if (!Schema::hasColumn('tenants', 'database_port')) {
                    $table->string('database_port')->nullable()->after('database_host');
                }
                if (!Schema::hasColumn('tenants', 'database_username')) {
                    $table->string('database_username')->nullable()->after('database_port');
                }
                if (!Schema::hasColumn('tenants', 'database_password')) {
                    $table->string('database_password')->nullable()->after('database_username');
                }
                if (!Schema::hasColumn('tenants', 'status')) {
                    $table->enum('status', ['active', 'inactive', 'suspended'])->default('active')->after('database_password');
                }
                if (!Schema::hasColumn('tenants', 'settings')) {
                    $table->json('settings')->nullable()->after('status');
                }
                // Additional fields for school management
                if (!Schema::hasColumn('tenants', 'subscription_plan')) {
                    $table->string('subscription_plan')->nullable()->after('settings');
                }
                if (!Schema::hasColumn('tenants', 'max_schools')) {
                    $table->integer('max_schools')->nullable()->after('subscription_plan');
                }
                if (!Schema::hasColumn('tenants', 'max_users')) {
                    $table->integer('max_users')->nullable()->after('max_schools');
                }
                if (!Schema::hasColumn('tenants', 'features')) {
                    $table->json('features')->nullable()->after('max_users');
                }
            });

            // Add indexes separately (can't add in table modification callback)
            try {
                if (!Schema::hasColumn('tenants', 'subdomain') || !Schema::hasColumn('tenants', 'status')) {
                    // Columns don't exist yet, skip index
                } else {
                    // Try to add composite index if it doesn't exist
                    $connection = Schema::getConnection();
                    $doctrineSchemaManager = $connection->getDoctrineSchemaManager();
                    $indexes = $doctrineSchemaManager->listTableIndexes('tenants');

                    $hasSubdomainStatusIndex = false;
                    foreach ($indexes as $index) {
                        $columns = $index->getColumns();
                        if (count($columns) === 2 && in_array('subdomain', $columns) && in_array('status', $columns)) {
                            $hasSubdomainStatusIndex = true;
                            break;
                        }
                    }

                    if (!$hasSubdomainStatusIndex) {
                        $connection->statement('CREATE INDEX IF NOT EXISTS tenants_subdomain_status_index ON tenants(subdomain, status)');
                    }
                }
            } catch (\Exception $e) {
                // Index might already exist or other issue, continue
            }
        } else {
            // Table doesn't exist, create it
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
                $table->string('name')->nullable();
            $table->string('domain')->nullable();
                $table->string('subdomain')->nullable()->unique();
                $table->string('database_name')->nullable();
                $table->string('database_host')->nullable();
                $table->string('database_port')->nullable();
                $table->string('database_username')->nullable();
                $table->string('database_password')->nullable();
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->json('settings')->nullable();
                $table->string('subscription_plan')->nullable();
                $table->integer('max_schools')->nullable();
                $table->integer('max_users')->nullable();
                $table->json('features')->nullable();
            $table->timestamps();

            $table->index(['subdomain', 'status']);
            $table->index(['domain', 'status']);
        });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
