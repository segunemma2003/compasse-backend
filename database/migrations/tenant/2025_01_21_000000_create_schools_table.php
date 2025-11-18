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
        // Determine tenant_id type based on tenants table in main DB
        $tenantIdType = 'string'; // Default for stancl/tenancy UUID
        try {
            // Connect to main DB to check tenant ID type
            $mainConnection = config('database.connections.mysql.database');
            if ($mainConnection) {
                $idType = \Illuminate\Support\Facades\DB::connection('mysql')
                    ->select("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'id'", [$mainConnection]);
                if (!empty($idType) && in_array($idType[0]->DATA_TYPE ?? '', ['bigint', 'int'])) {
                    $tenantIdType = 'unsignedBigInteger';
                }
            }
        } catch (\Exception $e) {
            // Default to string if we can't determine
        }

        Schema::create('schools', function (Blueprint $table) {
            $table->id();

            // Note: No tenant_id needed in tenant DB - each database is already isolated per tenant
            $table->string('name');
            $table->string('code')->unique();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('logo')->nullable();

            // School-specific fields (only in tenant DB)
            $table->unsignedBigInteger('principal_id')->nullable();
            $table->unsignedBigInteger('vice_principal_id')->nullable();
            $table->string('academic_year')->nullable();
            $table->string('term')->nullable();
            $table->json('settings')->nullable();

            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index(['status']);
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

