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
        Schema::create('results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('exam_id');
            $table->unsignedBigInteger('subject_id');
            $table->decimal('score', 5, 2)->nullable();
            $table->decimal('total_marks', 5, 2)->default(100);
            $table->string('grade')->nullable();
            $table->text('remarks')->nullable();
            $table->enum('status', ['pending', 'published'])->default('pending');
            $table->timestamps();
            
            $table->foreign('student_id')->references('id')->on('students')->onDelete('cascade');
            $table->foreign('exam_id')->references('id')->on('exams')->onDelete('cascade');
            $table->foreign('subject_id')->references('id')->on('subjects')->onDelete('cascade');
            
            $table->unique(['student_id', 'exam_id', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('results');
    }
};
