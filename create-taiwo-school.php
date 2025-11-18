<?php

require __DIR__ . '/vendor/autoload.php';

$baseUrl = 'http://127.0.0.1:8000';
$superadminEmail = 'superadmin@compasse.net';
$superadminPassword = 'Nigeria@60';

// Colors for output
$green = "\033[32m";
$red = "\033[31m";
$yellow = "\033[33m";
$cyan = "\033[36m";
$reset = "\033[0m";

function testApi($method, $url, $data = null, $headers = []) {
    global $baseUrl, $green, $red, $yellow, $cyan, $reset;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl . $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $headers['Content-Type'] = 'application/json';
    }
    
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_map(function($k, $v) {
            return "$k: $v";
        }, array_keys($headers), $headers));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    return [
        'status' => $httpCode,
        'response' => $result,
        'raw' => $response
    ];
}

echo "{$cyan}=== Creating Taiwo International School ==={$reset}\n\n";

// Step 1: Login as superadmin
echo "{$yellow}Step 1: Logging in as superadmin...{$reset}\n";
$loginResult = testApi('POST', "/api/v1/auth/login", [
    'email' => $superadminEmail,
    'password' => $superadminPassword
], [
    'Content-Type' => 'application/json'
]);

if ($loginResult['status'] !== 200) {
    echo "{$red}❌ Superadmin login failed: {$loginResult['status']}{$reset}\n";
    echo "Response: " . $loginResult['raw'] . "\n";
    exit(1);
}

$token = $loginResult['response']['token'] ?? null;
if (!$token) {
    echo "{$red}❌ No token received{$reset}\n";
    exit(1);
}

echo "{$green}✅ Superadmin login successful{$reset}\n";
echo "Token: " . substr($token, 0, 20) . "...\n\n";

// Step 2: Create school (API will create new tenant automatically)
echo "{$yellow}Step 2: Creating school 'Taiwo International' (will create new tenant and database)...{$reset}\n";

$schoolData = [
    'name' => 'Taiwo International',
    'address' => '123 Education Street, Lagos, Nigeria',
    'phone' => '+234-123-456-7890',
    'email' => 'info@taiwointernational.com',
    'website' => 'https://taiwointernational.com',
    'code' => 'TAIWO',
    // DO NOT provide tenant_id - let the API create a new tenant and database
];

$headers = [
    'Authorization' => "Bearer {$token}",
    'Content-Type' => 'application/json'
];

$createSchoolResult = testApi('POST', '/api/v1/schools', $schoolData, $headers);

if ($createSchoolResult['status'] !== 201 && $createSchoolResult['status'] !== 200) {
    echo "{$red}❌ School creation failed: {$createSchoolResult['status']}{$reset}\n";
    echo "Response: " . $createSchoolResult['raw'] . "\n";
    exit(1);
}

$school = $createSchoolResult['response']['school'] ?? $createSchoolResult['response'] ?? null;
if (!$school) {
    echo "{$red}❌ No school data in response{$reset}\n";
    echo "Response: " . $createSchoolResult['raw'] . "\n";
    exit(1);
}

$schoolId = $school['id'] ?? null;
$tenantId = $school['tenant_id'] ?? $tenantId ?? null;

echo "{$green}✅ School created successfully!{$reset}\n";
echo "School ID: {$schoolId}\n";
echo "Tenant ID: {$tenantId}\n";
echo "School Name: {$school['name']}\n\n";

// Step 3: Wait a bit for database setup and migrations
echo "{$yellow}Step 3: Waiting for database setup and migrations...{$reset}\n";
sleep(5); // Give more time for database creation and migrations

// Step 4: Login as school admin
echo "{$yellow}Step 4: Logging in as school admin...{$reset}\n";

// Generate admin email based on school name - use same logic as SchoolController
$schoolName = 'Taiwo International';
$cleanName = preg_replace('/\d{4}-\d{2}-\d{2}.*$/', '', $schoolName);
$cleanName = preg_replace('/\d{2}:\d{2}:\d{2}/', '', $cleanName);
$cleanName = trim($cleanName);
// Use Laravel's Str::slug which handles this properly
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();
$slug = \Illuminate\Support\Str::slug($cleanName);
$slug = preg_replace('/[^a-z0-9-]/', '', $slug);
$slug = trim($slug, '-');
$slug = preg_replace('/-+/', '-', $slug);
if (empty($slug) || strlen($slug) < 3) {
    $slug = 'taiwo-international';
}
$adminEmail = "admin@{$slug}.com";
$adminPassword = 'Password@12345';

echo "Admin Email: {$adminEmail}\n";
echo "Admin Password: {$adminPassword}\n";

// Retry login up to 5 times
$adminToken = null;
for ($i = 0; $i < 5; $i++) {
    $adminLoginResult = testApi('POST', '/api/v1/auth/login', [
        'email' => $adminEmail,
        'password' => $adminPassword,
        'tenant_id' => $tenantId
    ], [
        'X-Tenant-ID' => $tenantId,
        'Content-Type' => 'application/json'
    ]);
    
    if ($adminLoginResult['status'] === 200) {
        $adminToken = $adminLoginResult['response']['token'] ?? null;
        if ($adminToken) {
            echo "{$green}✅ School admin login successful!{$reset}\n";
            break;
        }
    }
    
    if ($i < 4) {
        echo "{$yellow}⏳ Waiting 2 seconds before retry...{$reset}\n";
        sleep(2);
    }
}

if (!$adminToken) {
    echo "{$red}❌ School admin login failed after retries{$reset}\n";
    echo "Response: " . ($adminLoginResult['raw'] ?? 'No response') . "\n";
    echo "{$yellow}⚠️  Continuing with superadmin token...{$reset}\n";
    $adminToken = $token;
} else {
    echo "{$green}✅ Using school admin token for subsequent operations{$reset}\n";
}

echo "\n{$cyan}=== School Creation Complete ==={$reset}\n";
echo "School Name: Taiwo International\n";
echo "School ID: {$schoolId}\n";
echo "Tenant ID: {$tenantId}\n";
echo "Admin Email: {$adminEmail}\n";
echo "Admin Password: {$adminPassword}\n";
echo "Admin Token: " . substr($adminToken, 0, 20) . "...\n\n";

// Save credentials for next script
file_put_contents('taiwo-school-credentials.json', json_encode([
    'school_id' => $schoolId,
    'tenant_id' => $tenantId,
    'admin_email' => $adminEmail,
    'admin_password' => $adminPassword,
    'admin_token' => $adminToken,
    'superadmin_token' => $token
], JSON_PRETTY_PRINT));

echo "{$green}✅ Credentials saved to taiwo-school-credentials.json{$reset}\n\n";

