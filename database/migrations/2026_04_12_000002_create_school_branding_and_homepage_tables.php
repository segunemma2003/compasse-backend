<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * School Branding, Homepage, and Section Info
 *
 * - Each school can upload a logo (used on homepage, paystubs, receipts, vouchers, results, etc.)
 * - Schools can set homepage content and manage sections with images and info
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── School Branding ───────────────────────────────────────────────
        Schema::create('school_branding', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->unique();
            $table->string('logo_path')->nullable(); // Logo file path
            $table->string('signature_path')->nullable(); // Signature image path
            $table->string('homepage_title')->nullable();
            $table->text('homepage_content')->nullable();
            $table->timestamps();
        });

        // ── School Homepage Sections ──────────────────────────────────────
        Schema::create('school_homepage_sections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('title');
            $table->text('content')->nullable();
            $table->string('image_path')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->foreign('school_id')->references('id')->on('schools')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_homepage_sections');
        Schema::dropIfExists('school_branding');
    }
};
