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
        if (!Schema::hasTable('livestreams')) {
            Schema::create('livestreams', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('teacher_id')->nullable();
                $table->unsignedBigInteger('class_id')->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('meeting_link')->nullable();
                $table->string('meeting_id')->nullable();
                $table->string('meeting_password')->nullable();
                $table->dateTime('start_time');
                $table->dateTime('end_time')->nullable();
                $table->integer('duration_minutes')->default(60);
                $table->enum('status', ['scheduled', 'live', 'ended', 'cancelled'])->default('scheduled');
                $table->string('recording_url')->nullable();
                $table->boolean('attendance_taken')->default(false);
                $table->integer('max_participants')->nullable();
                $table->boolean('is_recurring')->default(false);
                $table->string('recurrence_pattern')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->timestamps();
                
                $table->index(['school_id', 'status']);
                $table->index(['teacher_id']);
                $table->index(['class_id']);
                $table->index(['start_time']);
            });
        }

        if (!Schema::hasTable('livestream_attendances')) {
            Schema::create('livestream_attendances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('livestream_id');
                $table->unsignedBigInteger('user_id');
                $table->dateTime('joined_at')->nullable();
                $table->dateTime('left_at')->nullable();
                $table->integer('duration_minutes')->default(0);
                $table->enum('status', ['present', 'absent', 'late'])->default('present');
                $table->timestamps();
                
                $table->index(['livestream_id', 'user_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('livestream_attendances');
        Schema::dropIfExists('livestreams');
    }
};

