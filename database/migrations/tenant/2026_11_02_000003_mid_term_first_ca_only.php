<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mid-term results should show the 1st CA only. Every CA column used to be ticked
 * "counts for mid-term" by default, so the 2nd/3rd CA were added in and the mid-term
 * report looked like the full term. Untick the later CAs (a school can tick them again
 * on the mark sheet).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('continuous_assessments') || ! Schema::hasColumn('continuous_assessments', 'counts_for_mid_term')) {
            return;
        }
        DB::table('continuous_assessments')->where('counts_for_mid_term', true)->get(['id', 'name'])
            ->filter(fn ($ca) => preg_match('/\b(2nd|3rd|4th|second|third|fourth)\b|\bca\s*[2-9]\b|\btest\s*[2-9]\b/i', (string) $ca->name))
            ->each(fn ($ca) => DB::table('continuous_assessments')->where('id', $ca->id)->update(['counts_for_mid_term' => false, 'updated_at' => now()]));
    }

    public function down(): void
    {
    }
};
