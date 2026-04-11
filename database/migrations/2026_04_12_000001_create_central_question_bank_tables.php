<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (super-admin-managed) Question Bank.
 *
 * Super admins author questions and organise them by subject, class level,
 * and curriculum type.  Schools subscribe to subject+level combos and can
 * import questions directly into their local exams.
 *
 * Tables (all on the CENTRAL database):
 *   question_bank_subjects       — global subject catalogue
 *   question_bank_questions      — the actual question pool
 *   question_bank_subscriptions  — which tenants have access to which subject+level
 *   question_bank_imports        — tracks when a school copies a question to an exam
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Subjects ──────────────────────────────────────────────────────────
        Schema::create('question_bank_subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name');                       // Mathematics, English Language, …
            $table->string('code')->unique();             // MATH, ENG, PHY …
            $table->text('description')->nullable();
            $table->enum('category', [
                'core',         // Maths, English — every curriculum
                'sciences',     // Physics, Chemistry, Biology
                'arts',         // Literature, Fine Art, Music
                'social',       // History, Government, Economics
                'vocational',   // Technical Drawing, Home Economics
                'languages',    // French, Yoruba, Igbo, Hausa …
                'religious',    // CRS, IRS
                'other',
            ])->default('core');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index(['category', 'status']);
        });

        // ── Questions ─────────────────────────────────────────────────────────
        Schema::create('question_bank_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')
                ->constrained('question_bank_subjects')
                ->cascadeOnDelete();

            // Target audience
            $table->enum('class_level', [
                'nursery',
                'primary',
                'junior_secondary',
                'senior_secondary',
                'tertiary',
            ])->default('senior_secondary');

            // Exam type (e.g., WAEC, NECO, JAMB, MOCK, INTERNAL, etc.)
            $table->string('exam_type')->nullable();

            // Year of the past question
            $table->year('year')->nullable();

            // Optional file attachment (e.g., image, PDF)
            $table->string('attachment_path')->nullable();

            // Curriculum tag — schools can filter for past-question sets
            $table->enum('curriculum_type', [
                'waec',
                'neco',
                'nabteb',
                'common_entrance',
                'jamb',
                'primary_school',
                'cambridge',
                'custom',
            ])->default('waec');

            $table->string('academic_year')->nullable();  // e.g. "2023/2024" for past-question sets
            $table->string('topic')->nullable();
            $table->string('subtopic')->nullable();

            // Question body
            $table->enum('question_type', [
                'multiple_choice',
                'true_false',
                'short_answer',
                'essay',
                'fill_in_blank',
                'matching',
                'ordering',
            ])->default('multiple_choice');

            $table->text('question_text');
            $table->json('options')->nullable();          // [{key:"A",value:"…"}, …]
            $table->json('correct_answer');               // "A" or ["A","C"] or essay model answer
            $table->text('explanation')->nullable();

            $table->enum('difficulty_level', ['easy', 'medium', 'hard'])->default('medium');
            $table->decimal('marks', 5, 2)->default(1);
            $table->string('media_url')->nullable();      // S3 key for image/audio
            $table->json('tags')->nullable();             // free-form tags for search

            // Usage tracking
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();

            // Authorship
            $table->foreignId('created_by')
                ->constrained('users')             // central users table
                ->restrictOnDelete();

            $table->enum('status', ['active', 'inactive', 'archived'])->default('active');
            $table->timestamps();

            $table->index(['subject_id', 'class_level', 'status']);
            $table->index(['curriculum_type', 'class_level', 'status']);
            $table->index(['question_type', 'difficulty_level']);
            $table->index('topic');
        });

        // ── Subscriptions ─────────────────────────────────────────────────────
        Schema::create('question_bank_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');                  // UUID from tenants table
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->foreignId('subject_id')
                ->constrained('question_bank_subjects')
                ->cascadeOnDelete();

            $table->enum('class_level', [
                'nursery', 'primary', 'junior_secondary',
                'senior_secondary', 'tertiary',
            ])->nullable();                               // NULL = all levels for this subject

            $table->enum('curriculum_type', [
                'waec', 'neco', 'nabteb', 'common_entrance',
                'jamb', 'primary_school', 'cambridge', 'custom',
            ])->nullable();                               // NULL = all curriculum types

            $table->enum('status', ['active', 'suspended', 'expired'])->default('active');
            $table->timestamp('subscribed_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();  // NULL = never expires

            $table->unsignedInteger('questions_imported')->default(0);

            $table->timestamps();

            // One subscription row per tenant+subject+level+curriculum combination
            $table->unique(['tenant_id', 'subject_id', 'class_level', 'curriculum_type'], 'qb_sub_unique');
            $table->index(['tenant_id', 'status']);
            $table->index('expires_at');
        });

        // ── Import Log ────────────────────────────────────────────────────────
        Schema::create('question_bank_imports', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->foreignId('question_id')
                ->constrained('question_bank_questions')
                ->cascadeOnDelete();

            // exam_id references the TENANT'S exams table — stored as plain int,
            // no FK since it points to a different DB.
            $table->unsignedBigInteger('exam_id')->nullable();

            $table->timestamp('imported_at')->useCurrent();
            $table->foreignId('imported_by')
                ->constrained('users')             // central users table
                ->restrictOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'question_id']);
            $table->index(['tenant_id', 'exam_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_bank_imports');
        Schema::dropIfExists('question_bank_subscriptions');
        Schema::dropIfExists('question_bank_questions');
        Schema::dropIfExists('question_bank_subjects');
    }
};
