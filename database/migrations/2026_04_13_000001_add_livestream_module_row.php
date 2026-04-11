<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ensures the `livestream` module exists for super-admin / plan UIs (ModuleSeeder only runs on fresh seeds).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('modules')) {
            return;
        }

        $exists = DB::table('modules')->where('slug', 'livestream')->exists();
        if ($exists) {
            return;
        }

        DB::table('modules')->insert([
            'name'        => 'Livestream',
            'slug'        => 'livestream',
            'description' => 'Broadcast and join live classes',
            'icon'        => 'video',
            'category'    => 'communication',
            'features'    => json_encode(['broadcast', 'join', 'attendance']),
            'requirements'=> json_encode([]),
            'is_active'   => true,
            'is_core'     => false,
            'sort_order'  => 46,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('modules')) {
            DB::table('modules')->where('slug', 'livestream')->delete();
        }
    }
};
