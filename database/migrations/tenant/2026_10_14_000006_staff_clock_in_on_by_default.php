<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Self clock-in used to default to off for every staff role, and saving Role Access
     * stored that default — so teachers got "no permission" when clocking in. Turn it on
     * for staff roles in saved matrices (nobody could have chosen "off" deliberately: it
     * was never on to begin with).
     */
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }
        $staff = ['hod', 'year_tutor', 'class_teacher', 'subject_teacher', 'teacher', 'accountant', 'librarian', 'nurse',
            'care_assistant', 'sen_facilitator', 'sen_therapist', 'counsellor', 'housemaster', 'security', 'staff', 'driver', 'caterer', 'cleaner'];
        foreach (DB::table('settings')->where('key', 'role_capabilities')->get(['id', 'value']) as $row) {
            $matrix = json_decode((string) $row->value, true);
            if (! is_array($matrix)) {
                continue;
            }
            foreach ($staff as $role) {
                if (isset($matrix[$role]) && is_array($matrix[$role])) {
                    $matrix[$role]['attendance.self_clock_in'] = true;
                }
            }
            DB::table('settings')->where('id', $row->id)->update(['value' => json_encode($matrix), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
    }
};
