<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transport_trips')) {
            return;
        }

        Schema::table('transport_trips', function (Blueprint $table) {
            if (! Schema::hasColumn('transport_trips', 'last_lat')) {
                $table->decimal('last_lat', 10, 7)->nullable()->after('notes');
                $table->decimal('last_lng', 10, 7)->nullable()->after('last_lat');
                $table->json('location_trace')->nullable()->after('last_lng');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('transport_trips')) {
            return;
        }

        Schema::table('transport_trips', function (Blueprint $table) {
            if (Schema::hasColumn('transport_trips', 'last_lat')) {
                $table->dropColumn(['last_lat', 'last_lng', 'location_trace']);
            }
        });
    }
};
