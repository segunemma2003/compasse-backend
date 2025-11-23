<?php

/**
 * Comprehensive School Admin API Test Script
 * Tests all major admin endpoints with proper tenant context
 */

$baseUrl = 'http://localhost:8000/api/v1';
$subdomain = 'testsch927320';
$adminEmail = "admin@{$subdomain}.samschool.com";
$adminPassword = 'Password@12345';

function apiRequest($method, $url, $headers = [], $data = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $headers[] = 'Content-Type: application/json';
    }
    
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Extract JSON from response (skip PHP notices/warnings)
    $jsonStart = strpos($response, '{');
    if ($jsonStart !== false) {
        $response = substr($response, $jsonStart);
    }
    
    return ['code' => $httpCode, 'body' => json_decode($response, true)];
}

function testEndpoint($testName, $method, $endpoint, $headers = [], $data = null, $expectedCode = 200) {
    echo "\n" . str_repeat('=', 80) . "\n";
    echo "TEST: $testName\n";
    echo str_repeat('=', 80) . "\n";
    echo "Endpoint: $method $endpoint\n";
    
    $result = apiRequest($method, $endpoint, $headers, $data);
    
    $passed = $result['code'] === $expectedCode;
    echo "Expected: $expectedCode | Got: {$result['code']} | " . ($passed ? "✓ PASS" : "✗ FAIL") . "\n";
    
    if (!$passed || !empty($result['body']['error'])) {
        echo "\nResponse:\n";
        print_r($result['body']);
    }
    
    return ['passed' => $passed, 'result' => $result];
}

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║           SCHOOL ADMIN API COMPREHENSIVE TEST SUITE                       ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
echo "\nSubdomain: $subdomain\n";
echo "Admin Email: $adminEmail\n";

$results = ['total' => 0, 'passed' => 0, 'failed' => 0];

// ============================================================================
// 1. AUTHENTICATION TESTS
// ============================================================================

echo "\n\n";
echo "┌────────────────────────────────────────────────────────────────────────────┐\n";
echo "│  1. AUTHENTICATION TESTS                                                   │\n";
echo "└────────────────────────────────────────────────────────────────────────────┘\n";

// Test 1.1: Admin Login
$test = testEndpoint(
    '1.1 School Admin Login',
    'POST',
    "$baseUrl/auth/login",
    ["X-Subdomain: $subdomain"],
    ['email' => $adminEmail, 'password' => $adminPassword]
);
$results['total']++;
if ($test['passed']) {
    $results['passed']++;
    $token = $test['result']['body']['token'] ?? null;
    echo "✓ Token received: " . substr($token, 0, 20) . "...\n";
} else {
    $results['failed']++;
    echo "✗ Login failed - cannot continue with authenticated tests\n";
    exit(1);
}

$authHeaders = [
    "X-Subdomain: $subdomain",
    "Authorization: Bearer $token",
    "Accept: application/json"
];

// Test 1.2: Get Current User
$test = testEndpoint(
    '1.2 Get Current User (/auth/me)',
    'GET',
    "$baseUrl/auth/me",
    $authHeaders
);
$results['total']++;
$test['passed'] ? $results['passed']++ : $results['failed']++;

if ($test['passed'] && isset($test['result']['body']['user'])) {
    $user = $test['result']['body']['user'];
    echo "✓ User: {$user['name']} ({$user['role']})\n";
}

// ============================================================================
// 2. SCHOOL MANAGEMENT TESTS
// ============================================================================

echo "\n\n";
echo "┌────────────────────────────────────────────────────────────────────────────┐\n";
echo "│  2. SCHOOL MANAGEMENT TESTS                                                │\n";
echo "└────────────────────────────────────────────────────────────────────────────┘\n";

// Test 2.1: List Schools
$test = testEndpoint(
    '2.1 List Schools',
    'GET',
    "$baseUrl/schools",
    $authHeaders
);
$results['total']++;
$test['passed'] ? $results['passed']++ : $results['failed']++;

$schoolId = null;
if ($test['passed'] && isset($test['result']['body']['data']) && count($test['result']['body']['data']) > 0) {
    $schoolId = $test['result']['body']['data'][0]['id'];
    echo "✓ Found " . count($test['result']['body']['data']) . " school(s)\n";
    echo "✓ School ID: $schoolId\n";
}

// Test 2.2: Show School Details
if ($schoolId) {
    $test = testEndpoint(
        '2.2 Get School Details',
        'GET',
        "$baseUrl/schools/$schoolId",
        $authHeaders
    );
    $results['total']++;
    $test['passed'] ? $results['passed']++ : $results['failed']++;
    
    if ($test['passed'] && isset($test['result']['body']['data'])) {
        $school = $test['result']['body']['data'];
        echo "✓ School: {$school['name']}\n";
    }
} else {
    echo "⚠ Skipping school detail test - no school ID available\n";
}

// Test 2.3: Update School
if ($schoolId) {
    $test = testEndpoint(
        '2.3 Update School',
        'PUT',
        "$baseUrl/schools/$schoolId",
        $authHeaders,
        [
            'name' => 'Updated School Name ' . time(),
            'phone' => '+1234567890',
            'email' => 'updated@school.com'
        ]
    );
    $results['total']++;
    $test['passed'] ? $results['passed']++ : $results['failed']++;
    
    if ($test['passed']) {
        echo "✓ School updated successfully\n";
    }
} else {
    echo "⚠ Skipping school update test - no school ID available\n";
}

// ============================================================================
// 3. USER MANAGEMENT TESTS
// ============================================================================

echo "\n\n";
echo "┌────────────────────────────────────────────────────────────────────────────┐\n";
echo "│  3. USER MANAGEMENT TESTS                                                  │\n";
echo "└────────────────────────────────────────────────────────────────────────────┘\n";

