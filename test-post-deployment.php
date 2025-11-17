<?php

/**
 * Post-Deployment Test Script
 * 
 * Run this after deployment to verify all APIs are working correctly
 * Usage: php test-post-deployment.php
 */

$baseUrl = 'https://api.compasse.net';
$credentials = [
    'email' => 'superadmin@compasse.net',
    'password' => 'Nigeria@60'
];

echo "🚀 POST-DEPLOYMENT API TEST SUITE\n";
echo str_repeat("=", 70) . "\n\n";

$results = [
    'passed' => 0,
    'failed' => 0,
    'tests' => []
];

/**
 * Test helper function
 */
function runTest($name, $method, $url, $headers = [], $data = null, $expectedStatus = 200) {
    global $results;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    $success = ($httpCode === $expectedStatus) && empty($error);
    $result = [
        'name' => $name,
        'status' => $success ? '✅ PASS' : '❌ FAIL',
        'http_code' => $httpCode,
        'expected' => $expectedStatus,
        'error' => $error,
        'response' => $response
    ];
    
    if ($success) {
        $results['passed']++;
    } else {
        $results['failed']++;
    }
    
    $results['tests'][] = $result;
    
    echo sprintf(
        "%-50s %s (HTTP %d)\n",
        $name,
        $result['status'],
        $httpCode
    );
    
    if (!$success && $error) {
        echo "   Error: $error\n";
    }
    
    return ['success' => $success, 'token' => $success ? json_decode($response, true)['token'] ?? null : null, 'response' => $response];
}

// Test 1: Health Check
echo "📋 Running Health Check...\n";
runTest('Health Check', 'GET', "$baseUrl/api/health", [
    'Accept: application/json'
], null, 200);

echo "\n";

// Test 2: Authentication
echo "🔐 Testing Authentication...\n";
$authResult = runTest('Superadmin Login', 'POST', "$baseUrl/api/v1/auth/login", [
    'Content-Type: application/json',
    'Accept: application/json'
], $credentials, 200);

$token = $authResult['token'] ?? null;

if (!$token) {
    echo "\n❌ CRITICAL: Authentication failed! Cannot continue with authenticated tests.\n";
    echo "Response: " . ($authResult['response'] ?? 'No response') . "\n";
    exit(1);
}

echo "\n✅ Authentication successful! Token obtained.\n\n";

// Test 3: Get Current User
echo "👤 Testing User Endpoints...\n";
runTest('Get Current User', 'GET', "$baseUrl/api/v1/auth/me", [
    'Accept: application/json',
    'Authorization: Bearer ' . $token
], null, 200);

echo "\n";

// Test 4: School Creation
echo "🏫 Testing School Management...\n";

// Get tenant ID from user data
$userData = json_decode($authResult['response'], true);
$tenantId = $userData['user']['tenant_id'] ?? $userData['tenant']['id'] ?? 1;

$schoolData = [
    'tenant_id' => $tenantId,
    'name' => 'Test School ' . date('Y-m-d H:i:s'),
    'address' => '123 Test Street',
    'phone' => '+1234567890',
    'email' => 'test@school.com',
    'website' => 'https://test.school.com',
    'status' => 'active'
];

$schoolResult = runTest('Create School', 'POST', "$baseUrl/api/v1/schools", [
    'Content-Type: application/json',
    'Accept: application/json',
    'Authorization: Bearer ' . $token,
    'X-Tenant-ID: ' . $tenantId
], $schoolData, 201);

if ($schoolResult['success']) {
    $schoolResponse = json_decode($schoolResult['response'], true);
    $schoolId = $schoolResponse['school']['id'] ?? null;
    
    if ($schoolId) {
        echo "\n   School created with ID: $schoolId\n";
        
        // Test getting the school
        runTest('Get School', 'GET', "$baseUrl/api/v1/schools/$schoolId", [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'X-Tenant-ID: ' . $tenantId
        ], null, 200);
        
        // Test school stats
        runTest('Get School Stats', 'GET', "$baseUrl/api/v1/schools/$schoolId/stats", [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'X-Tenant-ID: ' . $tenantId
        ], null, 200);
    }
}

echo "\n";

// Test 5: Tenant Management (if superadmin)
echo "🏢 Testing Tenant Management...\n";
runTest('List Tenants', 'GET', "$baseUrl/api/v1/tenants", [
    'Accept: application/json',
    'Authorization: Bearer ' . $token
], null, 200);

echo "\n";

// Summary
echo str_repeat("=", 70) . "\n";
echo "📊 TEST SUMMARY\n";
echo str_repeat("=", 70) . "\n";
echo "Total Tests: " . count($results['tests']) . "\n";
echo "✅ Passed: {$results['passed']}\n";
echo "❌ Failed: {$results['failed']}\n";
echo "Success Rate: " . round(($results['passed'] / count($results['tests'])) * 100, 2) . "%\n\n";

if ($results['failed'] > 0) {
    echo "❌ FAILED TESTS:\n";
    foreach ($results['tests'] as $test) {
        if ($test['status'] === '❌ FAIL') {
            echo "  - {$test['name']}: HTTP {$test['http_code']} (expected {$test['expected']})\n";
            if ($test['error']) {
                echo "    Error: {$test['error']}\n";
            }
        }
    }
    echo "\n";
}

if ($results['failed'] === 0) {
    echo "🎉 ALL TESTS PASSED! Deployment successful!\n";
    exit(0);
} else {
    echo "⚠️  Some tests failed. Please review the errors above.\n";
    exit(1);
}

