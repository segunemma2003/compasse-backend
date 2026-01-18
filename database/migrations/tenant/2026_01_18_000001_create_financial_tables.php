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
        // Fees table
        if (!Schema::hasTable('fees')) {
            Schema::create('fees', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('class_id')->nullable();
                $table->unsignedBigInteger('academic_year_id');
                $table->unsignedBigInteger('term_id');
                $table->string('fee_type'); // tuition, transport, library, etc
                $table->decimal('amount', 10, 2);
                $table->decimal('amount_paid', 10, 2)->default(0);
                $table->decimal('balance', 10, 2);
                $table->date('due_date')->nullable();
                $table->enum('status', ['pending', 'partial', 'paid', 'overdue'])->default('pending');
                $table->text('description')->nullable();
                $table->timestamps();
                
                $table->index(['school_id', 'student_id', 'academic_year_id']);
                $table->index(['status']);
            });
        }

        // Fee Structures table
        if (!Schema::hasTable('fee_structures')) {
            Schema::create('fee_structures', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->string('name');
                $table->string('fee_type');
                $table->decimal('amount', 10, 2);
                $table->unsignedBigInteger('class_id')->nullable();
                $table->unsignedBigInteger('academic_year_id');
                $table->unsignedBigInteger('term_id')->nullable();
                $table->enum('frequency', ['one-time', 'termly', 'yearly'])->default('termly');
                $table->text('description')->nullable();
                $table->boolean('is_mandatory')->default(true);
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->timestamps();
                
                $table->index(['school_id', 'academic_year_id']);
            });
        }

        // Payments table
        if (!Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('fee_id')->nullable();
                $table->string('payment_reference')->unique();
                $table->decimal('amount', 10, 2);
                $table->string('payment_method'); // cash, bank_transfer, card, cheque
                $table->date('payment_date');
                $table->string('paid_by')->nullable(); // Name of person who paid
                $table->text('notes')->nullable();
                $table->enum('status', ['pending', 'confirmed', 'failed', 'refunded'])->default('confirmed');
                $table->unsignedBigInteger('received_by')->nullable(); // Staff user_id who received payment
                $table->timestamps();
                
                $table->index(['school_id', 'student_id']);
                $table->index(['payment_reference']);
                $table->index(['payment_date']);
            });
        }

        // Expenses table
        if (!Schema::hasTable('expenses')) {
            Schema::create('expenses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->string('description');
                $table->decimal('amount', 10, 2);
                $table->string('category'); // utilities, salaries, maintenance, supplies, etc
                $table->date('date');
                $table->string('payment_method')->nullable();
                $table->string('vendor')->nullable();
                $table->string('receipt_number')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable(); // User who approved
                $table->unsignedBigInteger('recorded_by'); // User who recorded
                $table->text('notes')->nullable();
                $table->enum('status', ['pending', 'approved', 'paid', 'cancelled'])->default('pending');
                $table->timestamps();
                
                $table->index(['school_id', 'category']);
                $table->index(['date']);
            });
        }

        // Payrolls table
        if (!Schema::hasTable('payrolls')) {
            Schema::create('payrolls', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('staff_id'); // references users table
                $table->unsignedBigInteger('academic_year_id');
                $table->integer('month');
                $table->integer('year');
                $table->decimal('basic_salary', 10, 2);
                $table->decimal('allowances', 10, 2)->default(0);
                $table->decimal('deductions', 10, 2)->default(0);
                $table->decimal('net_salary', 10, 2);
                $table->date('payment_date')->nullable();
                $table->string('payment_method')->nullable();
                $table->enum('status', ['pending', 'processing', 'paid', 'failed'])->default('pending');
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('processed_by')->nullable();
                $table->timestamps();
                
                $table->index(['school_id', 'staff_id']);
                $table->index(['year', 'month']);
                $table->index(['status']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payrolls');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('fee_structures');
        Schema::dropIfExists('fees');
    }
};

