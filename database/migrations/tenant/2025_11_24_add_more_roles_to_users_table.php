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
        // Use raw SQL to modify the ENUM column to add more roles
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to original ENUM values
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
            'super_admin',
            'school_admin',
            'teacher',
            'student',
            'parent'
        ) NOT NULL DEFAULT 'student'");
    }
};

