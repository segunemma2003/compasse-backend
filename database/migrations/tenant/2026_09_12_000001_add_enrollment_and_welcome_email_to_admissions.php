<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes two gaps reported by schools actually using admissions:
 *   - approving an applicant did nothing but flip a status column and send
 *     an email — no Student record was ever created, so "approved" families
 *     had to be re-typed by hand into Enroll Student. student_id here lets
 *     AdmissionController record the conversion once it happens, and makes
 *     re-approving an already-converted applicant a safe no-op instead of
 *     creating a duplicate student.
 *   - there was no way for a school to write their own admission decision
 *     email — it was one hard-coded sentence for every school. These two
 *     nullable columns are an editable template (with a sensible built-in
 *     default when left blank); AdmissionController appends the actual
 *     login credentials to whatever the school writes, so a customised
 *     message can never accidentally omit them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->foreignId('student_id')->nullable()->after('class_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('admission_cycles', function (Blueprint $table) {
            $table->string('welcome_email_subject')->nullable()->after('description');
            $table->text('welcome_email_body')->nullable()->after('welcome_email_subject');
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('student_id');
        });

        Schema::table('admission_cycles', function (Blueprint $table) {
            $table->dropColumn(['welcome_email_subject', 'welcome_email_body']);
        });
    }
};
