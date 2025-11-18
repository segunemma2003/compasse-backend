<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('activity_id')->constrained('sports_activities')->onDelete('cascade');
            $table->string('name');
            $table->enum('gender', ['male', 'female', 'mixed'])->default('mixed');
            $table->string('age_group')->nullable();
            $table->foreignId('captain_id')->nullable()->constrained('students')->onDelete('set null');
            $table->foreignId('coach_id')->nullable()->constrained('teachers')->onDelete('set null');
            $table->timestamps();
            
            $table->index(['activity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_teams');
    }
};
