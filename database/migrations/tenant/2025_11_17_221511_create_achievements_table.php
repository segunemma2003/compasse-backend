<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->nullable()->constrained('students')->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('type', ['academic', 'sports', 'arts', 'leadership', 'community', 'other'])->default('academic');
            $table->string('category')->nullable();
            $table->date('achievement_date');
            $table->string('awarded_by')->nullable();
            $table->string('certificate_url')->nullable();
            $table->timestamps();
            
            $table->index(['student_id', 'type']);
            $table->index(['school_id', 'achievement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};
