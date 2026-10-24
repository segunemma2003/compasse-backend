<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Each fee gets an invoice (fee_id), with amount paid / balance and a "partial" status; existing fees are invoiced. */
    public function up(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }
        Schema::table('invoices', function (Blueprint $t) {
            if (! Schema::hasColumn('invoices', 'fee_id')) {
                $t->unsignedBigInteger('fee_id')->nullable()->index();
            }
            if (! Schema::hasColumn('invoices', 'amount_paid')) {
                $t->decimal('amount_paid', 12, 2)->default(0);
            }
            if (! Schema::hasColumn('invoices', 'balance')) {
                $t->decimal('balance', 12, 2)->default(0);
            }
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE invoices MODIFY status VARCHAR(20) NOT NULL DEFAULT 'draft'");
            DB::statement('ALTER TABLE invoices MODIFY subtotal DECIMAL(12,2) NOT NULL DEFAULT 0, MODIFY total_amount DECIMAL(12,2) NOT NULL DEFAULT 0, MODIFY discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0');
        }
        \App\Support\FeeInvoices::backfill();
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            foreach (['fee_id', 'amount_paid', 'balance'] as $c) {
                if (Schema::hasColumn('invoices', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
