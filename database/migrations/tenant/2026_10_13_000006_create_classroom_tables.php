<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Classroom (one per subject — subjects are class-scoped): topics,
     * stream posts (announcements and materials with links/files),
     * comments (public on posts, private between a student and teachers on
     * an assignment), and e-Lab activities with student submissions.
     */
    public function up(): void
    {
        if (! Schema::hasTable('classroom_topics')) {
            Schema::create('classroom_topics', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('subject_id')->index();
                $t->string('name');
                $t->unsignedInteger('position')->default(0);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('classroom_posts')) {
            Schema::create('classroom_posts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('subject_id')->index();
                $t->unsignedBigInteger('topic_id')->nullable();
                $t->string('type', 20);                 // announcement | material
                $t->string('title')->nullable();
                $t->text('body')->nullable();
                $t->json('links')->nullable();          // [{url, label}]
                $t->json('attachments')->nullable();    // [{path, name}]
                $t->boolean('pinned')->default(false);
                $t->unsignedBigInteger('author_id')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('classroom_comments')) {
            Schema::create('classroom_comments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('post_id')->nullable()->index();
                // Private thread about one student's work on an assignment or lab.
                $t->string('work_type', 20)->nullable();  // assignment | lab
                $t->unsignedBigInteger('work_id')->nullable();
                $t->unsignedBigInteger('student_id')->nullable();
                $t->unsignedBigInteger('author_id');
                $t->text('body');
                $t->timestamps();
                $t->index(['work_type', 'work_id', 'student_id']);
            });
        }
        if (! Schema::hasTable('elab_activities')) {
            Schema::create('elab_activities', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('subject_id')->index();
                $t->unsignedBigInteger('topic_id')->nullable();
                $t->string('sim_slug', 100);             // PhET simulation id, e.g. "ph-scale"
                $t->string('title');
                $t->text('instructions')->nullable();
                $t->json('questions')->nullable();       // observation questions students answer
                $t->timestamp('due_at')->nullable();
                $t->unsignedBigInteger('author_id')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('elab_submissions')) {
            Schema::create('elab_submissions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('activity_id')->index();
                $t->unsignedBigInteger('student_id');
                $t->json('answers')->nullable();
                $t->text('observations')->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->decimal('score', 6, 2)->nullable();
                $t->text('feedback')->nullable();
                $t->unsignedBigInteger('graded_by')->nullable();
                $t->timestamps();
                $t->unique(['activity_id', 'student_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['elab_submissions', 'elab_activities', 'classroom_comments', 'classroom_posts', 'classroom_topics'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
