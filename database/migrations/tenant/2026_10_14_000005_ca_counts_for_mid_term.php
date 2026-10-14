<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Which CAs make up the mid-term result (e.g. 1st CA + mid-term test, not the late-term CAs). */
    public function up(): void
    {
        if (Schema::hasTable('continuous_assessments') && ! Schema::hasColumn('continuous_assessments', 'counts_for_mid_term')) {
            Schema::table('continuous_assessments', function (Blueprint $t) {
                $t->boolean('counts_for_mid_term')->default(true)->after('total_marks');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('continuous_assessments', 'counts_for_mid_term')) {
            Schema::table('continuous_assessments', fn (Blueprint $t) => $t->dropColumn('counts_for_mid_term'));
        }
    }
};
