<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original settings migration added a global unique index on `key`.
 * This breaks multi-school tenants: if school A and school B both try to
 * save a `primary_color` setting, the second INSERT fails with a unique violation.
 *
 * Fix: drop the global unique on `key` and replace with a composite unique
 * on (school_id, key) — which is the semantically correct constraint.
 *
 * Also adds a covering index for the landing-page public endpoint query:
 *   WHERE school_id = ? AND category = 'landing_page'
 * which becomes a single index scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Drop the broken global unique
            $table->dropUnique(['key']);

            // Composite unique: one value per (school, key) pair
            $table->unique(['school_id', 'key'], 'settings_school_key_unique');

            // Covering index for landing-page reads:
            // SELECT key, value FROM settings WHERE school_id = ? AND category = ?
            $table->index(['school_id', 'category', 'key'], 'settings_school_category_key_idx');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropIndex('settings_school_category_key_idx');
            $table->dropUnique('settings_school_key_unique');
            $table->unique(['key']);
        });
    }
};
