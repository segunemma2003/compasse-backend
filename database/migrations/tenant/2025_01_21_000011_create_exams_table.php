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
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('subject_id')->constrained()->onDelete('cascade');
            $table->foreignId('class_id')->nullable()->constrained('classes')->onDelete('cascade');
            $table->foreignId('term_id')->constrained()->onDelete('cascade');
            $table->foreignId('academic_year_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['quiz', 'test', 'exam', 'assignment'])->default('exam');
            $table->integer('duration_minutes');
            $table->decimal('total_marks', 8, 2);
            $table->decimal('passing_marks', 8, 2);
            $table->datetime('start_date');
            $table->datetime('end_date');
            $table->boolean('is_cbt')->default(false);
            $table->json('cbt_settings')->nullable();
            $table->enum('status', ['draft', 'active', 'completed', 'cancelled'])->default('draft');
            $table->foreignId('created_by')->constrained('teachers')->onDelete('cascade');
            $table->timestamps();
            
            $table->index(['school_id', 'status']);
            $table->index(['subject_id', 'status']);
            $table->index(['class_id', 'status']);
            $table->index(['term_id', 'status']);
            $table->index(['is_cbt', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};
