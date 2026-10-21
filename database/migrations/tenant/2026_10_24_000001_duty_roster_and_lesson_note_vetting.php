<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Weekly duty roster (the teacher on duty clocks other staff in and out), each teacher's line
     * manager (who vets their lesson notes), and lesson-note reviewer / sharing with a class.
     */
    public function up(): void
    {
        if (! Schema::hasTable('staff_duties')) {
            Schema::create('staff_duties', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->date('week_start')->index();                    // Monday of the week
                $t->string('duty', 100)->default('Teacher on duty');
                $t->string('notes')->nullable();
                $t->unsignedBigInteger('assigned_by')->nullable();
                $t->timestamps();
                $t->unique(['user_id', 'week_start']);
            });
        }
        if (Schema::hasTable('teachers') && ! Schema::hasColumn('teachers', 'line_manager_id')) {
            Schema::table('teachers', function (Blueprint $t) {
                $t->unsignedBigInteger('line_manager_id')->nullable();   // users.id
            });
        }
        if (Schema::hasTable('lesson_notes') && ! Schema::hasColumn('lesson_notes', 'reviewer_id')) {
            Schema::table('lesson_notes', function (Blueprint $t) {
                $t->unsignedBigInteger('reviewer_id')->nullable()->index();   // HOD / line manager it was sent to
                $t->unsignedBigInteger('shared_class_id')->nullable()->index();
                $t->timestamp('share_at')->nullable();                     // students see it from this time
                $t->unsignedBigInteger('shared_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_duties');
        if (Schema::hasColumn('teachers', 'line_manager_id')) {
            Schema::table('teachers', fn (Blueprint $t) => $t->dropColumn('line_manager_id'));
        }
        if (Schema::hasColumn('lesson_notes', 'reviewer_id')) {
            Schema::table('lesson_notes', fn (Blueprint $t) => $t->dropColumn(['reviewer_id', 'shared_class_id', 'share_at', 'shared_by']));
        }
    }
};
