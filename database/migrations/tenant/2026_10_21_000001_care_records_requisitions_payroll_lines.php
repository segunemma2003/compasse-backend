<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - care_records: the school's paper forms kept online (ouch / head injury reports, medicine forms,
     *   pre-school and homework diaries, class / school / nurse daily reports). One row per filled form;
     *   the fields live in `data`, laid out by the form's template on the portal.
     * - requisitions: imprest (cash advance, later retired) and requisitions (items / purchases).
     * - audit_logs: who edited or deleted what (payroll, care records, requisitions).
     * - payrolls.items: the pay slip's own lines (PAYE, pension, loan, bonus, …).
     * - payments.recorded_by: the cashier, for the receipt.
     */
    public function up(): void
    {
        if (! Schema::hasTable('care_records')) {
            Schema::create('care_records', function (Blueprint $t) {
                $t->id();
                $t->string('type', 40)->index();
                $t->unsignedInteger('serial')->default(0);              // per-type running number (00169)
                $t->unsignedBigInteger('student_id')->nullable()->index();
                $t->unsignedBigInteger('class_id')->nullable()->index();
                $t->date('record_date')->index();
                $t->date('period_end')->nullable();                     // weekly forms
                $t->json('data')->nullable();
                $t->json('parent_response')->nullable();                // {comment, name, relationship, signed_at, user_id}
                $t->string('status', 20)->default('submitted');         // draft | submitted
                $t->unsignedBigInteger('created_by')->nullable()->index();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('requisitions')) {
            Schema::create('requisitions', function (Blueprint $t) {
                $t->id();
                $t->string('type', 20)->default('requisition');          // requisition | imprest
                $t->string('number', 30)->nullable();
                $t->string('title');
                $t->text('purpose')->nullable();
                $t->string('department')->nullable();
                $t->json('items')->nullable();                           // [{description, quantity, unit_cost}]
                $t->decimal('amount', 14, 2)->default(0);
                $t->date('needed_by')->nullable();
                $t->string('status', 20)->default('pending');            // pending | approved | rejected | disbursed | retired
                $t->unsignedBigInteger('requested_by')->index();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->text('decision_note')->nullable();
                $t->decimal('amount_approved', 14, 2)->nullable();
                $t->unsignedBigInteger('disbursed_by')->nullable();
                $t->timestamp('disbursed_at')->nullable();
                $t->string('disbursement_method', 30)->nullable();
                $t->unsignedBigInteger('expense_id')->nullable();
                $t->decimal('amount_spent', 14, 2)->nullable();          // imprest retirement
                $t->decimal('amount_returned', 14, 2)->nullable();
                $t->text('retirement_note')->nullable();
                $t->json('retirement_items')->nullable();
                $t->timestamp('retired_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $t) {
                $t->id();
                $t->string('subject_type', 40)->index();
                $t->unsignedBigInteger('subject_id')->nullable()->index();
                $t->string('action', 30);
                $t->string('summary', 500)->nullable();
                $t->json('changes')->nullable();                         // {field: [old, new]}
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('user_name')->nullable();
                $t->string('user_role', 50)->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }

        if (Schema::hasTable('payrolls') && ! Schema::hasColumn('payrolls', 'items')) {
            Schema::table('payrolls', fn (Blueprint $t) => $t->json('items')->nullable());
        }
        if (Schema::hasTable('payments') && ! Schema::hasColumn('payments', 'recorded_by')) {
            Schema::table('payments', fn (Blueprint $t) => $t->unsignedBigInteger('recorded_by')->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('care_records');
        Schema::dropIfExists('requisitions');
        Schema::dropIfExists('audit_logs');
        if (Schema::hasColumn('payrolls', 'items')) {
            Schema::table('payrolls', fn (Blueprint $t) => $t->dropColumn('items'));
        }
        if (Schema::hasColumn('payments', 'recorded_by')) {
            Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('recorded_by'));
        }
    }
};
