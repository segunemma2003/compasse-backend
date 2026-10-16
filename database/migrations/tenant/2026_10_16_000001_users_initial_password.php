<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The password the school issued (encrypted, not hashed) so login slips can be
     * reprinted without resetting anyone. Cleared as soon as the person sets their own.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'initial_password')) {
            Schema::table('users', fn (Blueprint $t) => $t->text('initial_password')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'initial_password')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('initial_password'));
        }
    }
};
