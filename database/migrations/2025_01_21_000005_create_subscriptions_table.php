<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('plan_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['active', 'cancelled', 'expired', 'suspended'])->default('active');
            $table->datetime('start_date');
            $table->datetime('end_date');
            $table->datetime('trial_end_date')->nullable();
            $table->boolean('is_trial')->default(false);
            $table->boolean('auto_renew')->default(true);
            $table->string('payment_method')->nullable();
            $table->enum('billing_cycle', ['monthly', 'yearly', 'quarterly'])->default('monthly');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->json('features')->nullable();
            $table->json('limits')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index(['plan_id', 'status']);
            $table->index(['status', 'end_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
