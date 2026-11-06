<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A special subject (e.g. Handwriting, French) can be graded on its own scale. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subjects') && ! Schema::hasColumn('subjects', 'grading_system_id')) {
            Schema::table('subjects', function (Blueprint $t) {
                $t->unsignedBigInteger('grading_system_id')->nullable()->after('is_optional');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subjects', 'grading_system_id')) {
            Schema::table('subjects', fn (Blueprint $t) => $t->dropColumn('grading_system_id'));
        }
    }
};
