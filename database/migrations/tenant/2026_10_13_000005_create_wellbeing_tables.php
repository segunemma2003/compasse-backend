<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SEN & Wellbeing: the SEN register, support plans, therapy/support/
     * counselling sessions, referrals, the early-years daily care log, and
     * which care assistant covers which class.
     */
    public function up(): void
    {
        if (! Schema::hasTable('sen_profiles')) {
            Schema::create('sen_profiles', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('student_id')->unique();
                $t->json('needs')->nullable();                  // e.g. ["Autism", "Speech & language"]
                $t->string('support_level', 30)->default('monitoring'); // monitoring | school_support | specialist
                $t->text('summary')->nullable();
                $t->text('strategies')->nullable();             // what helps — shown to the child's teachers
                $t->json('assigned_user_ids')->nullable();      // SEN facilitators / therapists on the caseload
                $t->boolean('active')->default(true);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('support_plans')) {
            Schema::create('support_plans', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('student_id')->index();
                $t->string('title');
                $t->json('goals')->nullable();                  // [{goal, target, progress (0-100), status}]
                $t->date('start_date')->nullable();
                $t->date('review_date')->nullable();
                $t->string('status', 20)->default('active');    // active | completed
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('wellbeing_sessions')) {
            Schema::create('wellbeing_sessions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('student_id')->index();
                $t->string('kind', 20);                         // therapy | support | counselling
                $t->unsignedBigInteger('staff_user_id')->nullable();
                $t->date('session_date');
                $t->unsignedSmallInteger('duration_minutes')->nullable();
                $t->text('focus')->nullable();
                $t->text('notes')->nullable();                  // counselling notes: counsellors only
                $t->text('next_steps')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('wellbeing_referrals')) {
            Schema::create('wellbeing_referrals', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('student_id')->index();
                $t->string('referred_to', 20);                  // counsellor | sen
                $t->text('reason');
                $t->string('urgency', 10)->default('normal');   // normal | urgent
                $t->string('status', 20)->default('open');      // open | accepted | closed
                $t->unsignedBigInteger('referred_by')->nullable();
                $t->unsignedBigInteger('assigned_to')->nullable();
                $t->text('outcome')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('care_logs')) {
            Schema::create('care_logs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('student_id')->index();
                $t->string('type', 20);                         // meal | nap | nappy | toilet | medication | incident | activity | note
                $t->text('details')->nullable();
                $t->timestamp('logged_at');
                $t->unsignedBigInteger('logged_by')->nullable();
                $t->boolean('shared_with_parent')->default(true);
                $t->timestamps();
                $t->index(['student_id', 'logged_at']);
            });
        }

        if (! Schema::hasTable('care_assignments')) {
            Schema::create('care_assignments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();     // the care assistant
                $t->unsignedBigInteger('class_id');
                $t->timestamps();
                $t->unique(['user_id', 'class_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['care_assignments', 'care_logs', 'wellbeing_referrals', 'wellbeing_sessions', 'support_plans', 'sen_profiles'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
