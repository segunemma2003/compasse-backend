<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Practice quizzes on the student portal: quick questions with instant feedback (not part of results). */
    public function up(): void
    {
        if (! Schema::hasTable('practice_quizzes')) {
            Schema::create('practice_quizzes', function (Blueprint $t) {
                $t->id();
                $t->string('title');
                $t->text('description')->nullable();
                $t->unsignedBigInteger('class_id')->nullable()->index();   // null = whole school
                $t->unsignedBigInteger('subject_id')->nullable();
                $t->json('questions');                                     // [{text, options[], correct, explanation}]
                $t->boolean('published')->default(true);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('practice_quiz_attempts')) {
            Schema::create('practice_quiz_attempts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('quiz_id')->index();
                $t->unsignedBigInteger('student_id')->nullable()->index();
                $t->unsignedBigInteger('user_id');
                $t->unsignedSmallInteger('score');
                $t->unsignedSmallInteger('total');
                $t->json('answers')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_quiz_attempts');
        Schema::dropIfExists('practice_quizzes');
    }
};
