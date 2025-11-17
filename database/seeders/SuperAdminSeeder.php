<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get or create a default tenant for super admin
        $tenant = Tenant::first();

        if (!$tenant) {
            // Check if ID column is string (UUID) or integer (auto-increment)
            try {
                $idType = \Illuminate\Support\Facades\Schema::getColumnType('tenants', 'id');
            } catch (\Exception $e) {
                // If we can't determine, try to create with UUID first
                $idType = 'string';
            }
            
            $tenantData = [
                'name' => 'System Administration',
                'domain' => 'admin.compasse.net',
                'subdomain' => 'admin',
                'database_name' => 'compasse_main',
                'database_host' => config('database.connections.mysql.host'),
                'database_port' => config('database.connections.mysql.port'),
                'database_username' => config('database.connections.mysql.username'),
                'database_password' => config('database.connections.mysql.password'),
                'status' => 'active',
                'settings' => [],
            ];
            
            // Add UUID if ID column is string type (stancl/tenancy uses UUID)
            if ($idType === 'string' || $idType === 'varchar') {
                $tenantData['id'] = Str::uuid()->toString();
            }
            
            $tenant = Tenant::create($tenantData);
        }

        // Check if super admin already exists
        $superAdmin = User::where('email', 'superadmin@compasse.net')->first();

        if ($superAdmin) {
            $this->command->info('ℹ️  Super admin user already exists. Skipping...');
            $this->command->info("   Email: {$superAdmin->email}");
            $this->command->info("   Role: {$superAdmin->role}");
            return;
        }

        // Create super admin user
        $superAdmin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Super Administrator',
            'email' => 'superadmin@compasse.net',
            'password' => Hash::make('Nigeria@60'),
            'role' => 'super_admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        // Attach super admin role if available
        if ($role = \App\Models\Role::where('slug', 'super_admin')->first()) {
            $superAdmin->roles()->syncWithoutDetaching([
                $role->id => [
                    'assigned_by' => $superAdmin->id,
                    'assigned_at' => now(),
                ],
            ]);
        }
        $this->command->info('✅ Super admin user created successfully!');

        $this->command->info('');
        $this->command->info('========================================');
        $this->command->info('Super Admin Credentials:');
        $this->command->info('Email: superadmin@compasse.net');
        $this->command->info('Password: Nigeria@60');
        $this->command->info('Role: super_admin');
        $this->command->info('========================================');
    }
}
