<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Checkpoint/competency-based reporting for early-years and nursery classes.
 *
 * Data model:
 *   ResultConfiguration (existing)
 *     └── ResultDomain       (e.g. "COMMUNICATION AND LANGUAGE")
 *           └── ResultStrand (e.g. "LISTENING AND ATTENTION")
 *                 └── ResultIndicator (e.g. "Is able to follow directions…")
 *                       └── StudentIndicatorGrade (grade per student × checkpoint)
 *     └── ResultCheckpoint   (e.g. CP1=Autumn, CP2=Spring, CP3=Summer)
 *
 * Plus vitals (height/weight/attendance/homework) and per-domain comments.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Extend report_template to include 'checkpoint' ─────────────────
        // Switch from enum to varchar so we can add values without ALTER ENUM pain
        if (Schema::hasTable('result_configurations') && Schema::hasColumn('result_configurations', 'report_template')) {
            DB::statement("ALTER TABLE result_configurations MODIFY report_template VARCHAR(30) NOT NULL DEFAULT 'standard'");
        }

        // ── 2. Result Domains (top-level subject areas) ───────────────────────
        if (! Schema::hasTable('result_domains')) {
            Schema::create('result_domains', function (Blueprint $table) {
                $table->id();
                $table->foreignId('result_configuration_id')->constrained('result_configurations')->cascadeOnDelete();
                $table->string('name');                       // "COMMUNICATION AND LANGUAGE"
                $table->string('color', 20)->default('#6b21a8'); // header colour on report card
                $table->unsignedSmallInteger('display_order')->default(0);
                $table->timestamps();

                $table->index(['result_configuration_id', 'display_order']);
            });
        }

        // ── 3. Result Strands (sub-categories within a domain) ────────────────
        if (! Schema::hasTable('result_strands')) {
            Schema::create('result_strands', function (Blueprint $table) {
                $table->id();
                $table->foreignId('result_domain_id')->constrained('result_domains')->cascadeOnDelete();
                $table->string('name');                       // "LISTENING AND ATTENTION"
                $table->unsignedSmallInteger('display_order')->default(0);
                $table->timestamps();

                $table->index(['result_domain_id', 'display_order']);
            });
        }

        // ── 4. Result Indicators (specific competency statements) ─────────────
        if (! Schema::hasTable('result_indicators')) {
            Schema::create('result_indicators', function (Blueprint $table) {
                $table->id();
                $table->foreignId('result_strand_id')->constrained('result_strands')->cascadeOnDelete();
                $table->text('name');                         // "Is able to follow directions…"
                $table->unsignedSmallInteger('display_order')->default(0);
                $table->timestamps();

                $table->index(['result_strand_id', 'display_order']);
            });
        }

        // ── 5. Result Checkpoints (CP1/CP2/CP3 definitions) ──────────────────
        if (! Schema::hasTable('result_checkpoints')) {
            Schema::create('result_checkpoints', function (Blueprint $table) {
                $table->id();
                $table->foreignId('result_configuration_id')->constrained('result_configurations')->cascadeOnDelete();
                $table->string('label', 10);                 // "CP1"
                $table->string('name');                      // "Autumn Term"
                $table->unsignedSmallInteger('display_order')->default(0);
                $table->timestamps();

                $table->unique(['result_configuration_id', 'label']);
                $table->index(['result_configuration_id', 'display_order']);
            });
        }

        // ── 6. Student Indicator Grades ───────────────────────────────────────
        if (! Schema::hasTable('student_indicator_grades')) {
            Schema::create('student_indicator_grades', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('result_indicator_id')->constrained('result_indicators')->cascadeOnDelete();
                $table->foreignId('result_checkpoint_id')->constrained('result_checkpoints')->cascadeOnDelete();
                $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
                $table->foreignId('term_id')->nullable()->constrained('terms')->nullOnDelete();
                $table->string('grade', 10)->nullable();     // "E", "B", "P", "C" or custom
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(
                    ['student_id', 'result_indicator_id', 'result_checkpoint_id', 'academic_year_id'],
                    'sig_student_indicator_checkpoint_year'
                );
                $table->index(['student_id', 'academic_year_id']);
                $table->index(['result_indicator_id', 'result_checkpoint_id']);
            });
        }

        // ── 7. Student Term Vitals (height, weight, attendance, ratings) ──────
        if (! Schema::hasTable('student_term_vitals')) {
            Schema::create('student_term_vitals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
                $table->foreignId('term_id')->nullable()->constrained('terms')->nullOnDelete();

                // Attendance
                $table->unsignedSmallInteger('days_school_opened')->nullable();
                $table->unsignedSmallInteger('days_attended')->nullable();

                // Physical measurements
                $table->decimal('height_beginning', 5, 1)->nullable(); // cm
                $table->decimal('height_end', 5, 1)->nullable();
                $table->decimal('weight_beginning', 5, 2)->nullable(); // kg
                $table->decimal('weight_end', 5, 2)->nullable();

                // Ratings (stored as string keys so admin can define their own options)
                $table->string('homework_rating', 30)->nullable();    // e.g. "Good"
                $table->string('punctuality_rating', 30)->nullable(); // e.g. "Always"

                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['student_id', 'academic_year_id', 'term_id'], 'stv_student_year_term');
                $table->index(['student_id', 'academic_year_id']);
            });
        }

        // ── 8. Student Domain Comments (per-subject teacher comments) ─────────
        if (! Schema::hasTable('student_domain_comments')) {
            Schema::create('student_domain_comments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('result_domain_id')->constrained('result_domains')->cascadeOnDelete();
                $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
                $table->foreignId('term_id')->nullable()->constrained('terms')->nullOnDelete();
                $table->text('comment');
                $table->string('teacher_name')->nullable();           // "MR. MONDAY"
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(
                    ['student_id', 'result_domain_id', 'academic_year_id', 'term_id'],
                    'sdc_student_domain_year_term'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_domain_comments');
        Schema::dropIfExists('student_term_vitals');
        Schema::dropIfExists('student_indicator_grades');
        Schema::dropIfExists('result_checkpoints');
        Schema::dropIfExists('result_indicators');
        Schema::dropIfExists('result_strands');
        Schema::dropIfExists('result_domains');

        if (Schema::hasTable('result_configurations') && Schema::hasColumn('result_configurations', 'report_template')) {
            DB::statement("ALTER TABLE result_configurations MODIFY report_template ENUM('basic','standard','detailed') NOT NULL DEFAULT 'standard'");
        }
    }
};
