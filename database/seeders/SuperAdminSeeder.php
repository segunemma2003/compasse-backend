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
        // Super admin should NOT have a tenant - they manage ALL tenants from the central database
        
        // Check if super admin already exists
        $superAdmin = User::where('email', 'superadmin@compasse.net')->first();

        if ($superAdmin) {
            $this->command->info('ℹ️  Super admin user already exists. Skipping...');
            $this->command->info("   Email: {$superAdmin->email}");
            $this->command->info("   Role: {$superAdmin->role}");
            return;
        }

        // Create super admin user WITHOUT tenant_id (global user in central database)
        $superAdmin = User::create([
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
