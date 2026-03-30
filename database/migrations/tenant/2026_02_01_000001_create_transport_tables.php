<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('make');
            $table->string('model');
            $table->year('year')->nullable();
            $table->string('plate_number')->unique();
            $table->unsignedInteger('capacity')->default(0);
            $table->enum('type', ['bus', 'van', 'car', 'minibus'])->default('bus');
            $table->enum('status', ['active', 'inactive', 'maintenance'])->default('active');
            $table->date('insurance_expiry')->nullable();
            $table->date('last_service_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('school_id');
            $table->index('status');
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name');
            $table->string('license_number')->unique();
            $table->date('license_expiry')->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('profile_picture')->nullable();
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->timestamps();

            $table->index('school_id');
            $table->index('status');
        });

        Schema::create('transport_routes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->string('name');
            $table->string('route_code')->nullable();
            $table->text('description')->nullable();
            $table->string('start_point');
            $table->string('end_point');
            $table->json('stops')->nullable();
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->decimal('fare', 10, 2)->default(0);
            $table->time('morning_pickup_time')->nullable();
            $table->time('afternoon_dropoff_time')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index('school_id');
            $table->index('status');
        });

        Schema::create('student_transport_routes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('route_id');
            $table->string('pickup_stop')->nullable();
            $table->string('dropoff_stop')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'route_id']);
            $table->index('route_id');
        });

        Schema::create('secure_pickups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('student_id');
            $table->string('authorized_name');
            $table->string('authorized_phone', 20);
            $table->string('relationship');
            $table->string('authorized_photo')->nullable();
            $table->string('pickup_code', 10)->unique();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('school_id');
            $table->index('student_id');
            $table->index('pickup_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_pickups');
        Schema::dropIfExists('student_transport_routes');
        Schema::dropIfExists('transport_routes');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('vehicles');
    }
};
