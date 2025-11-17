<?php

/**
 * Comprehensive API Test Suite
 * Tests all APIs, subdomain handling, and superadmin access
 * 
 * Usage: php test-all-apis-comprehensive.php
 */

$baseUrl = 'https://api.compasse.net';
$superadminCredentials = [
    'email' => 'superadmin@compasse.net',
    'password' => 'Nigeria@60'
];

echo "🧪 COMPREHENSIVE API TEST SUITE\n";
echo str_repeat("=", 80) . "\n\n";

$results = [
    'passed' => 0,
    'failed' => 0,
    'skipped' => 0,
    'tests' => []
];

/**
 * Test helper function
 */
function runTest($name, $method, $url, $headers = [], $data = null, $expectedStatus = 200, $skip = false) {
    global $results;
    
    if ($skip) {
        $results['skipped']++;
        $results['tests'][] = [
            'name' => $name,
            'status' => '⏭️  SKIP',
            'http_code' => null,
            'expected' => $expectedStatus,
            'error' => null,
            'response' => 'Skipped'
        ];
        echo sprintf("%-60s %s\n", $name, '⏭️  SKIP');
        return ['success' => true, 'token' => null, 'response' => null];
    }
    
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
    
    $statusIcon = $success ? '✅' : '❌';
    echo sprintf("%-60s %s (HTTP %d)\n", $name, $statusIcon, $httpCode);
    
    if (!$success && $error) {
        echo "   Error: $error\n";
    }
    
    if (!$success && $httpCode !== $expectedStatus) {
        $responseData = json_decode($response, true);
        if (isset($responseData['message'])) {
            echo "   Message: {$responseData['message']}\n";
        }
        if (isset($responseData['error'])) {
            echo "   Error: {$responseData['error']}\n";
        }
    }
    
    return [
        'success' => $success,
        'token' => $success ? (json_decode($response, true)['token'] ?? null) : null,
        'response' => $response,
        'data' => json_decode($response, true)
    ];
}

// ============================================================================
// SECTION 1: PUBLIC ROUTES (No Authentication Required)
// ============================================================================
echo "📋 SECTION 1: PUBLIC ROUTES\n";
echo str_repeat("-", 80) . "\n";

runTest('Health Check', 'GET', "$baseUrl/api/health", [
    'Accept: application/json'
], null, 200);

echo "\n";

// ============================================================================
// SECTION 2: AUTHENTICATION ROUTES
// ============================================================================
echo "🔐 SECTION 2: AUTHENTICATION ROUTES\n";
echo str_repeat("-", 80) . "\n";

$authResult = runTest('Superadmin Login', 'POST', "$baseUrl/api/v1/auth/login", [
    'Content-Type: application/json',
    'Accept: application/json'
], $superadminCredentials, 200);

$token = $authResult['token'] ?? null;
$userData = $authResult['data'] ?? [];
$tenantId = $userData['user']['tenant_id'] ?? $userData['tenant']['id'] ?? null;

if (!$token) {
    echo "\n❌ CRITICAL: Authentication failed! Cannot continue with authenticated tests.\n";
    exit(1);
}

echo "\n✅ Authentication successful!\n";
echo "   Token: " . substr($token, 0, 20) . "...\n";
if ($tenantId) {
    echo "   Tenant ID: $tenantId\n";
}
echo "\n";

// Test authenticated routes
runTest('Get Current User (Me)', 'GET', "$baseUrl/api/v1/auth/me", [
    'Accept: application/json',
    'Authorization: Bearer ' . $token
], null, 200);

runTest('Refresh Token', 'POST', "$baseUrl/api/v1/auth/refresh", [
    'Accept: application/json',
    'Authorization: Bearer ' . $token
], null, 200);

echo "\n";

// ============================================================================
// SECTION 3: SUPERADMIN ROUTES (api.compasse.net - No Tenant Required)
// ============================================================================
echo "👑 SECTION 3: SUPERADMIN ROUTES (Main Database)\n";
echo str_repeat("-", 80) . "\n";
echo "Note: These routes use api.compasse.net (excluded from tenant resolution)\n\n";

runTest('List All Tenants (Superadmin)', 'GET', "$baseUrl/api/v1/tenants", [
    'Accept: application/json',
    'Authorization: Bearer ' . $token
], null, 200);

// Get first tenant for testing
$tenantsResult = runTest('Get Tenants (for testing)', 'GET', "$baseUrl/api/v1/tenants", [
    'Accept: application/json',
    'Authorization: Bearer ' . $token
], null, 200);

