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
        // Determine tenant_id type based on tenants table
        $tenantIdType = 'string'; // Default for stancl/tenancy
        if (Schema::hasTable('tenants')) {
            try {
                $idType = Schema::getColumnType('tenants', 'id');
                // If tenants.id is bigint/unsignedBigInteger, use unsignedBigInteger
                if (in_array($idType, ['bigint', 'bigint unsigned'])) {
                    $tenantIdType = 'unsignedBigInteger';
                }
            } catch (\Exception $e) {
                // Default to string if we can't determine
            }
        }

        Schema::create('schools', function (Blueprint $table) use ($tenantIdType) {
            $table->id();

            // Use appropriate type based on tenants.id type
            if ($tenantIdType === 'unsignedBigInteger') {
                $table->unsignedBigInteger('tenant_id');
            } else {
                $table->string('tenant_id');
            }

            // Only add foreign key if tenants table exists
            if (Schema::hasTable('tenants')) {
                $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');
            }

            $table->string('name');
            $table->string('code')->unique()->nullable(); // School code for identification
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('logo')->nullable();
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->timestamps();

            // Note: principal_id, vice_principal_id, academic_year, term, settings
            // are stored in the tenant database, not in main database

            $table->index(['tenant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
