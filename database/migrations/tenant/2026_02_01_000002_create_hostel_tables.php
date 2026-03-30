<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hostel_rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('room_number');
            $table->string('block')->nullable();
            $table->string('floor')->nullable();
            $table->enum('type', ['single', 'double', 'triple', 'dormitory'])->default('double');
            $table->unsignedInteger('capacity')->default(2);
            $table->unsignedInteger('occupied_count')->default(0);
            $table->decimal('price_per_term', 10, 2)->default(0);
            $table->json('amenities')->nullable();
            $table->enum('status', ['active', 'inactive', 'maintenance'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'room_number']);
            $table->index('school_id');
            $table->index('status');
        });

        Schema::create('hostel_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->unsignedBigInteger('term_id')->nullable();
            $table->date('allocated_at');
            $table->date('vacated_at')->nullable();
            $table->enum('status', ['active', 'vacated'])->default('active');
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->enum('payment_status', ['pending', 'partial', 'paid'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index('student_id');
            $table->index('room_id');
        });

        Schema::create('hostel_maintenance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('room_id');
            $table->string('title');
            $table->text('description');
            $table->unsignedBigInteger('reported_by')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('status', ['pending', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->timestamp('reported_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('cost', 10, 2)->default(0);
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index('room_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hostel_maintenance');
        Schema::dropIfExists('hostel_allocations');
        Schema::dropIfExists('hostel_rooms');
    }
};
