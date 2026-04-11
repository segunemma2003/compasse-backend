<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-side users are not rows in the central `users` table, so imports
 * initiated by teachers cannot reference a central user id.  Drop the FK and
 * allow NULL; audit trail remains via tenant_id + timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('question_bank_imports')) {
            return;
        }

        try {
            Schema::table('question_bank_imports', function (Blueprint $table) {
                $table->dropForeign(['imported_by']);
            });
        } catch (\Throwable $e) {
            // Already dropped or unsupported driver
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE question_bank_imports MODIFY imported_by BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('question_bank_imports')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE question_bank_imports MODIFY imported_by BIGINT UNSIGNED NOT NULL');

        Schema::table('question_bank_imports', function (Blueprint $table) {
            $table->foreign('imported_by')->references('id')->on('users')->restrictOnDelete();
        });
    }
};
