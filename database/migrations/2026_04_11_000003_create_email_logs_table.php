<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('to');
            $table->string('subject');
            $table->enum('status', ['sent', 'failed'])->default('sent');
            $table->text('error')->nullable();
            $table->string('school_id')->nullable();
            $table->string('type')->nullable(); // welcome, subscription_reminder, manual, etc.
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('school_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
