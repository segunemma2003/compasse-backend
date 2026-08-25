<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff recruitment pipeline — mirrors the admissions pipeline
 * (2026_08_25_000004_create_admissions_tables.php) but for job openings and
 * candidates instead of classes and applicants. A school opens a job posting,
 * which makes a public application form available on the website; candidates
 * are reviewed and, once hired, "onboarded" — converted into a real staff
 * account (see RecruitmentController::onboard, which reuses StaffController's
 * account-creation logic rather than duplicating it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_openings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('title');
            $table->enum('role', [
                'teacher', 'admin', 'staff', 'accountant', 'librarian',
                'driver', 'security', 'cleaner', 'caterer', 'nurse',
            ]);
            $table->string('department')->nullable();
            $table->text('description')->nullable();
            $table->text('requirements')->nullable();
            $table->dateTime('opens_at')->nullable();
            $table->dateTime('closes_at')->nullable();
            $table->enum('status', ['draft', 'open', 'closed'])->default('draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
        });

        Schema::create('job_applicants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->foreignId('job_opening_id')->constrained()->onDelete('cascade');
            $table->uuid('access_token')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->text('cover_letter')->nullable();
            $table->text('qualifications')->nullable();
            $table->unsignedInteger('years_of_experience')->nullable();
            $table->string('resume_path')->nullable();
            $table->enum('status', [
                'submitted', 'shortlisted', 'offered', 'hired', 'rejected', 'withdrawn',
            ])->default('submitted');
            $table->text('decision_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            // Set once "onboard" actually creates the staff account, so it can
            // only ever happen once per applicant.
            $table->unsignedBigInteger('onboarded_user_id')->nullable();
            $table->dateTime('onboarded_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'job_opening_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_applicants');
        Schema::dropIfExists('job_openings');
    }
};
