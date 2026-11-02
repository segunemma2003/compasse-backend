<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SEN weekly reports: one per child, per week, per therapist / facilitator. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sen_weekly_reports')) {
            return;
        }
        Schema::create('sen_weekly_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('student_id')->index();
            $t->date('week_start');                        // the Monday of the week
            $t->unsignedBigInteger('author_id');
            $t->string('author_role', 40)->nullable();
            $t->text('goals')->nullable();                 // goals / targets worked on
            $t->text('progress')->nullable();              // what the child achieved
            $t->text('strategies')->nullable();            // strategies and activities used
            $t->text('mood_behaviour')->nullable();
            $t->text('next_steps')->nullable();
            $t->unsignedTinyInteger('rating')->nullable(); // overall progress 1–5
            $t->unsignedSmallInteger('sessions')->nullable();
            $t->timestamps();
            $t->unique(['student_id', 'week_start', 'author_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sen_weekly_reports');
    }
};
