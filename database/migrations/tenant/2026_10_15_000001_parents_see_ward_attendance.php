<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Parents see (never mark) their own child's attendance by default; it briefly defaulted to off. */
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }
        foreach (DB::table('settings')->where('key', 'role_capabilities')->get(['id', 'value']) as $row) {
            $m = json_decode((string) $row->value, true);
            if (! is_array($m)) {
                continue;
            }
            foreach (['parent', 'guardian'] as $role) {
                if (isset($m[$role]) && is_array($m[$role])) {
                    $m[$role]['parent.attendance'] = true;
                }
            }
            DB::table('settings')->where('id', $row->id)->update(['value' => json_encode($m), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
    }
};
