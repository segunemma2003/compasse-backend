<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * AssignmentController validates and tries to save these fields, but they were
     * never added to the assignments table — Eloquent's $fillable guard silently
     * dropped them on every create()/update(), so teachers' chosen assignment_type,
     * submission_type, instructions, term, and academic year were never persisted.
     */
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->foreignId('term_id')->nullable()->after('class_id')->constrained('terms')->onDelete('set null');
            $table->foreignId('academic_year_id')->nullable()->after('term_id')->constrained('academic_years')->onDelete('set null');
            $table->text('instructions')->nullable()->after('description');
            $table->string('assignment_type')->default('homework')->after('total_marks');
            $table->string('submission_type')->default('text')->after('assignment_type');
            $table->integer('max_file_size')->nullable()->after('submission_type');
            $table->json('allowed_file_types')->nullable()->after('max_file_size');
            $table->boolean('is_group_assignment')->default(false)->after('allowed_file_types');
            $table->integer('max_group_size')->nullable()->after('is_group_assignment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropForeign(['term_id']);
            $table->dropForeign(['academic_year_id']);
            $table->dropColumn([
                'term_id', 'academic_year_id', 'instructions', 'assignment_type',
                'submission_type', 'max_file_size', 'allowed_file_types',
                'is_group_assignment', 'max_group_size',
            ]);
        });
    }
};
