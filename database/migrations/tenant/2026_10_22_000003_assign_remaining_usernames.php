<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Accounts the first pass skipped (student logins whose student record is gone). */
    public function up(): void
    {
        \App\Support\Usernames::assignMissing();
    }

    public function down(): void
    {
    }
};
