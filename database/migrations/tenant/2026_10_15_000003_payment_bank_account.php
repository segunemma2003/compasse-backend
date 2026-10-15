<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** For transfers: which of the school's bank accounts the money went into (kept as text too, in case the account is later removed). */
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }
        Schema::table('payments', function (Blueprint $t) {
            if (! Schema::hasColumn('payments', 'bank_account_id')) {
                $t->unsignedBigInteger('bank_account_id')->nullable();
            }
            if (! Schema::hasColumn('payments', 'paid_into')) {
                $t->string('paid_into')->nullable();
            }
        });
    }

    public function down(): void
    {
    }
};
