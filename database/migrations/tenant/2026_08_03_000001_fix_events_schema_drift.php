<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * EventController + Events UI use event_type/status values that the original
 * tenant migration did not allow — inserts/updates failed with SQL errors.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('events')) {
            return;
        }

        DB::statement("ALTER TABLE events MODIFY event_type ENUM(
            'academic','sports','cultural','ceremony','holiday','meeting','excursion','exam','other'
        ) NOT NULL DEFAULT 'other'");

        DB::statement("ALTER TABLE events MODIFY target_audience ENUM(
            'all','students','teachers','parents','staff'
        ) NOT NULL DEFAULT 'all'");

        DB::statement("ALTER TABLE events MODIFY status ENUM(
            'scheduled','upcoming','ongoing','completed','cancelled'
        ) NOT NULL DEFAULT 'upcoming'");

        // Legacy rows
        DB::table('events')->where('status', 'upcoming')->update(['status' => 'scheduled']);
    }

    public function down(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('events')) {
            return;
        }

        DB::table('events')->where('status', 'scheduled')->update(['status' => 'upcoming']);

        DB::statement("ALTER TABLE events MODIFY event_type ENUM(
            'academic','sports','cultural','ceremony','holiday','meeting','excursion','other'
        ) NOT NULL DEFAULT 'other'");

        DB::statement("ALTER TABLE events MODIFY status ENUM(
            'upcoming','ongoing','completed','cancelled'
        ) NOT NULL DEFAULT 'upcoming'");
    }
};
