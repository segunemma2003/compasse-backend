<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Picking a "Teacher" on the Subject form (subjects.teacher_id — a "primary
 * teacher" display field) has never written to the separate teacher_subjects
 * pivot table, which is what Controller::accessibleStudentIds() and
 * assertCanManageSubjectResource() actually consult to decide a subject
 * teacher's roster and whether they may record CA scores / manage exams for
 * that subject. A school that only ever used the obvious "Teacher" dropdown
 * on Subjects — rather than the separate, easy-to-miss "Manage Teachers"
 * dialog that does write the pivot — has subject teachers who can see the
 * subject exists but get an empty student list and a 403 the moment they try
 * to record scores, silently, since launch.
 *
 * SubjectController::store()/update() now keep the pivot in sync going
 * forward (see syncPrimaryTeacherAssignment()); this backfills every subject
 * that already has a teacher_id from before that fix, so already-assigned
 * subject teachers get access without a school admin having to re-pick the
 * teacher on every subject to trigger it. Only adds rows — never touches an
 * existing teacher_subjects row (e.g. a deliberately added co-teacher, or one
 * already covering this exact teacher/subject/class), so nothing already
 * granted or already correct is affected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subjects') || ! Schema::hasTable('teacher_subjects')) {
            return;
        }

        DB::table('subjects')
            ->whereNotNull('teacher_id')
            ->orderBy('id')
            ->each(function ($subject) {
                $exists = DB::table('teacher_subjects')
                    ->where('teacher_id', $subject->teacher_id)
                    ->where('subject_id', $subject->id)
                    ->where('class_id', $subject->class_id)
                    ->exists();

                if ($exists) {
                    return;
                }

                DB::table('teacher_subjects')->insert([
                    'teacher_id' => $subject->teacher_id,
                    'subject_id' => $subject->id,
                    'class_id'   => $subject->class_id,
                    'status'     => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        // Not reversible — we don't know which rows we added versus which
        // already existed (e.g. from "Manage Teachers"), and removing them
        // would risk taking away access a school_admin granted deliberately.
    }
};
