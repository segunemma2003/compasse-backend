<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * InventoryTransactionController writes 'checked_out'/'returned' (and reads
     * them back to gate the return flow), but the original enum only allowed
     * pending/completed/overdue — every checkout/return threw a SQL truncation
     * error.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE inventory_transactions MODIFY status ENUM('pending','completed','overdue','checked_out','returned') NOT NULL DEFAULT 'completed'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE inventory_transactions MODIFY status ENUM('pending','completed','overdue') NOT NULL DEFAULT 'completed'");
    }
};
