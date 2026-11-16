<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A personal clock-in PIN for everyone: scan the school's QR code (or use the gate kiosk), type the
 * PIN, done — no username and password. Looked up by a keyed hash; kept encrypted so the school
 * can print each person's PIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'clock_pin_hash')) {
                $t->string('clock_pin_hash', 64)->nullable()->unique();
            }
            if (! Schema::hasColumn('users', 'clock_pin')) {
                $t->text('clock_pin')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['clock_pin_hash', 'clock_pin']));
    }
};
