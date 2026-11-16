<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Half-term dates: the half-term report prints these (it showed the full-term dates). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terms', function (Blueprint $t) {
            if (! Schema::hasColumn('terms', 'half_term_starts')) {
                $t->date('half_term_starts')->nullable();
            }
            if (! Schema::hasColumn('terms', 'half_term_resumes')) {
                $t->date('half_term_resumes')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('terms', fn (Blueprint $t) => $t->dropColumn(['half_term_starts', 'half_term_resumes']));
    }
};
