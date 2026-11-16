<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subjects graded without scores (e.g. Handwriting, Music): the teacher picks a grade for each
 * student; the result shows only that grade and the subject is left out of totals and averages.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subjects') && ! Schema::hasColumn('subjects', 'grade_only')) {
            Schema::table('subjects', fn (Blueprint $t) => $t->boolean('grade_only')->default(false));
        }
        if (! Schema::hasTable('subject_grades')) {
            Schema::create('subject_grades', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('student_id');
                $t->unsignedBigInteger('subject_id');
                $t->unsignedBigInteger('term_id');
                $t->unsignedBigInteger('academic_year_id')->nullable();
                $t->string('grade', 50);
                $t->string('remark')->nullable();
                $t->unsignedBigInteger('recorded_by')->nullable();
                $t->timestamps();
                $t->unique(['student_id', 'subject_id', 'term_id'], 'subject_grades_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_grades');
        if (Schema::hasColumn('subjects', 'grade_only')) {
            Schema::table('subjects', fn (Blueprint $t) => $t->dropColumn('grade_only'));
        }
    }
};
