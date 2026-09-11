<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App\Modules\Financial\Models\Payment::$fillable has always listed
 * guardian_id (there's a guardian() relation, index()/show() eager-load it,
 * receipts/summaries read $payment->guardian), and
 * PaymentController::store() has always passed it through — but the
 * payments table (2026_01_18_000001_create_financial_tables) never actually
 * got the column. Every payment recorded through the "Record Payment"
 * button (PaymentController::store()) crashed with "Unknown column
 * 'guardian_id' in field list", independently of the payment_reference/
 * status enum bugs fixed alongside this.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payments') && ! Schema::hasColumn('payments', 'guardian_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->foreignId('guardian_id')->nullable()->after('student_id')
                    ->constrained('guardians')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'guardian_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('guardian_id');
            });
        }
    }
};
