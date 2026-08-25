<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a signature belong to a specific teacher rather than only a role.
 * Report cards previously showed one shared "class teacher" signature image
 * for the whole school, regardless of which teacher actually teaches the
 * class — this lets each teacher have their own, resolved per-class at
 * render time (see SchoolSignature::resolveForReportCard).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('school_signatures') && ! Schema::hasColumn('school_signatures', 'teacher_id')) {
            Schema::table('school_signatures', function (Blueprint $table) {
                $table->unsignedBigInteger('teacher_id')->nullable()->after('role');
                $table->index('teacher_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('school_signatures') && Schema::hasColumn('school_signatures', 'teacher_id')) {
            Schema::table('school_signatures', function (Blueprint $table) {
                $table->dropColumn('teacher_id');
            });
        }
    }
};
