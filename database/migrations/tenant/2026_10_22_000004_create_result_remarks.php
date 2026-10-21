<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Report-card comments typed on the Mark Sheet before (or after) results are generated. */
    public function up(): void
    {
        if (! Schema::hasTable('result_remarks')) {
            Schema::create('result_remarks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('student_id');
                $t->unsignedBigInteger('term_id');
                $t->unsignedBigInteger('academic_year_id');
                $t->text('class_teacher_comment')->nullable();
                $t->text('principal_comment')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->unique(['student_id', 'term_id', 'academic_year_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('result_remarks');
    }
};
