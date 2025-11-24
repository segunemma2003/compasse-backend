<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->foreignId('academic_year_id')->nullable()->after('class_teacher_id')->constrained('academic_years')->onDelete('cascade');
            $table->foreignId('term_id')->nullable()->after('academic_year_id')->constrained('terms')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropForeign(['academic_year_id']);
            $table->dropForeign(['term_id']);
            $table->dropColumn(['academic_year_id', 'term_id']);
        });
    }
};
