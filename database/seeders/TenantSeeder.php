<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\School;
use App\Models\AcademicYear;
use App\Models\Term;
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
        \Log::info("TenantSeeder::run() called");
        
        // Get the current tenant from tenancy context
        $tenant = tenant();
        
        if (!$tenant) {
            \Log::warning('⚠️  No tenant context found');
            if ($this->command) {
                $this->command->warn('⚠️  No tenant context found. This seeder should be run within a tenant context.');
            }
            return;
        }

        \Log::info("TenantSeeder running for tenant", ['tenant_id' => $tenant->id]);

        // Get school name - check if schools table exists and has data
        $schoolName = 'School';
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('schools')) {
                $school = School::first();
                if ($school) {
                    $schoolName = $school->name;
                    $this->command->info("Found school: {$schoolName}");
                } else {
                    $this->command->warn("Schools table exists but no school found");
                }
            } else {
                $this->command->warn("Schools table does not exist yet");
            }
        } catch (\Exception $e) {
            // Schools table might not exist yet, use tenant name as fallback
            $schoolName = $tenant->name ?? 'School';
            $this->command->warn("Error checking schools table: " . $e->getMessage());
        }
        
        // Generate admin email based on school name: admin@school_slug.com
        $adminEmail = $this->generateAdminEmail($schoolName, $tenant);
        
        // Check if admin user already exists
        $adminUser = User::where('email', $adminEmail)->first();
        
        if ($adminUser) {
            $this->command->info("ℹ️  Admin user already exists: {$adminEmail}");
            return;
        }
        
        // Create admin user (no tenant_id needed in tenant DB - each DB is isolated)
        try {
            $adminUser = User::create([
                // Note: No tenant_id in tenant DB - each database is already isolated per tenant
                'name' => 'Administrator',
                'email' => $adminEmail,
                'password' => Hash::make('Password@12345'),
                'role' => 'school_admin',
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            $this->command->info("✅ Admin user created successfully!");
            $this->command->info("   Email: {$adminEmail}");
            $this->command->info("   Password: Password@12345");
            $this->command->info("   Role: school_admin");
        } catch (\Exception $e) {
            $this->command->warn("⚠️  Failed to create admin user: " . $e->getMessage());
            $this->command->warn("   This is normal if users table doesn't exist yet.");
        }
        
        // Seed default Academic Year and Terms
        $this->seedAcademicYearAndTerms();
    }
    
    /**
     * Seed default academic year and terms
     */
    protected function seedAcademicYearAndTerms(): void
    {
        try {
            // Check if academic_years table exists
            if (!\Illuminate\Support\Facades\Schema::hasTable('academic_years')) {
                $this->command->warn("Academic years table does not exist yet");
                return;
            }
            
            // Check if academic year already exists
            if (AcademicYear::count() > 0) {
                $this->command->info("ℹ️  Academic years already seeded");
                return;
            }
            
            // Get school_id if schools table exists
            $schoolId = null;
            if (\Illuminate\Support\Facades\Schema::hasTable('schools')) {
                $school = School::first();
                $schoolId = $school?->id;
            }
            
            if (!$schoolId) {
                $this->command->warn("⚠️  No school found, skipping academic year seeding");
                return;
            }
            
            // Create current academic year (2025-2026)
            $currentYear = date('Y');
            $nextYear = $currentYear + 1;
            
            $academicYear = AcademicYear::create([
                'school_id' => $schoolId,
                'name' => "{$currentYear}-{$nextYear}",
                'start_date' => "{$currentYear}-09-01",
                'end_date' => "{$nextYear}-07-31",
                'status' => 'active',
                'is_current' => true,
            ]);
            
            $this->command->info("✅ Academic year created: {$currentYear}-{$nextYear}");
            
            // Check if terms table exists
            if (!\Illuminate\Support\Facades\Schema::hasTable('terms')) {
                $this->command->warn("Terms table does not exist yet");
                return;
            }
            
            // Create default terms
            $nextYear = $currentYear + 1;
            $terms = [
                [
                    'name' => '1st Term',
                    'start_date' => "{$currentYear}-09-01",
                    'end_date' => "{$currentYear}-12-15",
                ],
                [
                    'name' => '2nd Term',
                    'start_date' => "{$nextYear}-01-05",
                    'end_date' => "{$nextYear}-04-10",
                ],
                [
                    'name' => '3rd Term',
                    'start_date' => "{$nextYear}-04-20",
                    'end_date' => "{$nextYear}-07-31",
                ],
            ];
            
            foreach ($terms as $index => $termData) {
                Term::create([
                    'school_id' => $schoolId,
                    'academic_year_id' => $academicYear->id,
                    'name' => $termData['name'],
                    'start_date' => $termData['start_date'],
                    'end_date' => $termData['end_date'],
                    'status' => 'active',
                    'is_current' => $index === 0, // First term is current
                ]);
                
                $this->command->info("✅ Term created: {$termData['name']}");
            }
            
        } catch (\Exception $e) {
            $this->command->warn("⚠️  Failed to seed academic year/terms: " . $e->getMessage());
        }
    }

    /**
     * Generate admin email based on school name: admin@school_slug.com
     */
    protected function generateAdminEmail(string $schoolName, $tenant): string
    {
        // Clean school name - remove dates, timestamps, and special characters
        $cleanName = preg_replace('/\d{4}-\d{2}-\d{2}.*$/', '', $schoolName); // Remove dates
        $cleanName = preg_replace('/\d{2}:\d{2}:\d{2}/', '', $cleanName); // Remove times
        $cleanName = trim($cleanName);
        
        // Convert to URL-friendly slug
        $slug = Str::slug($cleanName);
        
        // Remove any remaining special characters, keep only alphanumeric and hyphens
        $slug = preg_replace('/[^a-z0-9-]/', '', $slug);
        
        // Remove leading/trailing hyphens and collapse multiple hyphens
        $slug = trim($slug, '-');
        $slug = preg_replace('/-+/', '-', $slug);
        
        // If slug is empty or too short, fallback to tenant subdomain or name
        if (empty($slug) || strlen($slug) < 3) {
            $slug = $tenant->subdomain ?? Str::slug($tenant->name ?? 'school');
            $slug = preg_replace('/[^a-z0-9-]/', '', $slug);
            $slug = trim($slug, '-');
            $slug = preg_replace('/-+/', '-', $slug);
        }
        
        // Final fallback - ensure valid domain part
        if (empty($slug) || strlen($slug) < 3) {
            $slug = 'school';
        }
        
        // Ensure it doesn't start or end with hyphen
        $slug = trim($slug, '-');
        if (empty($slug)) {
            $slug = 'school';
        }
        
        // Generate email: admin@school_slug.com
        return "admin@{$slug}.com";
    }
}

