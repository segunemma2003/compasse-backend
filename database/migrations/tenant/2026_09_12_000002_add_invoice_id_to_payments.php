<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice::payments() is a plain hasMany(Payment::class) — Laravel's default
 * convention expects payments.invoice_id — but the payments table (from the
 * original fee/payment feature, predating invoices) only ever had fee_id.
 * Every invoice creation crashed on this: creating one succeeds, but
 * building the JSON response reads $invoice->status, which calls
 * isPaid() -> getOutstandingAmount() -> getPaidAmount() ->
 * payments()->sum('amount') -> "Unknown column 'payments.invoice_id'".
 * Confirmed live 2026-09-12: POST /financial/invoices 500'd for every
 * invoice regardless of item count — this was never about the number of
 * line items, that was just the first symptom noticed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('fee_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });
    }
};
