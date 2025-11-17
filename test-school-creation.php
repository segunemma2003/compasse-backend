<?php

/**
 * Test script for school creation endpoint
 *
 * Usage: php test-school-creation.php
 */

// Test against production server
$baseUrl = 'https://api.compasse.net';

// First, try to get tenant ID from production server
$tenantId = null;

// Step 0: Get tenant from production server
echo "Step 0: Getting tenant from production server...\n";
$loginData = [
    'email' => 'superadmin@compasse.net',
    'password' => 'Nigeria@60'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "$baseUrl/api/v1/auth/login");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($loginData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);

$loginResponse = curl_exec($ch);
$loginHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($loginHttpCode === 200) {
    $loginResult = json_decode($loginResponse, true);
    $tempToken = $loginResult['token'] ?? null;

    if ($tempToken) {
        // Get tenants list
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$baseUrl/api/v1/tenants");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $tempToken
        ]);

        $tenantsResponse = curl_exec($ch);
        $tenantsHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($tenantsHttpCode === 200) {
            $tenantsResult = json_decode($tenantsResponse, true);
            if (isset($tenantsResult['tenants']['data'][0]['id'])) {
                $tenantId = $tenantsResult['tenants']['data'][0]['id'];
                echo "✅ Found tenant on production: $tenantId\n\n";
            } elseif (isset($tenantsResult['tenants'][0]['id'])) {
                $tenantId = $tenantsResult['tenants'][0]['id'];
                echo "✅ Found tenant on production: $tenantId\n\n";
            }
        }
    }
}

// Fallback to local database if production doesn't have tenants
if (!$tenantId) {
    echo "⚠️  No tenant found on production. Trying local database...\n";
    $output = shell_exec("cd /Users/segun/Documents/projects/samschool-backend && php artisan tinker --execute=\"echo \\App\\Models\\Tenant::first()?->id ?? 'none';\" 2>&1");
    $tenantId = trim($output);
    if ($tenantId === 'none' || empty($tenantId)) {
        echo "❌ No tenant found locally either. Please run: php artisan db:seed --class=SuperAdminSeeder\n";
        echo "   Or ensure production server has been seeded.\n";
        exit(1);
    }
    echo "Using local Tenant ID: $tenantId\n";
    echo "⚠️  Note: This tenant may not exist on production server.\n\n";
} else {
    echo "Using Tenant ID: $tenantId\n\n";
}

echo "🧪 Testing School Creation Endpoint\n";
echo str_repeat("=", 60) . "\n\n";

// Step 1: Login to get authentication token
echo "Step 1: Authenticating with superadmin credentials...\n";
$loginData = [
    'email' => 'superadmin@compasse.net',
    'password' => 'Nigeria@60'
    // Note: tenant_id is optional for superadmin login
];

echo "Login URL: $baseUrl/api/v1/auth/login\n";
echo "Email: {$loginData['email']}\n\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "$baseUrl/api/v1/auth/login");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($loginData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);

$loginResponse = curl_exec($ch);
$loginHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Login Status: $loginHttpCode\n";
$loginResult = json_decode($loginResponse, true);

if ($loginHttpCode !== 200 || !isset($loginResult['token'])) {
    echo "❌ Authentication failed!\n";
    echo "Response: $loginResponse\n";
    exit(1);
}

$token = $loginResult['token'];
echo "✅ Authentication successful!\n\n";

// Step 2: Create school
echo "Step 2: Creating school...\n";
$schoolData = [
    'tenant_id' => $tenantId,
    'name' => 'Test School ' . date('Y-m-d H:i:s'),
    'address' => '123 Test Street',
    'phone' => '+1234567890',
    'email' => 'test@school.com',
    'website' => 'https://test.school.com',
    'status' => 'active'
];

// Debug output
echo "Sending request with:\n";
echo "  tenant_id in body: " . $schoolData['tenant_id'] . "\n";
echo "  X-Tenant-ID header: " . $tenantId . "\n";
echo "  URL: $baseUrl/api/v1/schools\n\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "$baseUrl/api/v1/schools");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($schoolData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
    'Authorization: Bearer ' . $token,
    'X-Tenant-ID: ' . $tenantId
]);

$schoolResponse = curl_exec($ch);
$schoolHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "School Creation Status: $schoolHttpCode\n";
if ($error) {
    echo "cURL Error: $error\n";
}

$schoolResult = json_decode($schoolResponse, true);

if ($schoolHttpCode === 201 || $schoolHttpCode === 200) {
    echo "✅ School created successfully!\n";
    echo "School ID: " . ($schoolResult['school']['id'] ?? 'N/A') . "\n";
    echo "School Name: " . ($schoolResult['school']['name'] ?? 'N/A') . "\n";
    echo "\nFull Response:\n";
    echo json_encode($schoolResult, JSON_PRETTY_PRINT) . "\n";
} else {
    echo "❌ School creation failed!\n";
    echo "Response: $schoolResponse\n";
    if (isset($schoolResult['error'])) {
        echo "Error: " . $schoolResult['error'] . "\n";
    }
    if (isset($schoolResult['message'])) {
        echo "Message: " . $schoolResult['message'] . "\n";
    }
    if (isset($schoolResult['messages'])) {
        echo "Validation Errors:\n";
        echo json_encode($schoolResult['messages'], JSON_PRETTY_PRINT) . "\n";
    }
}

echo "\n" . str_repeat("=", 60) . "\n";

