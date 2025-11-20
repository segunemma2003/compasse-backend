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
        Schema::create('answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained()->onDelete('cascade');
            $table->foreignId('question_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->constrained()->onDelete('cascade');
            $table->text('answer_text')->nullable();
            $table->json('answer_data')->nullable();
            $table->boolean('is_correct')->default(false);
            $table->decimal('marks_obtained', 8, 2)->default(0);
            $table->integer('time_taken_seconds')->default(0);
            $table->timestamps();

            $table->unique(['exam_attempt_id', 'question_id']);
            $table->index(['student_id', 'is_correct']);
            $table->index(['question_id', 'is_correct']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('answers');
    }
};
