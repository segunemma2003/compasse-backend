<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_term_vitals')) {
            return;
        }

        if (! Schema::hasColumn('student_term_vitals', 'memorable_moment')) {
            Schema::table('student_term_vitals', function (Blueprint $table) {
                $table->text('memorable_moment')->nullable()->after('report_photo_url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('student_term_vitals') && Schema::hasColumn('student_term_vitals', 'memorable_moment')) {
            Schema::table('student_term_vitals', function (Blueprint $table) {
                $table->dropColumn('memorable_moment');
            });
        }
    }
};
