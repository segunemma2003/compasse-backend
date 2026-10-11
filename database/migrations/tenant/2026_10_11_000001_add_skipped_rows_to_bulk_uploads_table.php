<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rows for people who are already on the books are skipped rather than
     * counted as failures, so an upload re-run after a partial success
     * reports "10 skipped" instead of "10 failed".
     */
    public function up(): void
    {
        if (! Schema::hasColumn('bulk_uploads', 'skipped_rows')) {
            Schema::table('bulk_uploads', function (Blueprint $table) {
                $table->unsignedInteger('skipped_rows')->default(0)->after('failed_rows');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bulk_uploads', 'skipped_rows')) {
            Schema::table('bulk_uploads', function (Blueprint $table) {
                $table->dropColumn('skipped_rows');
            });
        }
    }
};
