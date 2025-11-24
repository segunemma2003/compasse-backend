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
        Schema::create('question_banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('subject_id')->constrained()->onDelete('cascade');
            $table->foreignId('class_id')->constrained('classes')->onDelete('cascade');
            $table->foreignId('term_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->onDelete('set null');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            
            // Question details
            $table->enum('question_type', [
                'multiple_choice',
                'true_false',
                'short_answer',
                'essay',
                'fill_in_blank',
                'matching',
                'ordering'
            ]);
            $table->text('question');
            $table->json('options')->nullable(); // For multiple choice, matching, etc.
            $table->json('correct_answer'); // Can be single or multiple
            $table->text('explanation')->nullable();
            $table->string('difficulty')->default('medium'); // easy, medium, hard
            $table->integer('marks')->default(1);
            $table->json('tags')->nullable(); // For categorization
            $table->string('topic')->nullable();
            $table->text('hints')->nullable();
            $table->json('attachments')->nullable(); // Images, files, etc.
            
            // Usage tracking
            $table->integer('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->enum('status', ['active', 'inactive', 'archived'])->default('active');
            
            $table->timestamps();
            
            // Indexes for faster queries
            $table->index(['school_id', 'status']);
            $table->index(['subject_id', 'class_id', 'term_id']);
            $table->index(['question_type', 'difficulty']);
            $table->index(['academic_year_id', 'term_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_banks');
    }
};
