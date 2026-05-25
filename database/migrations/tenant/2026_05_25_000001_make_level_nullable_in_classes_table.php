<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('classes') && Schema::hasColumn('classes', 'level')) {
            Schema::table('classes', function (Blueprint $table) {
                $table->string('level')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('classes') && Schema::hasColumn('classes', 'level')) {
            Schema::table('classes', function (Blueprint $table) {
                // Set a default before making NOT NULL to avoid failures on existing rows
                $table->string('level')->nullable(false)->default('')->change();
            });
        }
    }
};
