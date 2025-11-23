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
        // Make tenant_id nullable in users table to allow super admins without tenants
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'tenant_id')) {
            $driver = DB::connection()->getDriverName();
            
            if ($driver === 'mysql') {
                // Check the current column type
                $columnInfo = DB::select("SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE 
                    FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'users' 
                    AND COLUMN_NAME = 'tenant_id'");
                
                if (!empty($columnInfo) && $columnInfo[0]->IS_NULLABLE === 'NO') {
                    // Drop foreign key constraint temporarily if it exists
                    $foreignKeys = DB::select("
                        SELECT CONSTRAINT_NAME 
                        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                        WHERE TABLE_SCHEMA = DATABASE() 
                        AND TABLE_NAME = 'users' 
                        AND COLUMN_NAME = 'tenant_id'
                        AND REFERENCED_TABLE_NAME IS NOT NULL
                    ");
                    
                    $droppedConstraint = null;
                    if (!empty($foreignKeys)) {
                        $constraintName = $foreignKeys[0]->CONSTRAINT_NAME;
                        try {
                            DB::statement("ALTER TABLE `users` DROP FOREIGN KEY `{$constraintName}`");
                            $droppedConstraint = $constraintName;
                        } catch (\Exception $e) {
                            // Foreign key might not exist
                        }
                    }
                    
                    // Determine the column type
                    $columnType = strtolower($columnInfo[0]->DATA_TYPE);
                    if ($columnType === 'bigint' || strpos($columnInfo[0]->COLUMN_TYPE, 'bigint') !== false) {
                        DB::statement('ALTER TABLE users MODIFY COLUMN tenant_id BIGINT UNSIGNED NULL');
                    } else {
                        // Assume it's a string/varchar (for UUID)
                        DB::statement('ALTER TABLE users MODIFY COLUMN tenant_id VARCHAR(255) NULL');
                    }
                    
                    // Re-add foreign key constraint if it existed
                    if ($droppedConstraint && Schema::hasTable('tenants')) {
                        try {
                            Schema::table('users', function (Blueprint $table) {
                                $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');
                            });
                        } catch (\Exception $e) {
                            // Constraint might conflict, skip
                        }
                    }
                }
            } elseif ($driver === 'sqlite') {
                // SQLite doesn't support ALTER COLUMN, so we skip
                // This is fine for tests
            } elseif ($driver === 'pgsql') {
                DB::statement('ALTER TABLE users ALTER COLUMN tenant_id DROP NOT NULL');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Not reversible - we don't want to make tenant_id NOT NULL again
        // as it would fail if super admins exist
    }
};

