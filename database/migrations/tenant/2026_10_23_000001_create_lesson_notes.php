<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Teachers' lesson notes (written, or drafted with the school's AI key), vetted by the head / HOD. */
    public function up(): void
    {
        if (! Schema::hasTable('lesson_notes')) {
            Schema::create('lesson_notes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();          // the teacher
                $t->unsignedBigInteger('class_id')->nullable()->index();
                $t->unsignedBigInteger('subject_id')->nullable()->index();
                $t->unsignedBigInteger('term_id')->nullable();
                $t->unsignedBigInteger('academic_year_id')->nullable();
                $t->unsignedSmallInteger('week')->nullable();
                $t->date('lesson_date')->nullable();
                $t->string('topic');
                $t->string('sub_topic')->nullable();
                $t->unsignedSmallInteger('duration_minutes')->nullable();
                $t->json('content')->nullable();                     // objectives, materials, steps, evaluation…
                $t->string('status', 20)->default('draft');           // draft | submitted | approved | returned
                $t->boolean('ai_generated')->default(false);
                $t->text('review_comment')->nullable();
                $t->unsignedBigInteger('reviewed_by')->nullable();
                $t->timestamp('reviewed_at')->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_notes');
    }
};
