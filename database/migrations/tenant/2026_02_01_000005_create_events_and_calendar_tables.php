<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('event_type', ['academic', 'sports', 'cultural', 'ceremony', 'holiday', 'meeting', 'excursion', 'other'])->default('other');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('location')->nullable();
            $table->string('organizer')->nullable();
            $table->enum('target_audience', ['all', 'students', 'teachers', 'parents', 'staff'])->default('all');
            $table->unsignedBigInteger('class_id')->nullable();
            $table->boolean('is_all_day')->default(false);
            $table->enum('status', ['upcoming', 'ongoing', 'completed', 'cancelled'])->default('upcoming');
            $table->unsignedInteger('max_participants')->nullable();
            $table->json('attachments')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'start_date']);
            $table->index(['school_id', 'status']);
        });

        Schema::create('calendars', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('date');
            $table->date('end_date')->nullable();
            $table->enum('type', ['holiday', 'exam', 'event', 'term_start', 'term_end', 'meeting', 'other'])->default('other');
            $table->string('color', 7)->default('#3B82F6');
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_rule')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'date']);
            $table->index(['school_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendars');
        Schema::dropIfExists('events');
    }
};
