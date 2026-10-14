<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Role columns were ENUMs, so every role added later (care_assistant,
     * sen_facilitator, sen_therapist, counsellor, …) was rejected with
     * "Data truncated for column 'role'". Roles are validated in the app;
     * store them as plain strings.
     */
    public function up(): void
    {
        $columns = ['users' => 'student', 'staff' => 'staff', 'job_openings' => null, 'recruitment_jobs' => null, 'job_postings' => null];
        foreach ($columns as $table => $default) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'role')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($default) {
                $col = $t->string('role', 50);
                $default === null ? $col->nullable()->change() : $col->default($default)->change();
            });
        }
    }

    public function down(): void
    {
        // Not reverted: narrowing back to an ENUM would fail on rows using newer roles.
    }
};
