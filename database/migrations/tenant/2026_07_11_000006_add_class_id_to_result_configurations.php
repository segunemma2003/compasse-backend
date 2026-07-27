<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * result_configurations was scoped per (school_id, section_type) only — one
 * config per section, shared by every class in that section. This adds an
 * optional class_id so a school can override the section default for a
 * specific class while keeping the section-level config as the fallback.
 *
 * MySQL treats NULL as distinct in unique indexes, so a plain
 * unique(school_id, section_type, class_id) would silently allow multiple
 * section-level (class_id = NULL) rows. A generated column that coalesces
 * NULL to 0 closes that gap so uniqueness is still enforced at the DB level.
 *
 * Uses VIRTUAL rather than STORED: MySQL 8.4 raises a spurious "Cannot add
 * foreign key constraint" (1215) when a STORED generated column depends on
 * a column that carries its own FK — reproduced independent of column
 * order, cascade rules, or referencing/foreign_key_checks state. VIRTUAL
 * is unaffected and still supports a unique index on the computed value.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('result_configurations', ['school_id', 'section_type'], 'unique')) {
            Schema::table('result_configurations', function (Blueprint $table) {
                $table->dropUnique(['school_id', 'section_type']);
            });
        }

        if (! Schema::hasColumn('result_configurations', 'class_id')) {
            Schema::table('result_configurations', function (Blueprint $table) {
                $table->foreignId('class_id')->nullable()->after('section_type')
                    ->constrained('classes')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('result_configurations', 'class_scope_key')) {
            DB::statement('ALTER TABLE result_configurations ADD COLUMN class_scope_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(class_id, 0)) VIRTUAL');
        }

        if (! Schema::hasIndex('result_configurations', 'result_configs_school_section_class_unique', 'unique')) {
            DB::statement('ALTER TABLE result_configurations ADD UNIQUE KEY result_configs_school_section_class_unique (school_id, section_type, class_scope_key)');
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE result_configurations DROP INDEX result_configs_school_section_class_unique');
        DB::statement('ALTER TABLE result_configurations DROP COLUMN class_scope_key');

        Schema::table('result_configurations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_id');
        });

        Schema::table('result_configurations', function (Blueprint $table) {
            $table->unique(['school_id', 'section_type']);
        });
    }
};
