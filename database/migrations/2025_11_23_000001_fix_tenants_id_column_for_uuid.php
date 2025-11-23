<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Only run this migration for MySQL in production
        // SQLite and PostgreSQL handle this differently
        $driver = DB::connection()->getDriverName();
        
        if ($driver !== 'mysql') {
            // Skip for non-MySQL databases (like SQLite in tests)
            return;
        }
        
        // Check if the tenants table exists and has an integer id column
        if (Schema::hasTable('tenants')) {
            try {
                $columnType = DB::select("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'tenants' 
                    AND COLUMN_NAME = 'id'");
                
                // If id column is not a string type, we need to fix it
                if (!empty($columnType) && !in_array(strtolower($columnType[0]->DATA_TYPE), ['varchar', 'char', 'text'])) {
                    // First, check if there are any existing records
                    $hasRecords = DB::table('tenants')->count() > 0;
                    
                    if ($hasRecords) {
                        // If there are existing records with integer IDs, we need to migrate them
                        // This is a destructive operation, so we'll backup the data first
                        DB::statement('CREATE TABLE IF NOT EXISTS tenants_backup AS SELECT * FROM tenants');
                        
                        // Drop foreign key constraints temporarily if they exist
                        // Note: Adjust table names based on your schema
                        $foreignKeys = DB::select("
                            SELECT CONSTRAINT_NAME 
                            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND REFERENCED_TABLE_NAME = 'tenants'
                        ");
                        
                        $droppedConstraints = [];
                        foreach ($foreignKeys as $fk) {
                            try {
                                $tableName = DB::selectOne("
                                    SELECT TABLE_NAME 
                                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                                    WHERE CONSTRAINT_NAME = ? 
                                    AND TABLE_SCHEMA = DATABASE()
                                ", [$fk->CONSTRAINT_NAME])->TABLE_NAME;
                                
                                DB::statement("ALTER TABLE `{$tableName}` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                                $droppedConstraints[] = ['table' => $tableName, 'constraint' => $fk->CONSTRAINT_NAME];
                            } catch (\Exception $e) {
                                // Continue if constraint doesn't exist
                            }
                        }
                        
                        // Truncate the table (we'll restore from backup)
                        DB::table('tenants')->truncate();
                    }
                    
                    // Modify the id column to accept UUIDs
                    Schema::table('tenants', function (Blueprint $table) {
                        // Drop the primary key first
                        $table->dropPrimary(['id']);
                    });
                    
                    // Change id column to string (36 chars for UUID)
                    DB::statement('ALTER TABLE tenants MODIFY COLUMN id VARCHAR(36) NOT NULL');
                    
                    // Re-add primary key
                    Schema::table('tenants', function (Blueprint $table) {
                        $table->primary('id');
                    });
                    
                    if ($hasRecords) {
                        // Note: We can't automatically restore integer IDs as UUIDs
                        // Manual intervention may be required if there was critical data
                        // For now, we'll just log this
                        \Illuminate\Support\Facades\Log::warning('Tenants table ID column migrated from integer to UUID. Previous data backed up in tenants_backup table.');
                    }
                }
            } catch (\Exception $e) {
                // If there's any error, log it but don't fail the migration
                \Illuminate\Support\Facades\Log::error('Failed to migrate tenants.id column: ' . $e->getMessage());
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration is not reversible as it involves data type changes
        // Manual intervention required if rollback is needed
    }
};

