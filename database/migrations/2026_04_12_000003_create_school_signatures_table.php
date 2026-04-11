<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digital Signature System
 *
 * - Each school can have multiple digital signatures (e.g., principal, bursar)
 * - Used on paystubs, receipts, vouchers, results, and other documents
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_signatures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('role'); // e.g., principal, bursar, admin
            $table->string('name'); // Name of the signatory
            $table->string('signature_path'); // Path to signature image
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('school_id')->references('id')->on('schools')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_signatures');
    }
};
