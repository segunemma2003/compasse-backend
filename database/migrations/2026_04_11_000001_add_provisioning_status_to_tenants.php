<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite does not support MODIFY COLUMN and does not enforce ENUM constraints
        // (it stores them as TEXT), so the new values are already accepted without changes.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE tenants MODIFY COLUMN status ENUM('active','inactive','suspended','provisioning','failed') NOT NULL DEFAULT 'provisioning'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE tenants MODIFY COLUMN status ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active'");
        }
    }
};