// Test 3.1: List Users
$test = testEndpoint(
    '3.1 List Users',
    'GET',
    "$baseUrl/users",
    $authHeaders
);
$results['total']++;
$test['passed'] ? $results['passed']++ : $results['failed']++;

if ($test['passed']) {
    $userCount = is_array($test['result']['body']['data'] ?? null) 
        ? count($test['result']['body']['data']) 
        : (is_array($test['result']['body'] ?? null) ? count($test['result']['body']) : 0);
    echo "✓ Found $userCount user(s)\n";
}

// Test 3.2: Create New User
$newUserEmail = "teacher_" . time() . "@{$subdomain}.samschool.com";
$test = testEndpoint(
    '3.2 Create New User (Teacher)',
    'POST',
    "$baseUrl/users",
    $authHeaders,
    [
        'name' => 'Test Teacher',
        'email' => $newUserEmail,
        'password' => 'Teacher@12345',
        'password_confirmation' => 'Teacher@12345',
        'role' => 'teacher',
        'phone' => '+1234567890'
    ],
    201
);
$results['total']++;
$test['passed'] ? $results['passed']++ : $results['failed']++;

$newUserId = null;
if ($test['passed'] && isset($test['result']['body']['data']['id'])) {
    $newUserId = $test['result']['body']['data']['id'];
    echo "✓ User created with ID: $newUserId\n";
}

// Test 3.3: Get User Details
if ($newUserId) {
    $test = testEndpoint(
        '3.3 Get User Details',
        'GET',
        "$baseUrl/users/$newUserId",
        $authHeaders
    );
    $results['total']++;
    $test['passed'] ? $results['passed']++ : $results['failed']++;
}

// Test 3.4: Update User
if ($newUserId) {
    $test = testEndpoint(
        '3.4 Update User',
        'PUT',
        "$baseUrl/users/$newUserId",
        $authHeaders,
        [
            'name' => 'Updated Teacher Name',
            'phone' => '+0987654321'
        ]
    );
    $results['total']++;
    $test['passed'] ? $results['passed']++ : $results['failed']++;
}

// ============================================================================
// 4. SUBSCRIPTION TESTS
// ============================================================================

echo "\n\n";
echo "┌────────────────────────────────────────────────────────────────────────────┐\n";
echo "│  4. SUBSCRIPTION TESTS                                                     │\n";
echo "└────────────────────────────────────────────────────────────────────────────┘\n";

// Test 4.1: Get Subscription Status
$test = testEndpoint(
    '4.1 Get Subscription Status',
    'GET',
    "$baseUrl/subscriptions/status",
    $authHeaders
);
$results['total']++;
$test['passed'] ? $results['passed']++ : $results['failed']++;

// Test 4.2: Get Available Plans
$test = testEndpoint(
    '4.2 Get Available Plans',
    'GET',
    "$baseUrl/subscriptions/plans",
    $authHeaders
);
$results['total']++;
$test['passed'] ? $results['passed']++ : $results['failed']++;

// Test 4.3: Get Available Modules
$test = testEndpoint(
    '4.3 Get Available Modules',
    'GET',
    "$baseUrl/subscriptions/modules",
    $authHeaders
);
$results['total']++;
$test['passed'] ? $results['passed']++ : $results['failed']++;

// ============================================================================
// 5. SETTINGS TESTS
// ============================================================================

echo "\n\n";
echo "┌────────────────────────────────────────────────────────────────────────────┐\n";
echo "│  5. SETTINGS TESTS                                                         │\n";
echo "└────────────────────────────────────────────────────────────────────────────┘\n";

// Test 5.1: Get Settings
$test = testEndpoint(
    '5.1 Get Settings',
    'GET',
    "$baseUrl/settings",
    $authHeaders
);
$results['total']++;
$test['passed'] ? $results['passed']++ : $results['failed']++;

// Test 5.2: Get School Settings
$test = testEndpoint(
    '5.2 Get School Settings',
    'GET',
    "$baseUrl/settings/school",
    $authHeaders
);
$results['total']++;
$test['passed'] ? $results['passed']++ : $results['failed']++;

// ============================================================================
// 6. CLEANUP & LOGOUT
// ============================================================================

echo "\n\n";
echo "┌────────────────────────────────────────────────────────────────────────────┐\n";
echo "│  6. CLEANUP & LOGOUT                                                       │\n";
echo "└────────────────────────────────────────────────────────────────────────────┘\n";

// Delete created user if exists
if ($newUserId) {
    $test = testEndpoint(
        '6.1 Delete Created User',
        'DELETE',
        "$baseUrl/users/$newUserId",
        $authHeaders,
        null,
        200
    );
    $results['total']++;
    $test['passed'] ? $results['passed']++ : $results['failed']++;
}

// Test Logout
$test = testEndpoint(
    '6.2 Logout',
    'POST',
    "$baseUrl/auth/logout",
    $authHeaders
);
$results['total']++;
$test['passed'] ? $results['passed']++ : $results['failed']++;

// ============================================================================
// FINAL SUMMARY
// ============================================================================

echo "\n\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                           TEST SUMMARY                                     ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "Total Tests:  {$results['total']}\n";
echo "Passed:       {$results['passed']} ✓\n";
echo "Failed:       {$results['failed']} ✗\n";
echo "Success Rate: " . round(($results['passed'] / $results['total']) * 100, 2) . "%\n";
echo "\n";

if ($results['failed'] === 0) {
    echo "🎉 ALL TESTS PASSED! 🎉\n";
    exit(0);
} else {
    echo "⚠️  SOME TESTS FAILED - Review output above for details\n";
    exit(1);
}

