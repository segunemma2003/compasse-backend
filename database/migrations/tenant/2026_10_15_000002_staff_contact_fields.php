<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Address, emergency contact and next of kin for everyone who works at the school (staff and teachers). */
    public function up(): void
    {
        $cols = [
            'address' => fn (Blueprint $t) => $t->text('address')->nullable(),
            'emergency_contact_name' => fn (Blueprint $t) => $t->string('emergency_contact_name')->nullable(),
            'emergency_contact_phone' => fn (Blueprint $t) => $t->string('emergency_contact_phone', 30)->nullable(),
            'next_of_kin_name' => fn (Blueprint $t) => $t->string('next_of_kin_name')->nullable(),
            'next_of_kin_phone' => fn (Blueprint $t) => $t->string('next_of_kin_phone', 30)->nullable(),
            'next_of_kin_relationship' => fn (Blueprint $t) => $t->string('next_of_kin_relationship', 60)->nullable(),
        ];
        foreach (['staff', 'teachers'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($cols, $table) {
                foreach ($cols as $name => $add) {
                    if (! Schema::hasColumn($table, $name)) {
                        $add($t);
                    }
                }
                if ($table === 'staff') {
                    if (! Schema::hasColumn('staff', 'date_of_birth')) {
                        $t->date('date_of_birth')->nullable();
                    }
                    if (! Schema::hasColumn('staff', 'gender')) {
                        $t->string('gender', 10)->nullable();
                    }
                }
            });
        }
        // Employment date shouldn't block creating a record when it isn't known yet.
        if (Schema::hasColumn('staff', 'employment_date')) {
            Schema::table('staff', fn (Blueprint $t) => $t->date('employment_date')->nullable()->change());
        }
    }

    public function down(): void
    {
    }
};
