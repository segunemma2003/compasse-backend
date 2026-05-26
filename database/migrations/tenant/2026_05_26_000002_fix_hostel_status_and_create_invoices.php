<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fix hostel_rooms status enum: was ['active','inactive','maintenance'], should be ['available','occupied','maintenance','closed']
        if (Schema::hasTable('hostel_rooms') && Schema::hasColumn('hostel_rooms', 'status')) {
            // Map old values to new before changing enum
            DB::statement("UPDATE hostel_rooms SET status = 'available' WHERE status = 'active'");
            DB::statement("UPDATE hostel_rooms SET status = 'occupied'  WHERE status = 'inactive'");
            DB::statement("ALTER TABLE hostel_rooms MODIFY COLUMN status ENUM('available','occupied','maintenance','closed') NOT NULL DEFAULT 'available'");
        }

        // Create invoices table
        if (!Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('guardian_id')->nullable();
                $table->string('invoice_number')->unique();
                $table->date('invoice_date');
                $table->date('due_date');
                $table->decimal('subtotal', 10, 2)->default(0);
                $table->decimal('tax_amount', 10, 2)->default(0);
                $table->decimal('discount_amount', 10, 2)->default(0);
                $table->decimal('total_amount', 10, 2)->default(0);
                $table->enum('status', ['draft', 'sent', 'paid', 'overdue', 'cancelled'])->default('draft');
                $table->string('payment_terms')->nullable();
                $table->text('notes')->nullable();
                $table->json('billing_address')->nullable();
                $table->json('shipping_address')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancellation_reason')->nullable();
                $table->timestamps();

                $table->index(['school_id', 'student_id']);
                $table->index(['school_id', 'status']);
                $table->index('invoice_date');
            });
        }

        // Create invoice_items table
        if (!Schema::hasTable('invoice_items')) {
            Schema::create('invoice_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('invoice_id');
                $table->string('description');
                $table->decimal('quantity', 10, 2)->default(1);
                $table->decimal('unit_price', 10, 2)->default(0);
                $table->decimal('total_price', 10, 2)->default(0);
                $table->timestamps();

                $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
                $table->index('invoice_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');

        if (Schema::hasTable('hostel_rooms') && Schema::hasColumn('hostel_rooms', 'status')) {
            DB::statement("UPDATE hostel_rooms SET status = 'active' WHERE status = 'available'");
            DB::statement("UPDATE hostel_rooms SET status = 'inactive' WHERE status = 'occupied'");
            DB::statement("ALTER TABLE hostel_rooms MODIFY COLUMN status ENUM('active','inactive','maintenance') NOT NULL DEFAULT 'active'");
        }
    }
};
