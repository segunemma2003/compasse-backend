<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exam Submissions table.
 *
 * Stores the final scored submission for a written/paper exam per student.
 * (CBT sittings live in exam_attempts; this table covers non-CBT exams
 * where a teacher manually enters the student's exam score.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('exam_submissions')) {
            return;
        }

        Schema::create('exam_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('exam_id');
            $table->unsignedBigInteger('student_id');
            $table->decimal('score', 8, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();

            // Prevent duplicate submission per exam+student
            $table->unique(['exam_id', 'student_id'], 'exam_submission_unique');

            // Covering index used by the bulk pre-load in ResultController::generateResults():
            //   JOIN exams ON exam_submissions.exam_id = exams.id
            //   WHERE exams.term_id = ? AND exams.academic_year_id = ?
            //   AND   exam_submissions.student_id IN (...)
            $table->index(['exam_id', 'student_id'], 'idx_exam_sub_exam_student');
            $table->index('student_id', 'idx_exam_sub_student');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_submissions');
    }
};
