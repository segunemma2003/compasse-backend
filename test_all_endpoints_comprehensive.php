<?php

/**
 * Comprehensive API Test - All Endpoints
 * Tests both Super Admin and School Admin capabilities
 */

$baseUrl = 'http://localhost:8000/api/v1';

// Test configurations
$superAdminEmail = 'superadmin@compasse.net';
$superAdminPassword = 'Nigeria@60';

$schoolSubdomain = 'testsch927320';
$schoolAdminEmail = "admin@{$schoolSubdomain}.samschool.com";
$schoolAdminPassword = 'Password@12345';

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
    $result = apiRequest($method, $endpoint, $headers, $data);
    $passed = $result['code'] === $expectedCode;
    
    echo sprintf("%-70s", $testName);
    echo ($passed ? "✓ PASS" : "✗ FAIL ({$result['code']})");
    echo "\n";
    
    if (!$passed && isset($result['body']['error'])) {
        echo "   Error: {$result['body']['error']}\n";
    }
    
    return ['passed' => $passed, 'result' => $result];
}

$stats = ['total' => 0, 'passed' => 0, 'failed' => 0];

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║           COMPREHENSIVE API TEST - ALL ENDPOINTS                           ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";

// ============================================================================
// SUPER ADMIN TESTS
// ============================================================================

echo "\n";
echo "┌────────────────────────────────────────────────────────────────────────────┐\n";
echo "│  SUPER ADMIN TESTS                                                         │\n";
echo "└────────────────────────────────────────────────────────────────────────────┘\n\n";

// Super Admin Login
$test = testEndpoint(
    'Super Admin Login',
    'POST',
    "$baseUrl/auth/login",
    [],
    ['email' => $superAdminEmail, 'password' => $superAdminPassword]
);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$superAdminToken = $test['result']['body']['token'] ?? null;

if (!$superAdminToken) {
    echo "\n⚠️  Super Admin login failed - skipping super admin tests\n";
    $superAdminHeaders = [];
} else {
    $superAdminHeaders = [
        "Authorization: Bearer $superAdminToken",
        "Accept: application/json"
    ];
    
    echo "\n--- School Management (Super Admin) ---\n";
    
    // List all schools (super admin view)
    $test = testEndpoint('List All Schools (Global)', 'GET', "$baseUrl/schools", $superAdminHeaders);
    $stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;
    
    // Create new school
    $newSchoolData = [
        'name' => 'Test School ' . time(),
        'subdomain' => 'testsch' . time(),
        'email' => 'test' . time() . '@school.com',
        'phone' => '+1234567890',
        'address' => '123 Test St',
        'code' => 'TST' . substr(time(), -3),
        'admin_name' => 'Test Admin',
        'admin_email' => 'admin' . time() . '@test.com',
        'admin_password' => 'Password@12345',
        'admin_password_confirmation' => 'Password@12345'
    ];
    
    $test = testEndpoint(
        'Create New School',
        'POST',
        "$baseUrl/schools",
        $superAdminHeaders,
        $newSchoolData,
        201
    );
    $stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;
    
    $createdSchoolId = $test['result']['body']['data']['id'] ?? null;
    
    if ($createdSchoolId) {
        // View created school
        $test = testEndpoint(
            'View Created School',
            'GET',
            "$baseUrl/schools/$createdSchoolId",
            $superAdminHeaders
        );
        $stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;
        
        // Delete created school
        $test = testEndpoint(
            'Delete Created School',
            'DELETE',
            "$baseUrl/schools/$createdSchoolId",
            $superAdminHeaders
        );
        $stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;
    }
    
    echo "\n--- Tenant Management (Super Admin) ---\n";
    
    $test = testEndpoint('List Tenants', 'GET', "$baseUrl/tenants", $superAdminHeaders);
    $stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;
}

// ============================================================================
// SCHOOL ADMIN TESTS
// ============================================================================

echo "\n";
echo "┌────────────────────────────────────────────────────────────────────────────┐\n";
echo "│  SCHOOL ADMIN TESTS                                                        │\n";
echo "└────────────────────────────────────────────────────────────────────────────┘\n\n";

// School Admin Login
$test = testEndpoint(
    'School Admin Login',
    'POST',
    "$baseUrl/auth/login",
    ["X-Subdomain: $schoolSubdomain"],
    ['email' => $schoolAdminEmail, 'password' => $schoolAdminPassword]
);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$schoolAdminToken = $test['result']['body']['token'] ?? null;

