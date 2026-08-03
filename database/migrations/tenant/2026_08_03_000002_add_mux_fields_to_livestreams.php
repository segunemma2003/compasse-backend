<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('livestreams')) {
            return;
        }

        Schema::table('livestreams', function (Blueprint $table) {
            if (! Schema::hasColumn('livestreams', 'stream_provider')) {
                $table->string('stream_provider', 20)->default('meet')->after('meeting_password');
            }
            if (! Schema::hasColumn('livestreams', 'mux_live_stream_id')) {
                $table->string('mux_live_stream_id')->nullable()->after('stream_provider');
            }
            if (! Schema::hasColumn('livestreams', 'mux_playback_id')) {
                $table->string('mux_playback_id')->nullable()->after('mux_live_stream_id');
            }
            if (! Schema::hasColumn('livestreams', 'mux_stream_key')) {
                $table->text('mux_stream_key')->nullable()->after('mux_playback_id');
            }
            if (! Schema::hasColumn('livestreams', 'mux_rtmp_url')) {
                $table->string('mux_rtmp_url')->nullable()->after('mux_stream_key');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('livestreams')) {
            return;
        }

        Schema::table('livestreams', function (Blueprint $table) {
            foreach (['mux_rtmp_url', 'mux_stream_key', 'mux_playback_id', 'mux_live_stream_id', 'stream_provider'] as $col) {
                if (Schema::hasColumn('livestreams', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
