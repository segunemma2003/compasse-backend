<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * student_results.total_score was decimal(5,2) — at most 999.99. A pupil's total is the sum
 * of every subject (each out of 100), so a class with 11+ subjects and good scores overflowed
 * it and "Generate Results" failed with a server error (Year One has 16 subjects).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_results') || DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement('ALTER TABLE student_results MODIFY total_score DECIMAL(10,2) NOT NULL DEFAULT 0, MODIFY average_score DECIMAL(8,2) NOT NULL DEFAULT 0');
        if (Schema::hasTable('subject_results') && Schema::hasColumn('subject_results', 'total_score')) {
            DB::statement('ALTER TABLE subject_results MODIFY total_score DECIMAL(8,2) NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
    }
};
