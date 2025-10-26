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
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->onDelete('cascade');
            $table->foreignId('subject_id')->constrained()->onDelete('cascade');
            $table->text('question_text');
            $table->enum('question_type', ['multiple_choice', 'true_false', 'essay', 'fill_blank', 'matching'])->default('multiple_choice');
            $table->enum('difficulty_level', ['easy', 'medium', 'hard'])->default('medium');
            $table->decimal('marks', 8, 2);
            $table->integer('time_limit_seconds')->nullable();
            $table->json('options')->nullable();
            $table->json('correct_answer');
            $table->text('explanation')->nullable();
            $table->string('media_url')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            
            $table->index(['exam_id', 'status']);
            $table->index(['subject_id', 'status']);
            $table->index(['question_type', 'difficulty_level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
