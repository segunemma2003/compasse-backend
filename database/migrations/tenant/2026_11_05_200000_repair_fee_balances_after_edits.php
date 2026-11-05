<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Editing a fee's breakdown changed its amount but left the old balance, so the balance owed
 * (and the dashboard's "Fees Outstanding") no longer matched the invoice. Recalculate those
 * balances as amount - amount paid and bring the invoices in line.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fees') || ! Schema::hasColumn('fees', 'balance') || ! Schema::hasColumn('fees', 'amount_paid')) {
            return;
        }
        $ids = DB::table('fees')->get(['id', 'amount', 'amount_paid', 'balance'])
            ->filter(fn ($f) => abs((float) $f->balance - max(0, (float) $f->amount - (float) $f->amount_paid)) > 0.009)
            ->pluck('id');
        foreach ($ids as $id) {
            $f = DB::table('fees')->where('id', $id)->first(['amount', 'amount_paid', 'status']);
            $balance = max(0, round((float) $f->amount - (float) $f->amount_paid, 2));
            $status = $f->status;
            if ($status !== 'cancelled') {
                $status = (float) $f->amount > 0 && $balance <= 0 ? 'paid' : ((float) $f->amount_paid > 0 ? 'partial' : ($status === 'paid' ? 'pending' : $status));
            }
            DB::table('fees')->where('id', $id)->update(['balance' => $balance, 'status' => $status, 'updated_at' => now()]);
            try {
                \App\Support\FeeInvoices::sync((int) $id);
            } catch (\Throwable) {
            }
        }
    }

    public function down(): void
    {
    }
};
