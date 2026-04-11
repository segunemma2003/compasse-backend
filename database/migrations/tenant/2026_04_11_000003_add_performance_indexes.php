<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Add missing performance indexes across the tenant schema.
 *
 * Approach: each index is guarded by a hasIndex() check so the migration is
 * safe to re-run and won't fail when deployed to tenants that already have
 * some of these indexes from a previous migration.
 *
 * Impact summary
 * ──────────────
 * student_results
 *   idx_sr_class_term_year  → ResultController::generateResults(), calculatePositions()
 *   idx_sr_student          → student portal "my results" lookups
 *   idx_sr_status           → publishResults(), result listing filters
 *
 * subject_results
 *   idx_subr_subject        → calculateSubjectPositions() PARTITION BY subject_id
 *   idx_subr_result_subject → already covered by unique; explicit for EXPLAIN visibility
 *
 * continuous_assessments
 *   idx_ca_term_year_class  → bulk CA pre-load JOIN in generateResults()
 *
 * attendances
 *   idx_att_morphs_date     → attendance queries filtered by student + date range
 *
 * students
 *   idx_stu_class_status    → Student listing + promotion queries
 *   idx_stu_school_class    → school-scoped class roster
 *
 * promotions
 *   idx_promo_from_year     → bulk promote FROM a class in an academic year
 */
return new class extends Migration
{
    private function indexExists(string $table, string $index): bool
    {
        $dbName = DB::getDatabaseName();
        return DB::table('information_schema.statistics')
            ->where('table_schema', $dbName)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    public function up(): void
    {
        // ── student_results ───────────────────────────────────────────────────
        Schema::table('student_results', function (Blueprint $table) {
            // Composite covering index for the most common filter pattern:
            // WHERE class_id = ? AND term_id = ? AND academic_year_id = ?
            // (the existing idx only covers class_id + term_id)
            if (!$this->indexExists('student_results', 'idx_sr_class_term_year')) {
                $table->index(
                    ['class_id', 'term_id', 'academic_year_id'],
                    'idx_sr_class_term_year'
                );
            }

            // Fast lookup for the student portal (/my-results)
            if (!$this->indexExists('student_results', 'idx_sr_student')) {
                $table->index('student_id', 'idx_sr_student');
            }

            // Filtering by publication status
            if (!$this->indexExists('student_results', 'idx_sr_status')) {
                $table->index('status', 'idx_sr_status');
            }
        });

        // ── subject_results ───────────────────────────────────────────────────
        Schema::table('subject_results', function (Blueprint $table) {
            // RANK() OVER (PARTITION BY subject_id) in calculateSubjectPositions()
            if (!$this->indexExists('subject_results', 'idx_subr_subject')) {
                $table->index('subject_id', 'idx_subr_subject');
            }
        });

        // ── continuous_assessments ────────────────────────────────────────────
        Schema::table('continuous_assessments', function (Blueprint $table) {
            // Bulk CA pre-load in generateResults():
            //   WHERE term_id = ? AND academic_year_id = ? [AND class_id IN (?)]
            // (existing idx covers subject_id + class_id + term_id; we add academic_year_id)
            if (!$this->indexExists('continuous_assessments', 'idx_ca_term_year_class')) {
                $table->index(
                    ['term_id', 'academic_year_id', 'class_id'],
                    'idx_ca_term_year_class'
                );
            }
        });

        // ── attendances ───────────────────────────────────────────────────────
        Schema::table('attendances', function (Blueprint $table) {
            // Attendance queries filter by (type + id + date range).
            // morphs() creates attendanceable_type + attendanceable_id + a composite index
            // named 'attendances_attendanceable_type_attendanceable_id_index'.
            // We add a covering index that includes `date` for date-range scans.
            if (!$this->indexExists('attendances', 'idx_att_morphs_date')) {
                $table->index(
                    ['attendanceable_type', 'attendanceable_id', 'date'],
                    'idx_att_morphs_date'
                );
            }
        });

        // ── students ──────────────────────────────────────────────────────────
        Schema::table('students', function (Blueprint $table) {
            // bulkPromote / graduateStudents: WHERE class_id = ? AND status = 'active'
            if (!$this->indexExists('students', 'idx_stu_class_status')) {
                $table->index(['class_id', 'status'], 'idx_stu_class_status');
            }

            // School-scoped class roster (admin listing)
            if (!$this->indexExists('students', 'idx_stu_school_class')) {
                $table->index(['school_id', 'class_id'], 'idx_stu_school_class');
            }
        });

        // ── promotions ────────────────────────────────────────────────────────
        Schema::table('promotions', function (Blueprint $table) {
            // bulkPromote queries look up existing promotions for a from_class + year
            if (!$this->indexExists('promotions', 'idx_promo_from_year')) {
                $table->index(
                    ['from_class_id', 'academic_year_id'],
                    'idx_promo_from_year'
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_results', function (Blueprint $table) {
            $table->dropIndexIfExists('idx_sr_class_term_year');
            $table->dropIndexIfExists('idx_sr_student');
            $table->dropIndexIfExists('idx_sr_status');
        });

        Schema::table('subject_results', function (Blueprint $table) {
            $table->dropIndexIfExists('idx_subr_subject');
        });

        Schema::table('continuous_assessments', function (Blueprint $table) {
            $table->dropIndexIfExists('idx_ca_term_year_class');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndexIfExists('idx_att_morphs_date');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropIndexIfExists('idx_stu_class_status');
            $table->dropIndexIfExists('idx_stu_school_class');
        });

        Schema::table('promotions', function (Blueprint $table) {
            $table->dropIndexIfExists('idx_promo_from_year');
        });
    }
};
