<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subjects')) {
            return;
        }

        Schema::table('subjects', function (Blueprint $table) {
            if (! Schema::hasColumn('subjects', 'is_optional')) {
                $table->boolean('is_optional')->default(false)->after('status');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('subjects') && Schema::hasColumn('subjects', 'is_optional')) {
            Schema::table('subjects', function (Blueprint $table) {
                $table->dropColumn('is_optional');
            });
        }
    }
};
