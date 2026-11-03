<?php

use App\Http\Controllers\ClassController;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Classes created without a level were all filed under the "custom" section, so they read an
 * empty "Custom Section" result set-up instead of the Nursery / Primary set-ups the school had
 * configured (no preschool learning areas; report-card switches with no effect). Re-file them by
 * name — only where the school's "custom" section set-up is unconfigured (no report settings, no
 * learning areas) and a set-up exists for the guessed section.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('classes') || ! Schema::hasTable('result_configurations')) {
            return;
        }
        $custom = DB::table('result_configurations')->where('section_type', 'custom')->whereNull('class_id')->first();
        if ($custom) {
            $settings = json_decode((string) $custom->custom_settings, true) ?: [];
            $areas = Schema::hasTable('result_domains') ? DB::table('result_domains')->where('result_configuration_id', $custom->id)->count() : 0;
            if (! empty($settings['report']) || $areas > 0) {
                return; // the school really uses its custom section
            }
        }
        $sections = DB::table('result_configurations')->whereNull('class_id')->where('is_active', true)->pluck('section_type')->unique()->all();
        foreach (DB::table('classes')->where(fn ($q) => $q->where('section_type', 'custom')->orWhereNull('section_type'))->get(['id', 'name', 'level']) as $c) {
            $guess = ClassController::sectionTypeFromName((string) $c->name) ?? ClassController::sectionTypeFromName((string) $c->level);
            if ($guess && in_array($guess, $sections, true)) {
                DB::table('classes')->where('id', $c->id)->update(['section_type' => $guess, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
    }
};