$tenantsData = json_decode($tenantsResult['response'], true);
$firstTenantId = null;
if (isset($tenantsData['tenants']['data'][0]['id'])) {
    $firstTenantId = $tenantsData['tenants']['data'][0]['id'];
} elseif (isset($tenantsData['tenants'][0]['id'])) {
    $firstTenantId = $tenantsData['tenants'][0]['id'];
}

if ($firstTenantId) {
    runTest('Get Tenant Stats (Superadmin)', 'GET', "$baseUrl/api/v1/tenants/$firstTenantId/stats", [
        'Accept: application/json',
        'Authorization: Bearer ' . $token
    ], null, 200);
}

echo "\n";

// ============================================================================
// SECTION 4: TENANT-SPECIFIC ROUTES (Requires X-Tenant-ID Header)
// ============================================================================
echo "🏢 SECTION 4: TENANT-SPECIFIC ROUTES (With X-Tenant-ID Header)\n";
echo str_repeat("-", 80) . "\n";
echo "Note: These routes require tenant context via X-Tenant-ID header\n\n";

if (!$tenantId) {
    $tenantId = $firstTenantId ?? 1;
    echo "⚠️  Using Tenant ID: $tenantId (from tenants list or default)\n\n";
}

$tenantHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'Authorization: Bearer ' . $token,
    'X-Tenant-ID: ' . $tenantId
];

// School Management
echo "📚 School Management:\n";
$schoolData = [
    'tenant_id' => $tenantId,
    'name' => 'Test School ' . date('Y-m-d H:i:s'),
    'address' => '123 Test Street',
    'phone' => '+1234567890',
    'email' => 'test@school.com',
    'website' => 'https://test.school.com',
    'status' => 'active'
];

$schoolResult = runTest('Create School', 'POST', "$baseUrl/api/v1/schools", $tenantHeaders, $schoolData, 201);

$schoolId = null;
if ($schoolResult['success']) {
    $schoolResponse = json_decode($schoolResult['response'], true);
    $schoolId = $schoolResponse['school']['id'] ?? null;
    
    if ($schoolId) {
        runTest('Get School', 'GET', "$baseUrl/api/v1/schools/$schoolId", $tenantHeaders, null, 200);
        runTest('Get School Stats', 'GET', "$baseUrl/api/v1/schools/$schoolId/stats", $tenantHeaders, null, 200);
        runTest('Get School Dashboard', 'GET', "$baseUrl/api/v1/schools/$schoolId/dashboard", $tenantHeaders, null, 200);
        runTest('Get School Organogram', 'GET', "$baseUrl/api/v1/schools/$schoolId/organogram", $tenantHeaders, null, 200);
    }
}

echo "\n";

// Subscription Management
echo "💳 Subscription Management:\n";
runTest('Get Subscription Plans', 'GET', "$baseUrl/api/v1/subscriptions/plans", $tenantHeaders, null, 200);
runTest('Get Subscription Modules', 'GET', "$baseUrl/api/v1/subscriptions/modules", $tenantHeaders, null, 200);
runTest('Get Subscription Status', 'GET', "$baseUrl/api/v1/subscriptions/status", $tenantHeaders, null, 200);
runTest('Get School Modules', 'GET', "$baseUrl/api/v1/subscriptions/school/modules", $tenantHeaders, null, 200);
runTest('Get School Limits', 'GET', "$baseUrl/api/v1/subscriptions/school/limits", $tenantHeaders, null, 200);

echo "\n";

// File Upload
echo "📁 File Upload:\n";
runTest('Get Presigned URLs', 'GET', "$baseUrl/api/v1/uploads/presigned-urls?type=profile_picture&entity_type=student&entity_id=1", $tenantHeaders, null, 200);

echo "\n";

// Academic Management (may require module access)
echo "📖 Academic Management:\n";
runTest('List Academic Years', 'GET', "$baseUrl/api/v1/academic-years", $tenantHeaders, null, 200, false);
runTest('List Terms', 'GET', "$baseUrl/api/v1/terms", $tenantHeaders, null, 200, false);
runTest('List Departments', 'GET', "$baseUrl/api/v1/departments", $tenantHeaders, null, 200, false);
runTest('List Classes', 'GET', "$baseUrl/api/v1/classes", $tenantHeaders, null, 200, false);
runTest('List Subjects', 'GET', "$baseUrl/api/v1/subjects", $tenantHeaders, null, 200, false);

