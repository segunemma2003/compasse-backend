<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDomainsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        // Check if table already exists
        if (Schema::hasTable('domains')) {
            return;
        }

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

        Schema::create('domains', function (Blueprint $table) use ($tenantIdType) {
            $table->increments('id');
            $table->string('domain', 255)->unique();
            
            // Use appropriate type based on tenants.id type
            if ($tenantIdType === 'unsignedBigInteger') {
                $table->unsignedBigInteger('tenant_id');
            } else {
            $table->string('tenant_id');
            }

            $table->timestamps();
            
            // Only add foreign key if tenants table exists
            if (Schema::hasTable('tenants')) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
}
