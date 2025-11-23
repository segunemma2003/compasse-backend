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
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->date('due_date')->nullable();
            $table->integer('total_marks')->default(100);
            $table->enum('status', ['draft', 'published', 'closed'])->default('draft');
            $table->text('attachments')->nullable();
            $table->timestamps();
            
            $table->foreign('subject_id')->references('id')->on('subjects')->onDelete('cascade');
            $table->foreign('class_id')->references('id')->on('classes')->onDelete('cascade');
            $table->foreign('teacher_id')->references('id')->on('teachers')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
