<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Extend the ENUM to include provisioning and failed states.
        DB::statement("ALTER TABLE tenants MODIFY COLUMN status ENUM('active','inactive','suspended','provisioning','failed') NOT NULL DEFAULT 'provisioning'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tenants MODIFY COLUMN status ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active'");
    }
};
