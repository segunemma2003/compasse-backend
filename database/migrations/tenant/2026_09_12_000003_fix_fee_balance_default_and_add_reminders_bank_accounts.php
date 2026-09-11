<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Assign Fee by Class" (FeeController::createFeeStructure()) — and plain
 * single-fee creation (FeeController::store()) — has been failing for every
 * school since the fees table was created: fees.balance is NOT NULL with no
 * default, but neither controller method ever set it on Fee::create(), so
 * MySQL strict mode rejected the insert with "Field 'balance' doesn't have
 * a default value". The controller's own try/catch swallows the exception
 * and returns it as a plain JSON error (never calls report()/Log::error()),
 * which is why nothing showed up in storage/logs/laravel-*.log even though
 * every attempt was failing.
 *
 * Also adds: fees.last_reminded_at (for the new single/bulk fee-reminder
 * feature, so the UI can show "reminded 2 days ago"), and
 * school_bank_accounts (settings for the bank account(s) a school wants
 * fee payments made into — surfaced on invoices/receipts and reminder
 * emails sent to guardians).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fees') && Schema::hasColumn('fees', 'balance')) {
            DB::statement('ALTER TABLE fees MODIFY COLUMN balance DECIMAL(10,2) NOT NULL DEFAULT 0');
            // Backfill any rows that somehow already exist with a null/stale
            // balance so existing data reflects amount - amount_paid too.
            DB::statement('UPDATE fees SET balance = GREATEST(amount - amount_paid, 0) WHERE balance IS NULL OR balance = 0');
        }

        if (Schema::hasTable('fees') && ! Schema::hasColumn('fees', 'last_reminded_at')) {
            Schema::table('fees', function (Blueprint $table) {
                $table->timestamp('last_reminded_at')->nullable()->after('status');
            });
        }

        if (! Schema::hasTable('school_bank_accounts')) {
            Schema::create('school_bank_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->string('bank_name');
                $table->string('account_name');
                $table->string('account_number');
                $table->string('notes')->nullable();
                $table->boolean('is_primary')->default(false);
                $table->timestamps();

                $table->index('school_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('school_bank_accounts');

        if (Schema::hasTable('fees') && Schema::hasColumn('fees', 'last_reminded_at')) {
            Schema::table('fees', function (Blueprint $table) {
                $table->dropColumn('last_reminded_at');
            });
        }

        if (Schema::hasTable('fees') && Schema::hasColumn('fees', 'balance')) {
            DB::statement('ALTER TABLE fees MODIFY COLUMN balance DECIMAL(10,2) NOT NULL');
        }
    }
};
