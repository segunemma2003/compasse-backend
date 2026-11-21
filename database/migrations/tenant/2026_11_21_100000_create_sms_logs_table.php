<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every SMS / WhatsApp message the school sends, with what the provider said: messages were
 * failing for days ("Insufficient credits") with nothing on the school's side to show it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sms_logs')) {
            return;
        }
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20)->default('sms'); // sms | whatsapp
            $table->string('to', 30);
            $table->text('message');
            $table->string('sender_id', 20)->nullable();
            $table->string('provider', 30)->nullable();
            $table->string('status', 20); // sent | failed
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
