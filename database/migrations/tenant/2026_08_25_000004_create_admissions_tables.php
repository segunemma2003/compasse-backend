<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public admissions pipeline: a school opens an admission cycle for a class,
 * which makes a public registration form available on the school's website
 * (see LandingPageController::publicLandingPage for the sibling public-page
 * pattern this follows). A cycle can optionally require an entrance exam —
 * a small self-contained MCQ/short-answer exam scoped to applicants only,
 * since the main CBT system (Exam/ExamAttempt/ExamSubmission) assumes an
 * already-enrolled student with a student_id and isn't a fit for someone
 * who doesn't have a student record yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_cycles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('name');
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->text('description')->nullable();
            $table->boolean('requires_entrance_exam')->default(false);
            $table->dateTime('opens_at')->nullable();
            $table->dateTime('closes_at')->nullable();
            $table->enum('status', ['draft', 'open', 'closed'])->default('draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
        });

        Schema::create('admission_exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_cycle_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->dateTime('scheduled_start')->nullable();
            $table->dateTime('scheduled_end')->nullable();
            // 'active' is a deliberate admin action (not implied by the schedule
            // window alone) — matches "activate when it will be written".
            $table->enum('status', ['draft', 'scheduled', 'active', 'closed'])->default('draft');
            $table->timestamps();
        });

        Schema::create('admission_exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_exam_id')->constrained()->onDelete('cascade');
            $table->text('question_text');
            $table->enum('type', ['mcq', 'short_answer'])->default('mcq');
            $table->json('options')->nullable(); // mcq: [{key:'A', text:'...'}, ...]
            $table->string('correct_option', 10)->nullable(); // mcq only, auto-graded
            $table->decimal('marks', 6, 2)->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->foreignId('admission_cycle_id')->constrained()->onDelete('cascade');
            $table->uuid('access_token')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('parent_name')->nullable();
            $table->string('parent_phone')->nullable();
            $table->string('parent_email')->nullable();
            $table->string('previous_school')->nullable();
            $table->unsignedBigInteger('class_id'); // class applying for
            $table->enum('status', [
                'submitted', 'exam_invited', 'exam_completed', 'approved', 'rejected', 'waitlisted',
            ])->default('submitted');
            $table->decimal('exam_score', 6, 2)->nullable();
            $table->text('decision_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'admission_cycle_id', 'status']);
        });

        Schema::create('applicant_exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained()->onDelete('cascade');
            $table->foreignId('admission_exam_id')->constrained()->onDelete('cascade');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->decimal('score', 6, 2)->nullable();
            $table->enum('status', ['not_started', 'in_progress', 'submitted', 'graded'])->default('not_started');
            $table->timestamps();

            $table->unique(['applicant_id', 'admission_exam_id']);
        });

        Schema::create('applicant_exam_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('applicant_exam_attempts')->onDelete('cascade');
            $table->foreignId('question_id')->constrained('admission_exam_questions')->onDelete('cascade');
            $table->text('answer_text')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->decimal('marks_awarded', 6, 2)->nullable();
            $table->timestamps();

            $table->unique(['attempt_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_exam_answers');
        Schema::dropIfExists('applicant_exam_attempts');
        Schema::dropIfExists('applicants');
        Schema::dropIfExists('admission_exam_questions');
        Schema::dropIfExists('admission_exams');
        Schema::dropIfExists('admission_cycles');
    }
};
