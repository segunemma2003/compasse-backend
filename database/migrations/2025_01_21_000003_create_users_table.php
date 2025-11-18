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
        // Drop existing users table if it exists
        Schema::dropIfExists('users');

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

        Schema::create('users', function (Blueprint $table) use ($tenantIdType) {
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
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone')->nullable();
            $table->enum('role', ['super_admin', 'school_admin', 'teacher', 'student', 'parent'])->default('student');
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->string('profile_picture')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['tenant_id', 'role']);
            $table->index(['tenant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
