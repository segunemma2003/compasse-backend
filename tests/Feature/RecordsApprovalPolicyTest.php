<?php

namespace Tests\Feature;

use App\Support\RecordsPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Ouch and injury reports wait for the admin / head of school by default; the school chooses per form. */
class RecordsApprovalPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['settings', 'schools'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('schools', fn ($t) => [$t->id(), $t->timestamps()]);
        Schema::create('settings', fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('key'), $t->text('value')->nullable(),
            $t->string('type')->nullable(), $t->string('category')->nullable(), $t->timestamps()]);
        DB::table('schools')->insert(['id' => 1]);
    }

    public function test_ouch_and_head_injury_reports_need_approval_by_default(): void
    {
        $this->assertTrue(RecordsPolicy::needsApproval('ouch_report'));
        $this->assertTrue(RecordsPolicy::needsApproval('head_injury'));
        $this->assertFalse(RecordsPolicy::needsApproval('preschool_diary'));
        $this->assertTrue(RecordsPolicy::all()['approval']['ouch_report']);
    }

    public function test_the_school_chooses_per_form(): void
    {
        RecordsPolicy::save(null, null, ['ouch_report' => false, 'preschool_diary' => true]);

        $this->assertFalse(RecordsPolicy::needsApproval('ouch_report'));
        $this->assertTrue(RecordsPolicy::needsApproval('head_injury'), 'untouched forms keep their setting');
        $this->assertTrue(RecordsPolicy::needsApproval('preschool_diary'));
    }

    public function test_the_one_switch_still_sets_every_form(): void
    {
        RecordsPolicy::save(true, null);
        $this->assertTrue(RecordsPolicy::needsApproval('homework_diary'));

        RecordsPolicy::save(false, null);
        $this->assertFalse(RecordsPolicy::needsApproval('ouch_report'));
        $this->assertFalse(RecordsPolicy::approvalRequired());
    }
}
