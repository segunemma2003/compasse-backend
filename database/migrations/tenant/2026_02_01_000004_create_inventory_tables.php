<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('color', 7)->default('#3B82F6');
            $table->timestamps();

            $table->unique(['school_id', 'name']);
            $table->index('school_id');
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('sku')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->string('unit')->default('piece');
            $table->unsignedInteger('min_quantity')->default(5);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->string('location')->nullable();
            $table->string('supplier')->nullable();
            $table->enum('status', ['active', 'inactive', 'discontinued'])->default('active');
            $table->timestamps();

            $table->index(['school_id', 'category_id']);
            $table->index('status');
        });

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('item_id');
            $table->enum('type', ['checkout', 'return', 'purchase', 'adjustment', 'disposal'])->default('checkout');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('remaining_quantity')->default(0);
            $table->nullableMorphs('borrower');
            $table->string('borrower_name')->nullable();
            $table->string('purpose')->nullable();
            $table->date('expected_return_date')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'completed', 'overdue'])->default('completed');
            $table->timestamps();

            $table->index(['school_id', 'item_id']);
            $table->index('status');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('inventory_categories');
    }
};
