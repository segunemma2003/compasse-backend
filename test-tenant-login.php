<?php

/**
 * Test script for tenant login and verification
 * 
 * Usage: php test-tenant-login.php
 */

$baseUrl = 'https://api.compasse.net';

echo "🧪 Testing Tenant Login and Account Verification\n";
echo str_repeat("=", 60) . "\n\n";

// Step 1: Get tenant and school info
echo "Step 1: Getting tenant and school information...\n";
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

if ($loginHttpCode !== 200) {
    echo "❌ Superadmin login failed!\n";
    exit(1);
}

$loginResult = json_decode($loginResponse, true);
$token = $loginResult['token'];

// Get tenants
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "$baseUrl/api/v1/tenants");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
    'Authorization: Bearer ' . $token
]);

$tenantsResponse = curl_exec($ch);
$tenantsHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($tenantsHttpCode !== 200) {
    echo "❌ Failed to get tenants!\n";
    exit(1);
}

$tenantsResult = json_decode($tenantsResponse, true);
$tenant = null;
$school = null;

if (isset($tenantsResult['tenants']['data'][0])) {
    $tenant = $tenantsResult['tenants']['data'][0];
} elseif (isset($tenantsResult['tenants'][0])) {
    $tenant = $tenantsResult['tenants'][0];
}

if (!$tenant) {
    echo "❌ No tenant found!\n";
    exit(1);
}

echo "✅ Found tenant: {$tenant['name']} (ID: {$tenant['id']})\n";
echo "   Database: {$tenant['database_name']}\n\n";

// Get schools for this tenant
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "$baseUrl/api/v1/schools?tenant_id={$tenant['id']}");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
    'Authorization: Bearer ' . $token,
    'X-Tenant-ID: ' . $tenant['id']
]);

$schoolsResponse = curl_exec($ch);
$schoolsHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($schoolsHttpCode === 200) {
    $schoolsResult = json_decode($schoolsResponse, true);
    if (isset($schoolsResult['schools']['data'][0])) {
        $school = $schoolsResult['schools']['data'][0];
    } elseif (isset($schoolsResult['schools'][0])) {
        $school = $schoolsResult['schools'][0];
    } elseif (isset($schoolsResult['school'])) {
        $school = $schoolsResult['school'];
    }
}

if ($school) {
    echo "✅ Found school: {$school['name']} (ID: {$school['id']})\n\n";
    
    // Step 2: Try to login with admin account
    echo "Step 2: Testing tenant admin login...\n";
    
    // Generate expected admin email based on school name
    $schoolSlug = strtolower(preg_replace('/[^a-z0-9-]/', '', str_replace(' ', '-', $school['name'])));
    $adminEmail = "admin@{$schoolSlug}.net";
    
    echo "   Expected admin email: $adminEmail\n";
    echo "   Password: Password12345\n\n";
    
    $adminLoginData = [
        'email' => $adminEmail,
        'password' => 'Password12345',
        'tenant_id' => $tenant['id']
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "$baseUrl/api/v1/auth/login");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($adminLoginData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Tenant-ID: ' . $tenant['id']
    ]);
    
    $adminLoginResponse = curl_exec($ch);
    $adminLoginHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "   Login Status: $adminLoginHttpCode\n";
    $adminLoginResult = json_decode($adminLoginResponse, true);
    
    if ($adminLoginHttpCode === 200 && isset($adminLoginResult['token'])) {
        echo "✅ Tenant admin login successful!\n";
        echo "   Token: " . substr($adminLoginResult['token'], 0, 20) . "...\n";
        
        // Step 3: Test accessing tenant data
        echo "\nStep 3: Testing tenant data access...\n";
        
        $adminToken = $adminLoginResult['token'];
        
        // Get current user
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$baseUrl/api/v1/auth/me");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $adminToken,
            'X-Tenant-ID: ' . $tenant['id']
        ]);
        
        $meResponse = curl_exec($ch);
        $meHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($meHttpCode === 200) {
            $meResult = json_decode($meResponse, true);
            echo "✅ User data retrieved successfully!\n";
            echo "   User: {$meResult['user']['name']} ({$meResult['user']['email']})\n";
            echo "   Role: {$meResult['user']['role']}\n";
        } else {
            echo "⚠️  Failed to get user data (Status: $meHttpCode)\n";
        }
    } else {
        echo "❌ Tenant admin login failed!\n";
        echo "   Response: $adminLoginResponse\n";
        echo "\n   Note: Admin account may not have been created yet.\n";
        echo "   This happens when:\n";
        echo "   - Tenant database was created before seeder was added\n";
        echo "   - Seeder failed to run\n";
    }
} else {
    echo "⚠️  No school found for this tenant.\n";
    echo "   You may need to create a school first.\n";
}

echo "\n" . str_repeat("=", 60) . "\n";

