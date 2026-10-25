<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Website templates that repeated another were removed (Harbor and Modern ≈ Summit,
 * Vibrant ≈ Crimson, Academic ≈ Prestige): schools on them move to the closest one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }
        foreach (['harbor' => 'summit', 'modern' => 'summit', 'vibrant' => 'crimson', 'academic' => 'prestige'] as $old => $new) {
            DB::table('settings')->where('category', 'landing_page')->where('key', 'template')->where('value', $old)
                ->update(['value' => $new, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
    }
};
