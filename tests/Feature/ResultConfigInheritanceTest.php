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
            $t->string('name')->nullable(), $t->unsignedBigInteger('grading_system_id')->nullable(), $t->json('custom_settings')->nullable(), $t->boolean('is_active')->default(true), $t->timestamps()]);

        DB::table('classes')->insert(['id' => 5, 'name' => 'TODDLER TWO', 'section_type' => 'nursery']);
        DB::table('grading_systems')->insert(['id' => 1, 'school_id' => 1, 'name' => 'School grading', 'is_default' => true]);
        DB::table('result_configurations')->insert([
            ['id' => 1, 'school_id' => 1, 'section_type' => 'nursery', 'class_id' => null, 'name' => 'Nursery', 'grading_system_id' => null,
                'custom_settings' => json_encode(['report' => ['show_class_low' => false, 'show_class_high' => false, 'show_dob' => true, 'show_age' => true]]), 'is_active' => true],
            ['id' => 8, 'school_id' => 1, 'section_type' => 'custom', 'class_id' => 5, 'name' => 'Custom Section', 'grading_system_id' => null,
                'custom_settings' => null, 'is_active' => true],
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
}
