<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** More than one phone number for everyone who works at the school (comma-separated). */
    public function up(): void
    {
        foreach (['staff', 'teachers'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'other_phones')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('other_phones', 255)->nullable());
            }
        }
    }

    public function down(): void
    {
    }
};
