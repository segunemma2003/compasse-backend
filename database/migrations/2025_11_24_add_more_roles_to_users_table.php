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
            // MySQL: Use ALTER TABLE MODIFY
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
        } else {
            // SQLite/PostgreSQL: Drop and recreate with correct type
            // For SQLite, ENUM is just a string check constraint, so we can skip this
            // or handle it differently if needed
            Schema::table('users', function (Blueprint $table) {
                // SQLite doesn't have ENUM, it's stored as string
                // No action needed as SQLite accepts any string value
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // Revert to original ENUM values
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
                'super_admin',
                'school_admin',
                'teacher',
                'student',
                'parent'
            ) NOT NULL DEFAULT 'student'");
        }
    }
};

