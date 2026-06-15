<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_results')) {
            return;
        }

        if (! Schema::hasColumn('student_results', 'result_type')) {
            Schema::table('student_results', function (Blueprint $table) {
                $table->enum('result_type', ['mid_term', 'end_term'])
                    ->default('end_term')
                    ->after('academic_year_id');
            });
        }

        Schema::table('student_results', function (Blueprint $table) {
            try {
                $table->dropUnique('result_unique');
            } catch (\Throwable) {
                // Index may already be renamed or absent on some tenants.
            }
        });

        Schema::table('student_results', function (Blueprint $table) {
            if (! $this->hasIndex('student_results', 'student_results_term_type_unique')) {
                $table->unique(
                    ['student_id', 'term_id', 'academic_year_id', 'result_type'],
                    'student_results_term_type_unique'
                );
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('student_results')) {
            return;
        }

        Schema::table('student_results', function (Blueprint $table) {
            try {
                $table->dropUnique('student_results_term_type_unique');
            } catch (\Throwable) {
            }
        });

        if (Schema::hasColumn('student_results', 'result_type')) {
            Schema::table('student_results', function (Blueprint $table) {
                $table->dropColumn('result_type');
            });
        }

        Schema::table('student_results', function (Blueprint $table) {
            if (! $this->hasIndex('student_results', 'result_unique')) {
                $table->unique(['student_id', 'term_id', 'academic_year_id'], 'result_unique');
            }
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = Schema::getConnection()->select(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
            [$indexName]
        );

        return ! empty($indexes);
    }
};
