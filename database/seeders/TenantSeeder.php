<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\School;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TenantSeeder extends Seeder
{
    /**
     * Run the database seeds for a tenant.
     * This is called automatically when a tenant is created via stancl/tenancy.
     */
    public function run(): void
    {
        // Get the current tenant from tenancy context
        $tenant = tenant();
        
        if (!$tenant) {
            $this->command->warn('⚠️  No tenant context found. This seeder should be run within a tenant context.');
            return;
        }

        // Get school name first (might be created before seeder runs)
        $school = School::first();
        $schoolName = $school ? $school->name : $tenant->name ?? 'School';
        
        // Generate admin email based on school name: admin@school_name.net
        $adminEmail = $this->generateAdminEmail($schoolName, $tenant);
        
        // Check if admin user already exists
        $adminUser = User::where('email', $adminEmail)->first();
        
        if ($adminUser) {
            $this->command->info("ℹ️  Admin user already exists: {$adminEmail}");
            return;
        }
        
        // Create admin user
        $adminUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Administrator',
            'email' => $adminEmail,
            'password' => Hash::make('Password12345'),
            'role' => 'school_admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->command->info("✅ Admin user created successfully!");
        $this->command->info("   Email: {$adminEmail}");
        $this->command->info("   Password: Password12345");
        $this->command->info("   Role: school_admin");
    }

    /**
     * Generate admin email based on school name: admin@school_name.net
     */
    protected function generateAdminEmail(string $schoolName, $tenant): string
    {
        // Convert school name to a URL-friendly slug
        $slug = Str::slug($schoolName);
        
        // Remove any special characters and spaces, keep only alphanumeric and hyphens
        $slug = preg_replace('/[^a-z0-9-]/', '', $slug);
        
        // If slug is empty, fallback to tenant subdomain or name
        if (empty($slug)) {
            $slug = $tenant->subdomain ?? Str::slug($tenant->name ?? 'school');
        }
        
        // Generate email: admin@school_name.net
        return "admin@{$slug}.net";
    }
}