echo "\n";

// Student Management (may require module access)
echo "👨‍🎓 Student Management:\n";
runTest('List Students', 'GET', "$baseUrl/api/v1/students", $tenantHeaders, null, 200, false);
runTest('Generate Admission Number', 'POST', "$baseUrl/api/v1/students/generate-admission-number", $tenantHeaders, ['school_id' => $schoolId], 200, false);

echo "\n";

// Teacher Management (may require module access)
echo "👨‍🏫 Teacher Management:\n";
runTest('List Teachers', 'GET', "$baseUrl/api/v1/teachers", $tenantHeaders, null, 200, false);

echo "\n";

// Guardian Management
echo "👨‍👩‍👧 Guardian Management:\n";
runTest('List Guardians', 'GET', "$baseUrl/api/v1/guardians", $tenantHeaders, null, 200, false);

echo "\n";

// Reports
echo "📊 Reports:\n";
runTest('Academic Reports', 'GET', "$baseUrl/api/v1/reports/academic", $tenantHeaders, null, 200, false);
runTest('Financial Reports', 'GET', "$baseUrl/api/v1/reports/financial", $tenantHeaders, null, 200, false);
runTest('Attendance Reports', 'GET', "$baseUrl/api/v1/reports/attendance", $tenantHeaders, null, 200, false);
runTest('Performance Reports', 'GET', "$baseUrl/api/v1/reports/performance", $tenantHeaders, null, 200, false);

echo "\n";

// ============================================================================
// SECTION 5: SUBDOMAIN-BASED ACCESS (Testing subdomain resolution)
// ============================================================================
echo "🌐 SECTION 5: SUBDOMAIN-BASED ACCESS\n";
echo str_repeat("-", 80) . "\n";
echo "Note: Testing how subdomain requests are handled\n\n";

if ($firstTenantId) {
    // Get tenant subdomain
    $tenantResult = runTest('Get Tenant Details', 'GET', "$baseUrl/api/v1/tenants/$firstTenantId", [
        'Accept: application/json',
        'Authorization: Bearer ' . $token
    ], null, 200);
    
    $tenantDetails = json_decode($tenantResult['response'], true);
    $subdomain = $tenantDetails['tenant']['subdomain'] ?? null;
    
    if ($subdomain) {
        echo "   Testing with subdomain: $subdomain\n\n";
        
        // Test public subdomain lookup
        runTest('Get School by Subdomain (Public)', 'GET', "$baseUrl/api/v1/schools/subdomain/$subdomain", [
            'Accept: application/json'
        ], null, 200);
    }
}

echo "\n";

// ============================================================================
// SECTION 6: LOGOUT
// ============================================================================
echo "🚪 SECTION 6: LOGOUT\n";
echo str_repeat("-", 80) . "\n";

runTest('Logout', 'POST', "$baseUrl/api/v1/auth/logout", [
    'Accept: application/json',
    'Authorization: Bearer ' . $token
], null, 200);

echo "\n";

// ============================================================================
// SUMMARY
// ============================================================================
echo str_repeat("=", 80) . "\n";
echo "📊 TEST SUMMARY\n";
echo str_repeat("=", 80) . "\n";
$total = count($results['tests']);
echo "Total Tests: $total\n";
echo "✅ Passed: {$results['passed']}\n";
echo "❌ Failed: {$results['failed']}\n";
echo "⏭️  Skipped: {$results['skipped']}\n";

if ($total > 0) {
    $successRate = round(($results['passed'] / ($total - $results['skipped'])) * 100, 2);
    echo "Success Rate: $successRate%\n";
}

echo "\n";

if ($results['failed'] > 0) {
    echo "❌ FAILED TESTS:\n";
    foreach ($results['tests'] as $test) {
        if ($test['status'] === '❌ FAIL') {
            echo "  - {$test['name']}: HTTP {$test['http_code']} (expected {$test['expected']})\n";
        }
    }
    echo "\n";
}

if ($results['failed'] === 0 && $results['skipped'] === 0) {
    echo "🎉 ALL TESTS PASSED! All APIs are working correctly!\n";
    exit(0);
} elseif ($results['failed'] === 0) {
    echo "✅ All executed tests passed! (Some tests were skipped)\n";
    exit(0);
} else {
    echo "⚠️  Some tests failed. Please review the errors above.\n";
    exit(1);
}

