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
        Schema::create('student_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
            $table->enum('status', ['active', 'inactive', 'completed', 'failed'])->default('active');
            $table->decimal('grade', 5, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            // Unique constraint to prevent duplicate enrollments
            $table->unique(['student_id', 'subject_id']);
            
            // Indexes for faster queries
            $table->index(['student_id', 'status']);
            $table->index(['subject_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_subjects');
    }
};
