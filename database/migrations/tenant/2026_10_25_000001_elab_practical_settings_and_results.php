<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** e-Lab practicals: the teacher's set-up (concentrations, salt, planet…) and the readings / answers a student records. */
    public function up(): void
    {
        if (Schema::hasTable('elab_activities') && ! Schema::hasColumn('elab_activities', 'settings')) {
            Schema::table('elab_activities', fn (Blueprint $t) => $t->json('settings')->nullable());
        }
        if (Schema::hasTable('elab_submissions') && ! Schema::hasColumn('elab_submissions', 'results')) {
            Schema::table('elab_submissions', fn (Blueprint $t) => $t->json('results')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('elab_activities', 'settings')) {
            Schema::table('elab_activities', fn (Blueprint $t) => $t->dropColumn('settings'));
        }
        if (Schema::hasColumn('elab_submissions', 'results')) {
            Schema::table('elab_submissions', fn (Blueprint $t) => $t->dropColumn('results'));
        }
    }
};
