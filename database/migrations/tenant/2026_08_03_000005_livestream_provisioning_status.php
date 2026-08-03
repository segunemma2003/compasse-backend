<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('livestreams')) {
            DB::statement("ALTER TABLE livestreams MODIFY status ENUM('provisioning','scheduled','active','completed','cancelled') NOT NULL DEFAULT 'scheduled'");
        }
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('livestreams')) {
            DB::statement("ALTER TABLE livestreams MODIFY status ENUM('scheduled','active','completed','cancelled') NOT NULL DEFAULT 'scheduled'");
        }
    }
};
