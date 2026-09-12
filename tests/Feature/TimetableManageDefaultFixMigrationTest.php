<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression found while live-verifying the CBT/timetable capability gate
 * on production: demoschool already had a full role_capabilities matrix
 * snapshot saved (from the earlier sub-admin work), captured while
 * 'timetable.manage' had no code consuming it — every teacher-tier role's
 * checkbox defaulted to false and meant nothing. Wiring TimetableController
 * up to that capability today would have silently taken away timetable
 * access those teachers already had. See the migration's own docblock for
 * the full explanation and the fix.
 */
class TimetableManageDefaultFixMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('settings', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->string('key');
            $t->text('value')->nullable();
            $t->timestamps();
        });
    }

    private function runMigration(): void
    {
        (require base_path('database/migrations/tenant/2026_09_12_000006_fix_stale_timetable_manage_default.php'))->up();
    }

    public function test_flips_timetable_manage_true_for_teacher_tier_roles_in_a_stale_pre_cbt_matrix(): void
    {
        $id = DB::table('settings')->insertGetId([
            'school_id' => 1,
            'key'       => 'role_capabilities',
            'value'     => json_encode([
                'admin'   => ['timetable.manage' => true],
                'teacher' => ['timetable.manage' => false, 'result.manage' => true],
                'hod'     => ['timetable.manage' => false],
                'security'=> ['timetable.manage' => false], // not teacher-tier — left alone
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigration();

        $matrix = json_decode(DB::table('settings')->find($id)->value, true);

        $this->assertTrue($matrix['teacher']['timetable.manage']);
        $this->assertTrue($matrix['hod']['timetable.manage']);
        $this->assertTrue($matrix['teacher']['result.manage'], 'unrelated keys must be untouched');
        $this->assertFalse($matrix['security']['timetable.manage'], 'non-teacher-tier roles are not touched');
        $this->assertTrue($matrix['admin']['timetable.manage'], 'already-true values are untouched');
    }

    public function test_leaves_a_matrix_saved_after_cbt_manage_shipped_untouched(): void
    {
        $id = DB::table('settings')->insertGetId([
            'school_id' => 1,
            'key'       => 'role_capabilities',
            'value'     => json_encode([
                'teacher' => ['timetable.manage' => false, 'cbt.manage' => true],
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigration();

        $matrix = json_decode(DB::table('settings')->find($id)->value, true);

        $this->assertFalse(
            $matrix['teacher']['timetable.manage'],
            'a matrix saved once cbt.manage existed means the checkbox was real — a deliberate false must survive'
        );
    }
}
