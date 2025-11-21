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
        // Ensure the tenants table has the 'data' column used by stancl/tenancy's BaseTenant
        if (Schema::hasTable('tenants') && ! Schema::hasColumn('tenants', 'data')) {
            Schema::table('tenants', function (Blueprint $table) {
                // Place the JSON 'data' column after the primary key if possible
                if (Schema::hasColumn('tenants', 'id')) {
                    $table->json('data')->nullable()->after('id');
                } else {
                    $table->json('data')->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('tenants') && Schema::hasColumn('tenants', 'data')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn('data');
            });
        }
    }
};


