<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fingerprint / face clock-in: a passkey on the person's own phone (WebAuthn). The phone checks the
 * finger and signs; the school keeps only the public key — never a fingerprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('passkeys')) {
            return;
        }
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('credential_id', 512)->unique(); // base64url
            $table->text('public_key');                    // PEM
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->string('name', 100)->nullable();       // e.g. "Tecno Spark 10"
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
