<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // housemaster already has its own dashboard, sidebar pages, and API
            // routes (role:...,housemaster middleware groups) but was never a
            // valid value in this enum, so no user could actually be assigned it.
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
                'super_admin',
                'school_admin',
                'teacher',
                'student',
                'parent',
                'guardian',
                'admin',
                'staff',
                'hod',
                'year_tutor',
                'class_teacher',
                'subject_teacher',
                'principal',
                'vice_principal',
                'accountant',
                'librarian',
                'driver',
                'security',
                'cleaner',
                'caterer',
                'nurse',
                'housemaster'
            ) NOT NULL DEFAULT 'student'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
                'super_admin',
                'school_admin',
                'teacher',
                'student',
                'parent',
                'guardian',
                'admin',
                'staff',
                'hod',
                'year_tutor',
                'class_teacher',
                'subject_teacher',
                'principal',
                'vice_principal',
                'accountant',
                'librarian',
                'driver',
                'security',
                'cleaner',
                'caterer',
                'nurse'
            ) NOT NULL DEFAULT 'student'");
        }
    }
};
