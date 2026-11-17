<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Fees with nothing left to pay showed under "Unpaid": mark them paid (their invoices follow). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fees')) {
            return;
        }
        $ids = DB::table('fees')->whereIn('status', ['pending', 'overdue', 'partial'])
            ->whereRaw('amount - COALESCE(amount_paid, 0) <= 0')->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        DB::table('fees')->whereIn('id', $ids)->update(['status' => 'paid', 'balance' => 0, 'updated_at' => now()]);
        if (class_exists(\App\Support\FeeInvoices::class) && \App\Support\FeeInvoices::ready()) {
            foreach ($ids as $id) {
                \App\Support\FeeInvoices::sync((int) $id);
            }
        }
    }

    public function down(): void
    {
    }
};
