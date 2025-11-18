<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
            $table->foreignId('class_id')->nullable()->constrained('classes')->onDelete('cascade');
            $table->foreignId('term_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('academic_year_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('assessment_type')->nullable(); // exam, quiz, assignment, etc.
            $table->foreignId('assessment_id')->nullable(); // ID of exam/quiz/assignment
            $table->decimal('score', 8, 2);
            $table->decimal('total_marks', 8, 2);
            $table->string('grade')->nullable(); // A, B, C, D, F
            $table->decimal('percentage', 5, 2);
            $table->text('remarks')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            
            $table->index(['student_id', 'subject_id']);
            $table->index(['class_id', 'term_id']);
            $table->index(['assessment_type', 'assessment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grades');
    }
};
