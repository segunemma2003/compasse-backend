<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Term attendance totals a class teacher fills in per student (days the
     * school opened, days present, days absent). Report cards use these when
     * present, instead of counting the daily attendance register — many
     * schools keep the register on paper and only record term totals.
     */
    public function up(): void
    {
        if (Schema::hasTable('term_attendance_summaries')) {
            return;
        }

        Schema::create('term_attendance_summaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('term_id');
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->unsignedSmallInteger('days_opened')->default(0);
            $table->unsignedSmallInteger('days_present')->default(0);
            $table->unsignedSmallInteger('days_absent')->default(0);
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'term_id']);
            $table->index('term_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('term_attendance_summaries');
    }
};
