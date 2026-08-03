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

        if (! Schema::hasColumn('student_term_vitals', 'report_photo_url')) {
            Schema::table('student_term_vitals', function (Blueprint $table) {
                $table->string('report_photo_url', 500)->nullable()->after('punctuality_rating');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('student_term_vitals') && Schema::hasColumn('student_term_vitals', 'report_photo_url')) {
            Schema::table('student_term_vitals', function (Blueprint $table) {
                $table->dropColumn('report_photo_url');
            });
        }
    }
};
