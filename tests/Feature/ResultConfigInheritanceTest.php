<?php

namespace Tests\Feature;

use App\Models\ResultConfiguration;
use App\Support\ReportCardExtras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A class's own result set-up (often a leftover "Custom Section") used to ignore everything set on its
 * section: switched-off class lowest/highest still printed, switched-on date of birth / age did not,
 * and set-ups with no grading scale lost their grades key.
 */
class ResultConfigInheritanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['result_configurations', 'grading_systems', 'classes'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('classes', fn ($t) => [$t->id(), $t->string('name'), $t->string('section_type')->nullable(), $t->timestamps()]);
        Schema::create('grading_systems', fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('name'), $t->boolean('is_default')->default(false),
            $t->decimal('pass_mark', 5, 2)->default(50), $t->json('grade_boundaries')->nullable(), $t->boolean('is_active')->default(true), $t->timestamps()]);
        Schema::create('result_configurations', fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('section_type'), $t->unsignedBigInteger('class_id')->nullable(),
            $t->string('name')->nullable(), $t->unsignedBigInteger('grading_system_id')->nullable(), $t->json('custom_settings')->nullable(), $t->boolean('show_position')->default(true), $t->boolean('is_active')->default(true), $t->timestamps()]);

        DB::table('classes')->insert(['id' => 5, 'name' => 'TODDLER TWO', 'section_type' => 'nursery']);
        DB::table('grading_systems')->insert(['id' => 1, 'school_id' => 1, 'name' => 'School grading', 'is_default' => true]);
        DB::table('result_configurations')->insert([
            ['id' => 1, 'school_id' => 1, 'section_type' => 'nursery', 'class_id' => null, 'name' => 'Nursery', 'grading_system_id' => null,
                'custom_settings' => json_encode(['report' => ['show_class_low' => false, 'show_class_high' => false, 'show_dob' => true, 'show_age' => true]]), 'show_position' => false, 'is_active' => true],
            ['id' => 8, 'school_id' => 1, 'section_type' => 'nursery', 'class_id' => 5, 'name' => 'Toddler Two', 'grading_system_id' => null,
                'custom_settings' => null, 'show_position' => true, 'is_active' => true],
        ]);
    }

    public function test_a_class_setup_inherits_its_sections_report_choices_and_grading_scale(): void
    {
        $config = ResultConfiguration::resolveFor(1, 'nursery', 5);
        $this->assertSame(8, $config->id, 'the class set-up still applies');

        $s = ReportCardExtras::settings($config);
        $this->assertFalse($s['show_class_low']);
        $this->assertFalse($s['show_class_high']);
        $this->assertTrue($s['show_dob']);
        $this->assertTrue($s['show_age']);
        $this->assertSame(1, (int) $config->grading_system_id, 'falls back to the school default scale');
        $this->assertSame('School grading', $config->gradingSystem?->name);
    }

    public function test_the_class_setups_own_choices_win(): void
    {
        DB::table('result_configurations')->where('id', 8)->update(['custom_settings' => json_encode(['report' => ['show_class_low' => true]])]);

        $s = ReportCardExtras::settings(ResultConfiguration::resolveFor(1, 'nursery', 5));
        $this->assertTrue($s['show_class_low']);
        $this->assertFalse($s['show_class_high']);
    }

    public function test_a_leftover_class_setup_from_another_section_no_longer_overrides_the_section(): void
    {
        // TODDLER TWO was moved from "Custom" to Nursery; its old Custom-section set-up (position on)
        // kept winning, and the Nursery page cannot show it, so position could not be switched off.
        DB::table('result_configurations')->where('id', 8)->update(['section_type' => 'custom', 'show_position' => true]);

        $config = ResultConfiguration::resolveFor(1, 'nursery', 5);
        $this->assertSame(1, $config->id);
        $this->assertFalse($config->show_position);
    }

    public function test_a_class_setup_from_another_section_still_applies_when_its_section_has_none(): void
    {
        DB::table('result_configurations')->where('id', 8)->update(['section_type' => 'custom']);
        DB::table('result_configurations')->where('id', 1)->delete();

        $this->assertSame(8, ResultConfiguration::resolveFor(1, 'nursery', 5)->id);
    }
}
