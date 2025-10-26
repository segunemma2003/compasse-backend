<?php

require_once 'vendor/autoload.php';

use App\Models\Tenant;
use App\Models\School;
use App\Models\User;
use App\Models\Plan;
use App\Models\Module;
use App\Models\Subscription;

echo "🚀 Testing API Routes with SQLite Database...\n\n";

// Create test data
echo "📊 Creating test data...\n";

// Create a tenant
$tenant = Tenant::create([
    'name' => 'Test School District',
    'subdomain' => 'test-school',
    'database_name' => 'test_school_db',
    'database_username' => 'test_user',
    'database_password' => 'test_password',
    'status' => 'active'
]);

echo "✅ Created tenant: {$tenant->name}\n";

// Create a school
$school = School::create([
    'tenant_id' => $tenant->id,
    'name' => 'Test High School',
    'address' => '123 Test Street',
    'phone' => '+1234567890',
    'email' => 'test@school.com',
    'status' => 'active'
]);

echo "✅ Created school: {$school->name}\n";

// Create a plan
$plan = Plan::create([
    'name' => 'Basic Plan',
    'slug' => 'basic',
    'description' => 'Basic school management plan',
    'price' => 99.99,
    'billing_cycle' => 'monthly',
    'max_students' => 500,
    'max_teachers' => 50,
    'max_classes' => 20,
    'features' => ['student_management', 'teacher_management', 'basic_reports'],
    'limits' => ['students' => 500, 'teachers' => 50, 'classes' => 20],
    'is_active' => true
]);

echo "✅ Created plan: {$plan->name}\n";

// Create modules
$modules = [
    ['name' => 'Student Management', 'slug' => 'student_management', 'is_core' => true],
    ['name' => 'Teacher Management', 'slug' => 'teacher_management', 'is_core' => true],
    ['name' => 'CBT System', 'slug' => 'cbt', 'is_core' => false],
    ['name' => 'Livestream', 'slug' => 'livestream', 'is_core' => false],
];

foreach ($modules as $moduleData) {
    $module = Module::create($moduleData);
    echo "✅ Created module: {$module->name}\n";
}

// Create a subscription
$subscription = Subscription::create([
    'school_id' => $school->id,
    'plan_id' => $plan->id,
    'status' => 'active',
    'start_date' => now(),
    'end_date' => now()->addYear(),
    'features' => $plan->features,
    'limits' => $plan->limits
]);

echo "✅ Created subscription\n";

// Create a super admin user
$superAdmin = User::create([
    'tenant_id' => $tenant->id,
    'name' => 'Super Admin',
    'email' => 'admin@test.com',
    'password' => bcrypt('password'),
    'role' => 'super_admin',
    'status' => 'active'
]);

echo "✅ Created super admin user\n";

// Create a school admin user
$schoolAdmin = User::create([
    'tenant_id' => $tenant->id,
    'name' => 'School Admin',
    'email' => 'school@test.com',
    'password' => bcrypt('password'),
    'role' => 'school_admin',
    'status' => 'active'
]);

echo "✅ Created school admin user\n";

echo "\n" . str_repeat("=", 50) . "\n";
echo "🎯 Test Data Created Successfully!\n";
echo str_repeat("=", 50) . "\n";

echo "\n📋 Test Data Summary:\n";
echo "• Tenant: {$tenant->name} (ID: {$tenant->id})\n";
echo "• School: {$school->name} (ID: {$school->id})\n";
echo "• Plan: {$plan->name} (ID: {$plan->id})\n";
echo "• Modules: " . Module::count() . " created\n";
echo "• Subscription: Active\n";
echo "• Super Admin: {$superAdmin->email}\n";
echo "• School Admin: {$schoolAdmin->email}\n";

echo "\n🔧 You can now test the API with:\n";
echo "• Super Admin Login: admin@test.com / password\n";
echo "• School Admin Login: school@test.com / password\n";
echo "• Tenant Subdomain: test-school\n";

echo "\n✅ Database setup complete! The API should now work properly.\n";
