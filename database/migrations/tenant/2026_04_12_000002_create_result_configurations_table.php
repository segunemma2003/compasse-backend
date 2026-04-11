<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Result configuration system.
 *
 * Each school can define how results are calculated and formatted for each
 * SECTION (Nursery / Primary / JSS / SSS / Tertiary).  A class belongs to a
 * section, so its result report automatically follows the right config.
 *
 * Changes:
 *   1. result_configurations  — per-school, per-section settings
 *   2. classes.section_type   — links a class to its result config
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Result Configurations ──────────────────────────────────────────
        Schema::create('result_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();

            /**
             * section_type maps to a school level.
             * nursery        = Crèche / Nursery 1-2 / KG
             * primary        = Primary 1-6 / Grade 1-6
             * junior_secondary = JSS 1-3 / Form 1-3
             * senior_secondary = SSS 1-3 / Form 4-6 / A-Level
             * tertiary       = OND / HND / Degree
             * custom         = Any other label (name field carries the label)
             */
            $table->enum('section_type', [
                'nursery',
                'primary',
                'junior_secondary',
                'senior_secondary',
                'tertiary',
                'custom',
            ])->default('primary');

            $table->string('name');   // e.g. "Nursery Section", "SSS Section"

            // ── Assessment weights ──────────────────────────────────────────
            // The sum of all component weights MUST equal 100.
            // assessment_components stores individual CA sub-scores; their
            // weights should sum to (100 - exam_weight).
            $table->decimal('ca_weight', 5, 2)->default(40.00);      // % of total
            $table->decimal('exam_weight', 5, 2)->default(60.00);     // % of total
            $table->decimal('pass_mark', 5, 2)->default(50.00);       // out of 100

            /**
             * Breakdown of CA components:
             * [
             *   {"name": "1st CA Test", "weight": 10, "max": 10},
             *   {"name": "2nd CA Test", "weight": 10, "max": 10},
             *   {"name": "Assignment",  "weight": 10, "max": 10},
             *   {"name": "Attendance",  "weight": 10, "max": 10}
             * ]
             * Weights here must sum to ca_weight above.
             * If null, teachers just enter a single ca_total up to ca_weight.
             */
            $table->json('assessment_components')->nullable();

            // ── Grade display ───────────────────────────────────────────────
            $table->enum('grade_style', [
                'letters',      // A, B, C, D, E, F
                'percentage',   // Show raw %
                'gpa',          // 4.0/5.0 scale
                'remarks_only', // e.g. Nursery: Excellent / Good / Fair / Needs Improvement
            ])->default('letters');

            // For 'remarks_only' — custom remark bands
            // [{"min":75,"max":100,"remark":"Excellent"},{"min":50,"max":74,"remark":"Good"},...]
            $table->json('remark_bands')->nullable();

            // ── Report card display toggles ─────────────────────────────────
            $table->boolean('show_position')->default(true);
            $table->boolean('show_class_average')->default(true);
            $table->boolean('show_subject_position')->default(true);
            $table->boolean('show_ca_breakdown')->default(true);   // show each CA component or just totals
            $table->boolean('show_psychomotor')->default(true);
            $table->boolean('show_affective')->default(true);
            $table->boolean('show_attendance')->default(true);
            $table->boolean('show_next_term_date')->default(true);

            // ── Comment field labels ────────────────────────────────────────
            // Allows renaming "Class Teacher's Comment" → "Form Tutor's Remark", etc.
            // [{"key":"class_teacher_comment","label":"Class Teacher's Remark","required":true},
            //  {"key":"principal_comment","label":"Principal's Comment","required":false}]
            $table->json('comment_fields')->nullable();

            // ── Grading system link ─────────────────────────────────────────
            // If null, the school's default grading system is used.
            $table->foreignId('grading_system_id')->nullable()->constrained('grading_systems')->nullOnDelete();

            // ── Report card template ────────────────────────────────────────
            $table->enum('report_template', ['basic', 'standard', 'detailed'])->default('standard');

            // ── Extra school-type-specific settings ─────────────────────────
            // Nursery might want {"show_developmental_milestones": true, "show_toilet_training": true}
            $table->json('custom_settings')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One config per school per section type
            $table->unique(['school_id', 'section_type']);
            $table->index(['school_id', 'is_active']);
        });

        // ── 2. Add section_type to classes ───────────────────────────────────
        // Links a class to the result_configuration that governs its reports.
        if (Schema::hasTable('classes') && ! Schema::hasColumn('classes', 'section_type')) {
            Schema::table('classes', function (Blueprint $table) {
                $table->enum('section_type', [
                    'nursery',
                    'primary',
                    'junior_secondary',
                    'senior_secondary',
                    'tertiary',
                    'custom',
                ])->default('primary')->after('status');

                $table->index('section_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('classes') && Schema::hasColumn('classes', 'section_type')) {
            Schema::table('classes', function (Blueprint $table) {
                $table->dropColumn('section_type');
            });
        }
        Schema::dropIfExists('result_configurations');
    }
};
