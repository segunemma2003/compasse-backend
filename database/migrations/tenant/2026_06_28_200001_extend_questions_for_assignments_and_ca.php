<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the questions system to support assignment and CA questions
 * alongside the existing exam questions.
 *
 * Strategy: make exam_id and subject_id nullable on the questions table,
 * add assignment_id and ca_id columns (one per row will be set),
 * then add student-answer tables for assignments and CAs.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Extend questions table ─────────────────────────────────────────
        Schema::table('questions', function (Blueprint $table) {
            // Drop the strict NOT NULL foreign key constraints so we can make
            // the columns nullable (exam questions stay as-is; new rows will
            // have assignment_id or ca_id set instead).
            $table->dropForeign(['exam_id']);
            $table->dropForeign(['subject_id']);

            $table->foreignId('exam_id')->nullable()->change();
            $table->foreignId('subject_id')->nullable()->change();

            // Re-add foreign keys as nullable-friendly
            $table->foreign('exam_id')->references('id')->on('exams')->cascadeOnDelete();
            $table->foreign('subject_id')->references('id')->on('subjects')->cascadeOnDelete();

            $table->foreignId('assignment_id')
                ->nullable()
                ->after('exam_id')
                ->constrained('assignments')
                ->cascadeOnDelete();

            $table->foreignId('ca_id')
                ->nullable()
                ->after('assignment_id')
                ->constrained('continuous_assessments')
                ->cascadeOnDelete();
        });

        // ── 2. Student answers for assignment questions ────────────────────────
        Schema::create('assignment_question_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('assignments')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->json('answer_data')->nullable();       // same shape as question_attempts.answer_data
            $table->boolean('is_correct')->nullable();     // null = open-ended (teacher grades)
            $table->decimal('marks_obtained', 8, 2)->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();

            $table->unique(['assignment_id', 'question_id', 'student_id'], 'aqa_unique');
            $table->index(['assignment_id', 'student_id']);
            $table->index(['question_id', 'student_id']);
        });

        // ── 3. Student answers for CA questions ───────────────────────────────
        Schema::create('ca_question_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ca_id')->constrained('continuous_assessments')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->json('answer_data')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->decimal('marks_obtained', 8, 2)->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();

            $table->unique(['ca_id', 'question_id', 'student_id'], 'cqa_unique');
            $table->index(['ca_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ca_question_answers');
        Schema::dropIfExists('assignment_question_answers');

        Schema::table('questions', function (Blueprint $table) {
            $table->dropForeign(['assignment_id']);
            $table->dropForeign(['ca_id']);
            $table->dropColumn(['assignment_id', 'ca_id']);

            // Restore original not-null behaviour
            $table->dropForeign(['exam_id']);
            $table->dropForeign(['subject_id']);
            $table->foreignId('exam_id')->nullable(false)->change();
            $table->foreignId('subject_id')->nullable(false)->change();
            $table->foreign('exam_id')->references('id')->on('exams')->cascadeOnDelete();
            $table->foreign('subject_id')->references('id')->on('subjects')->cascadeOnDelete();
        });
    }
};
