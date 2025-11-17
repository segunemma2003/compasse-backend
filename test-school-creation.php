<?php

/**
 * Test script for school creation endpoint
 * 
 * Usage: php test-school-creation.php
 */

$baseUrl = 'https://api.compasse.net'; // Production server URL

// Get tenant ID from database
$tenantId = null;
$output = shell_exec("cd /Users/segun/Documents/projects/samschool-backend && php artisan tinker --execute=\"echo \\App\\Models\\Tenant::first()?->id ?? 'none';\" 2>&1");
$tenantId = trim($output);
if ($tenantId === 'none' || empty($tenantId)) {
    echo "⚠️  No tenant found. Please run: php artisan db:seed --class=SuperAdminSeeder\n";
    exit(1);
}
$tenantId = (int)$tenantId;
echo "Using Tenant ID: $tenantId\n\n";

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

