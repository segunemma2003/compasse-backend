<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The shift a member of staff works (e.g. Morning 07:00, Afternoon 13:00) for late arrivals. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'attendance_shift')) {
            Schema::table('users', fn (Blueprint $t) => $t->string('attendance_shift', 40)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'attendance_shift')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('attendance_shift'));
        }
    }
};
