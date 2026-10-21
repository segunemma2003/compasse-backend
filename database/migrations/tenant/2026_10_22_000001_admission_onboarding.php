<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admission → onboarding:
     *  - approving an applicant now only offers a place (admission letter emailed); "enrolled" is the
     *    later step (after payment) that creates the student — so applicants.status gains "enrolled"
     *    (and becomes a plain string, so new stages never need an ENUM change again);
     *  - onboarding_steps: the school's own sequence of emails to new families (welcome, admission
     *    confirmed + starter pack, meet your teacher, first-day reminder, how was the first week);
     *  - onboarding_documents: handbook, prospectus, … attached to those emails;
     *  - onboarding_sends: which email went to which family, so none is sent twice.
     */
    public function up(): void
    {
        if (Schema::hasTable('applicants')) {
            Schema::table('applicants', function (Blueprint $t) {
                $t->string('status', 30)->default('submitted')->change();
            });
            Schema::table('applicants', function (Blueprint $t) {
                foreach (['offered_at', 'enrolled_at', 'payment_confirmed_at'] as $c) {
                    if (! Schema::hasColumn('applicants', $c)) {
                        $t->timestamp($c)->nullable();
                    }
                }
                if (! Schema::hasColumn('applicants', 'resumption_date')) {
                    $t->date('resumption_date')->nullable();   // overrides the cycle's
                }
            });
            // Approved under the old flow already became students: that is "enrolled" now.
            \Illuminate\Support\Facades\DB::table('applicants')->where('status', 'approved')->whereNotNull('student_id')
                ->update(['status' => 'enrolled', 'enrolled_at' => \Illuminate\Support\Facades\DB::raw('reviewed_at')]);
        }
        if (Schema::hasTable('admission_cycles') && ! Schema::hasColumn('admission_cycles', 'resumption_date')) {
            Schema::table('admission_cycles', fn (Blueprint $t) => $t->date('resumption_date')->nullable());
        }

        if (! Schema::hasTable('onboarding_steps')) {
            Schema::create('onboarding_steps', function (Blueprint $t) {
                $t->id();
                $t->string('key', 50)->nullable();          // the built-in ones: welcome, confirmed, meet_teacher, first_day, first_week
                $t->string('name');
                // application_received | admission_offered | enrolled | before_resumption | after_resumption
                $t->string('trigger', 30);
                $t->integer('offset_days')->default(0);     // days before / after resumption
                $t->string('subject');
                $t->text('body');
                $t->json('document_ids')->nullable();       // attachments
                $t->boolean('attach_letter')->default(false);
                $t->boolean('enabled')->default(true);
                $t->integer('sort')->default(0);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('onboarding_documents')) {
            Schema::create('onboarding_documents', function (Blueprint $t) {
                $t->id();
                $t->string('title');
                $t->string('kind', 30)->default('other');   // handbook | prospectus | other
                $t->string('path');
                $t->string('file_name');
                $t->string('mime', 100)->nullable();
                $t->unsignedBigInteger('size')->default(0);
                $t->unsignedBigInteger('uploaded_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('onboarding_sends')) {
            Schema::create('onboarding_sends', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('applicant_id')->index();
                $t->unsignedBigInteger('step_id')->index();
                $t->string('sent_to')->nullable();
                $t->string('status', 20)->default('sent');  // sent | skipped
                $t->unsignedBigInteger('sent_by')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->unique(['applicant_id', 'step_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_sends');
        Schema::dropIfExists('onboarding_documents');
        Schema::dropIfExists('onboarding_steps');
    }
};
