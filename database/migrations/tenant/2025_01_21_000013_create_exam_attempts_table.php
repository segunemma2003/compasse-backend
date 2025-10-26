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
        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->constrained()->onDelete('cascade');
            $table->datetime('started_at');
            $table->datetime('completed_at')->nullable();
            $table->enum('status', ['in_progress', 'completed', 'abandoned', 'time_expired'])->default('in_progress');
            $table->decimal('total_score', 8, 2)->default(0);
            $table->decimal('percentage', 5, 2)->default(0);
            $table->integer('time_taken_minutes')->default(0);
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            
            $table->index(['exam_id', 'student_id']);
            $table->index(['exam_id', 'status']);
            $table->index(['student_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_attempts');
    }
};
