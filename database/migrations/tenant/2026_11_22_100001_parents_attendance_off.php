<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parents no longer see attendance unless the school switches it on (Role Access → "Parents: see
 * their child's attendance"). Schools that saved Role Access while it was on by default have it
 * saved as on: switch it off once. Any school can switch it back on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }
        DB::table('settings')->where('key', 'role_capabilities')->orderBy('id')->each(function ($row) {
            $matrix = json_decode((string) $row->value, true);
            if (! is_array($matrix)) {
                return;
            }
            $changed = false;
            foreach (['parent', 'guardian'] as $role) {
                if (isset($matrix[$role]) && is_array($matrix[$role]) && ($matrix[$role]['parent.attendance'] ?? false) === true) {
                    $matrix[$role]['parent.attendance'] = false;
                    $changed = true;
                }
            }
            if ($changed) {
                DB::table('settings')->where('id', $row->id)->update(['value' => json_encode($matrix), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // Not reversible: a school switches it back on in Role Access.
    }
};
