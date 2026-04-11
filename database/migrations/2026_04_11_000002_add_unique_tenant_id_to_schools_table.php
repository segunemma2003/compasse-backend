<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove any duplicate rows before adding the unique constraint.
        // Keep the earliest school per tenant; delete the rest.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            \Illuminate\Support\Facades\DB::statement('
                DELETE s1 FROM schools s1
                INNER JOIN schools s2
                    ON s1.tenant_id = s2.tenant_id AND s1.id > s2.id
            ');
        }

        Schema::table('schools', function (Blueprint $table) {
            $table->unique('tenant_id', 'schools_tenant_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropUnique('schools_tenant_id_unique');
        });
    }
};
