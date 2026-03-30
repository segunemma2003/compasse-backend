<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('student_id');
            $table->string('blood_group', 10)->nullable();
            $table->decimal('height_cm', 5, 1)->nullable();
            $table->decimal('weight_kg', 5, 1)->nullable();
            $table->json('allergies')->nullable();
            $table->json('medical_conditions')->nullable();
            $table->json('current_medications')->nullable();
            $table->json('immunization_records')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();
            $table->string('family_doctor_name')->nullable();
            $table->string('family_doctor_phone', 20)->nullable();
            $table->date('last_checkup_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'student_id']);
            $table->index('student_id');
        });

        Schema::create('health_appointments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('student_id');
            $table->string('doctor_name')->nullable();
            $table->date('appointment_date');
            $table->time('appointment_time')->nullable();
            $table->string('reason');
            $table->enum('status', ['scheduled', 'completed', 'cancelled', 'no_show'])->default('scheduled');
            $table->text('diagnosis')->nullable();
            $table->text('prescription')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'student_id']);
            $table->index('appointment_date');
        });

        Schema::create('medications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('student_id');
            $table->string('name');
            $table->string('dosage')->nullable();
            $table->string('frequency')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('prescribed_by')->nullable();
            $table->text('reason')->nullable();
            $table->text('side_effects')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['active', 'discontinued', 'completed'])->default('active');
            $table->timestamps();

            $table->index(['school_id', 'student_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medications');
        Schema::dropIfExists('health_appointments');
        Schema::dropIfExists('health_records');
    }
};
