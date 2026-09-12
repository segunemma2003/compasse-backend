<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 'timetable.manage' has existed as a capability constant since the
 * sub-admin work earlier this session, but until the CBT/timetable
 * capability-gating change (same day) no route or controller actually
 * consulted it — toggling it in Role Access was a complete no-op. Any
 * school that visited Settings > Role Access and saved the matrix before
 * that gate shipped got a FULL snapshot written to settings.role_capabilities,
 * which baked in whatever buildDefaultMatrix() computed for
 * 'timetable.manage' at the time: false for every teacher-tier role, since
 * DEFAULTS didn't grant it to them yet either. That stored false now has
 * real teeth (TimetableController::store/update/destroy) and would
 * silently take away access those teachers already had via the old,
 * ungated role: middleware — a real regression, not a deliberate choice
 * any school_admin could have made, since the checkbox never did anything
 * before today.
 *
 * Fix: for any settings row saved before this feature existed — reliably
 * identified by the ABSENCE of the 'cbt.manage' key, which shipped in the
 * same change and could not appear in an older snapshot — flip
 * 'timetable.manage' back to true for the teacher-tier roles, matching
 * RoleCapabilityService::DEFAULTS. A row saved after this ships (has
 * 'cbt.manage' present) is left untouched — by then the checkbox is real
 * and any false in it is an actual school_admin decision.
 */
return new class extends Migration
{
    private const TEACHER_TIER_ROLES = ['teacher', 'class_teacher', 'subject_teacher', 'year_tutor', 'hod'];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->where('key', 'role_capabilities')
            ->orderBy('id')
            ->each(function ($row) {
                $matrix = json_decode($row->value, true);
                if (! is_array($matrix)) {
                    return;
                }

                // Already saved after cbt.manage shipped — the checkbox was
                // real when this was saved, leave it alone.
                foreach ($matrix as $caps) {
                    if (is_array($caps) && array_key_exists('cbt.manage', $caps)) {
                        return;
                    }
                }

                $changed = false;
                foreach (self::TEACHER_TIER_ROLES as $role) {
                    if (isset($matrix[$role]) && is_array($matrix[$role]) && ($matrix[$role]['timetable.manage'] ?? null) === false) {
                        $matrix[$role]['timetable.manage'] = true;
                        $changed = true;
                    }
                }

                if ($changed) {
                    DB::table('settings')->where('id', $row->id)->update([
                        'value'      => json_encode($matrix),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Not reversible — we don't know which rows we changed after the
        // fact, and reverting would just reintroduce the regression.
    }
};