if (!$schoolAdminToken) {
    echo "\n⚠️  School Admin login failed - skipping school admin tests\n";
    exit(1);
}

$schoolAdminHeaders = [
    "X-Subdomain: $schoolSubdomain",
    "Authorization: Bearer $schoolAdminToken",
    "Accept: application/json"
];

echo "\n--- User Management ---\n";

$test = testEndpoint('List Users', 'GET', "$baseUrl/users", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$test = testEndpoint('List Users (Filter by Teacher)', 'GET', "$baseUrl/users?role=teacher", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$test = testEndpoint('Search Users', 'GET', "$baseUrl/users?search=admin", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

echo "\n--- Student Management ---\n";

$test = testEndpoint('List Students', 'GET', "$baseUrl/students", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$test = testEndpoint('Student Statistics', 'GET', "$baseUrl/students/statistics", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

echo "\n--- Staff Management ---\n";

$test = testEndpoint('List Staff', 'GET', "$baseUrl/staff", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

echo "\n--- Academic Management ---\n";

$test = testEndpoint('List Classes', 'GET', "$baseUrl/classes", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$test = testEndpoint('List Subjects', 'GET', "$baseUrl/subjects", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$test = testEndpoint('List Sessions', 'GET', "$baseUrl/sessions", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$test = testEndpoint('List Terms', 'GET', "$baseUrl/terms", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

echo "\n--- Attendance ---\n";

$test = testEndpoint('Attendance Overview', 'GET', "$baseUrl/attendance", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$test = testEndpoint('Attendance Statistics', 'GET', "$baseUrl/attendance/statistics", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

echo "\n--- Assignments ---\n";

$test = testEndpoint('List Assignments', 'GET', "$baseUrl/assignments", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

echo "\n--- Exams & Results ---\n";

$test = testEndpoint('List Exams', 'GET', "$baseUrl/exams", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$test = testEndpoint('List Results', 'GET', "$baseUrl/results", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

echo "\n--- Timetable ---\n";

$test = testEndpoint('List Timetables', 'GET', "$baseUrl/timetables", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

echo "\n--- Fees & Payments ---\n";

$test = testEndpoint('List Fee Structures', 'GET', "$baseUrl/fees", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$test = testEndpoint('List Payments', 'GET', "$baseUrl/payments", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$test = testEndpoint('Payment Statistics', 'GET', "$baseUrl/payments/statistics", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

echo "\n--- Communication ---\n";

$test = testEndpoint('List Notifications', 'GET', "$baseUrl/notifications", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$test = testEndpoint('List Announcements', 'GET', "$baseUrl/announcements", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$test = testEndpoint('List Messages', 'GET', "$baseUrl/messages", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

echo "\n--- Library ---\n";

$test = testEndpoint('List Books', 'GET', "$baseUrl/library/books", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$test = testEndpoint('List Borrowed Books', 'GET', "$baseUrl/library/borrowed", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

echo "\n--- Transport ---\n";

$test = testEndpoint('List Vehicles', 'GET', "$baseUrl/transport/vehicles", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$test = testEndpoint('List Routes', 'GET', "$baseUrl/transport/routes", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$test = testEndpoint('List Drivers', 'GET', "$baseUrl/drivers", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

echo "\n--- Reports ---\n";

$test = testEndpoint('Student Reports', 'GET', "$baseUrl/reports/students", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$test = testEndpoint('Financial Reports', 'GET', "$baseUrl/reports/financial", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$test = testEndpoint('Attendance Reports', 'GET', "$baseUrl/reports/attendance", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

echo "\n--- Dashboards ---\n";

$test = testEndpoint('School Dashboard', 'GET', "$baseUrl/dashboard/school", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

$test = testEndpoint('Admin Dashboard', 'GET', "$baseUrl/schools/1/dashboard", $schoolAdminHeaders);
$stats['total']++; $test['passed'] ? $stats['passed']++ : $stats['failed']++;

// ============================================================================
// FINAL SUMMARY
// ============================================================================

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                           FINAL SUMMARY                                    ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "Total Tests:    {$stats['total']}\n";
echo "Passed:         {$stats['passed']} ✓\n";
echo "Failed:         {$stats['failed']} ✗\n";
echo "Success Rate:   " . round(($stats['passed'] / $stats['total']) * 100, 2) . "%\n";
echo "\n";

if ($stats['failed'] === 0) {
    echo "🎉 ALL TESTS PASSED! 🎉\n";
    exit(0);
} else {
    echo "⚠️  {$stats['failed']} TEST(S) FAILED\n";
    exit(1);
}

