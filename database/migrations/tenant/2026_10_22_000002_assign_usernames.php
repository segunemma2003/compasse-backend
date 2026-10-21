<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Every existing account gets a username to sign in with (see App\Support\Usernames). */
    public function up(): void
    {
        \App\Support\Usernames::assignMissing();
    }

    public function down(): void
    {
    }
};
