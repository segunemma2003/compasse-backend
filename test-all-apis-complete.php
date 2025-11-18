<?php

/**
 * Comprehensive API Test Script - Tests ALL Endpoints
 * Run: php test-all-apis-complete.php
 */

$baseUrl = 'http://127.0.0.1:8000/api/v1';
$healthUrl = 'http://127.0.0.1:8000/api/health';
$token = null;
$superAdminToken = null;
$tenantId = null;
$schoolId = null;
$studentId = null;
$teacherId = null;
$testResults = [];

// Colors for output
$green = "\033[32m";
$red = "\033[31m";
$yellow = "\033[33m";
$blue = "\033[34m";
$cyan = "\033[36m";
$reset = "\033[0m";

function testApi($method, $endpoint, $data = null, $headers = [], $description = '', $expectSuccess = true) {
    global $baseUrl, $token, $tenantId, $green, $red, $yellow, $blue, $cyan, $reset, $testResults;

    $url = $baseUrl . $endpoint;
    $fullDescription = $description ?: "$method $endpoint";

    // Add token to headers if available
    if ($token) {
        $headers['Authorization'] = 'Bearer ' . $token;
    }

    // Add tenant context if needed
    if (isset($tenantId) && $tenantId && !isset($headers['X-Tenant-ID'])) {
        $headers['X-Tenant-ID'] = $tenantId;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($data) {
            if (is_array($data) && isset($data['file'])) {
                // Handle file upload
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            } else {
                $jsonData = json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
                $headers['Content-Type'] = 'application/json';
            }
        }
    }

    // Set headers
    $headerArray = [];
    foreach ($headers as $key => $value) {
        $headerArray[] = "$key: $value";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $success = ($httpCode >= 200 && $httpCode < 300);
    if ($expectSuccess && !$success) {
        $status = "{$red}FAIL{$reset}";
    } elseif (!$expectSuccess && $success) {
        $status = "{$yellow}UNEXPECTED SUCCESS{$reset}";
    } else {
        $status = "{$green}OK{$reset}";
    }

    $result = [
        'method' => $method,
        'endpoint' => $endpoint,
        'description' => $fullDescription,
        'status_code' => $httpCode,
        'success' => $success,
        'response' => json_decode($response, true)
    ];

    $testResults[] = $result;

    echo "{$status} [{$httpCode}] {$fullDescription}\n";

    if ($error) {
        echo "  {$red}Error: {$error}{$reset}\n";
    }

    if ($httpCode >= 400) {
        $responseData = json_decode($response, true);
        if (is_array($responseData)) {
            if (isset($responseData['error'])) {
                $errorMsg = is_array($responseData['error']) ? json_encode($responseData['error']) : $responseData['error'];
                echo "  {$red}Error: {$errorMsg}{$reset}\n";
            }
            if (isset($responseData['messages'])) {
                echo "  {$red}Messages: " . json_encode($responseData['messages']) . "{$reset}\n";
            }
            if (isset($responseData['message'])) {
                echo "  {$red}Message: {$responseData['message']}{$reset}\n";
            }
        }
    }

    return $result;
}

function printSection($title) {
    global $cyan, $reset;
    echo "\n{$cyan}=== $title ==={$reset}\n";
}

function printSummary() {
    global $testResults, $green, $red, $yellow, $cyan, $reset;

    $total = count($testResults);
    $passed = count(array_filter($testResults, fn($r) => isset($r['success']) && $r['success']));
    $failed = $total - $passed;

    echo "\n\n";
    echo "{$cyan}========================================{$reset}\n";
    echo "{$cyan}TEST SUMMARY{$reset}\n";
    echo "{$cyan}========================================{$reset}\n";
    echo "Total Tests: $total\n";
    echo "{$green}Passed: $passed{$reset}\n";
    echo "{$red}Failed: $failed{$reset}\n";
    echo "{$cyan}========================================{$reset}\n";

    if ($failed > 0) {
        echo "\n{$red}Failed Tests:{$reset}\n";
        foreach ($testResults as $result) {
            if (!isset($result['success']) || !$result['success']) {
                $statusCode = isset($result['status_code']) ? $result['status_code'] : 'N/A';
                $desc = isset($result['description']) ? $result['description'] : 'Unknown';
                echo "  - {$desc} [{$statusCode}]\n";
            }
        }
    }
}

// Start testing
echo "{$blue}Starting Comprehensive API Tests...{$reset}\n";
echo "Base URL: $baseUrl\n\n";

// Check if server is running
$ch = curl_init('http://127.0.0.1:8000/api/health');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 2);
curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode == 0) {
    echo "{$red}ERROR: Laravel server is not running!{$reset}\n";
    echo "Please start the server with: php artisan serve\n";
    exit(1);
}

// 1. Health Check
printSection("Health Check");
$healthCh = curl_init('http://127.0.0.1:8000/api/health');
curl_setopt($healthCh, CURLOPT_RETURNTRANSFER, true);
curl_setopt($healthCh, CURLOPT_TIMEOUT, 5);
$healthResponse = curl_exec($healthCh);
$healthCode = curl_getinfo($healthCh, CURLINFO_HTTP_CODE);
curl_close($healthCh);

if ($healthCode == 200) {
    echo "{$green}OK [200] Health Check{$reset}\n";
    $testResults[] = ['method' => 'GET', 'endpoint' => '/api/health', 'description' => 'Health Check', 'status_code' => 200, 'success' => true, 'response' => json_decode($healthResponse, true)];
} else {
    echo "{$red}FAIL [{$healthCode}] Health Check{$reset}\n";
    $testResults[] = ['method' => 'GET', 'endpoint' => '/api/health', 'description' => 'Health Check', 'status_code' => $healthCode, 'success' => false, 'response' => json_decode($healthResponse, true)];
}

// 2. Authentication
printSection("Authentication");
$loginResult = testApi('POST', '/auth/login', [
    'email' => 'superadmin@compasse.net',
    'password' => 'Nigeria@60'
], [], 'Super Admin Login');

if ($loginResult['success'] && isset($loginResult['response']['token'])) {
    $superAdminToken = $loginResult['response']['token'];
    $token = $superAdminToken;
    echo "{$green}✓ Super Admin Token obtained{$reset}\n";
}

// 3. Tenant Management (Super Admin)
printSection("Tenant Management");
if ($superAdminToken) {
    $token = $superAdminToken;
    $tenantsResult = testApi('GET', '/tenants', null, [], 'Get Tenants List');
    if ($tenantsResult['success'] && isset($tenantsResult['response']['tenants']['data'][0]['id'])) {
        $tenantId = $tenantsResult['response']['tenants']['data'][0]['id'];
        echo "{$green}✓ Tenant ID obtained: $tenantId{$reset}\n";
    }
}

// 4. School Management (Use existing Taiwo International school)
printSection("School Management");
$schoolAdminToken = null;
$schoolAdminEmail = null;

// Use existing Taiwo International school credentials
$taiwoTenantId = '5fccca62-9378-4c0d-9a84-7de7e23cc6f5';
$taiwoSchoolId = 1; // This will be resolved from tenant DB
$taiwoAdminEmail = 'admin@taiwo-international.com';
$taiwoAdminPassword = 'Password@12345';

// Try to login as school admin first
echo "{$cyan}Logging in as Taiwo International school admin...{$reset}\n";
$adminLoginResult = testApi('POST', '/auth/login', [
    'email' => $taiwoAdminEmail,
    'password' => $taiwoAdminPassword
], ['X-Tenant-ID' => $taiwoTenantId, 'Content-Type' => 'application/json'], 'School Admin Login');

if ($adminLoginResult['success'] && isset($adminLoginResult['response']['token'])) {
    $schoolAdminToken = $adminLoginResult['response']['token'];
    $token = $schoolAdminToken;
    $tenantId = $taiwoTenantId;
    echo "{$green}✓ School Admin Token obtained{$reset}\n";
    echo "{$green}✓ Using School Admin for tenant operations{$reset}\n";
} else {
    echo "{$yellow}⚠ School Admin login failed, using superadmin token{$reset}\n";
    $token = $superAdminToken;
    $tenantId = $taiwoTenantId;
}

// Set headers for tenant operations
$headers = ['X-Tenant-ID' => $tenantId];

// Test school endpoints
testApi('GET', '/schools', null, $headers, 'List Schools');

// Get school ID from tenant database
$schoolsResult = testApi('GET', '/schools', null, $headers, 'Get Schools for ID');
if ($schoolsResult['success'] && isset($schoolsResult['response']['data'][0]['id'])) {
    $schoolId = $schoolsResult['response']['data'][0]['id'];
    echo "{$green}✓ School ID obtained: $schoolId{$reset}\n";
    
    testApi('GET', "/schools/$schoolId", null, $headers, 'Get School Details');
    testApi('GET', "/schools/$schoolId/stats", null, $headers, 'Get School Stats');
    testApi('GET', "/schools/$schoolId/dashboard", null, $headers, 'Get School Dashboard');
} else {
    echo "{$yellow}⚠ Could not get school ID from tenant database{$reset}\n";
}

// 5. User Management (Use school admin token)
printSection("User Management");
if ($tenantId) {
    // Use school admin token if available, otherwise superadmin
    if ($schoolAdminToken) {
        $token = $schoolAdminToken;
    }
    $headers = ['X-Tenant-ID' => $tenantId];
    testApi('GET', '/users', null, $headers, 'List Users');
    testApi('GET', '/users?role=teacher', null, $headers, 'List Users by Role');
}

// 6. Password Reset
printSection("Password Reset");
testApi('POST', '/auth/forgot-password', [
    'email' => 'test@example.com'
], [], 'Forgot Password');

// 7. Quiz System
printSection("Quiz System");
if ($tenantId) {
    $headers = ['X-Tenant-ID' => $tenantId];
    $quizData = [
        'name' => 'Test Quiz',
        'description' => 'A test quiz',
        'duration_minutes' => 30,
        'total_marks' => 100,
    ];
    $quizResult = testApi('POST', '/quizzes', $quizData, $headers, 'Create Quiz');
    $quizId = null;
    if ($quizResult['success'] && isset($quizResult['response']['quiz']['id'])) {
        $quizId = $quizResult['response']['quiz']['id'];
    }

    testApi('GET', '/quizzes', null, $headers, 'List Quizzes');
    if ($quizId) {
        testApi('GET', "/quizzes/$quizId", null, $headers, 'Get Quiz Details');
    }
}

// 8. Grades System (Use school admin token)
printSection("Grades System");
if ($tenantId) {
    if ($schoolAdminToken) {
        $token = $schoolAdminToken;
    }
    $headers = ['X-Tenant-ID' => $tenantId];
    testApi('GET', '/grades', null, $headers, 'List Grades');
}

// 9. Timetable (Use school admin token)
printSection("Timetable Management");
if ($tenantId) {
    if ($schoolAdminToken) {
        $token = $schoolAdminToken;
    }
    $headers = ['X-Tenant-ID' => $tenantId];
    testApi('GET', '/timetable', null, $headers, 'Get Timetable');
}

// 10. Announcements (Use school admin token)
printSection("Announcements");
if ($tenantId) {
    if ($schoolAdminToken) {
        $token = $schoolAdminToken;
    }
    $headers = ['X-Tenant-ID' => $tenantId];
    $announcementData = [
        'title' => 'Test Announcement',
        'content' => 'This is a test announcement',
        'type' => 'general',
    ];
    $announcementResult = testApi('POST', '/announcements', $announcementData, $headers, 'Create Announcement');
    $announcementId = null;
    if ($announcementResult['success'] && isset($announcementResult['response']['announcement']['id'])) {
        $announcementId = $announcementResult['response']['announcement']['id'];
        testApi('POST', "/announcements/$announcementId/publish", null, $headers, 'Publish Announcement');
    }
    testApi('GET', '/announcements', null, $headers, 'List Announcements');
}

// 11. Library
printSection("Library Management");
if ($tenantId) {
    $headers = ['X-Tenant-ID' => $tenantId];
    testApi('GET', '/library/books', null, $headers, 'List Books');
    testApi('GET', '/library/stats', null, $headers, 'Get Library Stats');
}

// 12. Houses
printSection("Houses System");
if ($tenantId) {
    $headers = ['X-Tenant-ID' => $tenantId];
    testApi('GET', '/houses', null, $headers, 'List Houses');
}

// 13. Sports
printSection("Sports Management");
if ($tenantId) {
    $headers = ['X-Tenant-ID' => $tenantId];
    testApi('GET', '/sports/activities', null, $headers, 'List Sports Activities');
    testApi('GET', '/sports/teams', null, $headers, 'List Sports Teams');
    testApi('GET', '/sports/events', null, $headers, 'List Sports Events');
}

// 14. Staff
printSection("Staff Management");
if ($tenantId) {
    $headers = ['X-Tenant-ID' => $tenantId];
    testApi('GET', '/staff', null, $headers, 'List Staff');
}

// 15. Achievements
printSection("Achievements");
if ($tenantId) {
    $headers = ['X-Tenant-ID' => $tenantId];
    testApi('GET', '/achievements', null, $headers, 'List Achievements');
}

// 16. Settings
printSection("Settings");
if ($tenantId) {
    $headers = ['X-Tenant-ID' => $tenantId];
    testApi('GET', '/settings', null, $headers, 'Get Settings');
}

// 17. Dashboards
printSection("Role-Specific Dashboards");
if ($tenantId) {
    $headers = ['X-Tenant-ID' => $tenantId];
    testApi('GET', '/dashboard/admin', null, $headers, 'Admin Dashboard');
    testApi('GET', '/dashboard/teacher', null, $headers, 'Teacher Dashboard');
    testApi('GET', '/dashboard/student', null, $headers, 'Student Dashboard');
    testApi('GET', '/dashboard/parent', null, $headers, 'Parent Dashboard');
}

// 18. Financial (Fees & Payments)
printSection("Financial Management");
if ($tenantId) {
    $headers = ['X-Tenant-ID' => $tenantId];
    testApi('GET', '/fees', null, $headers, 'List Fees');
    testApi('GET', '/payments', null, $headers, 'List Payments');
}

// 19. Subscriptions
printSection("Subscriptions");
if ($tenantId) {
    $headers = ['X-Tenant-ID' => $tenantId];
    testApi('GET', '/subscriptions/plans', null, $headers, 'Get Subscription Plans');
    testApi('GET', '/subscriptions/modules', null, $headers, 'Get Modules');
    testApi('GET', '/subscriptions/status', null, $headers, 'Get Subscription Status');
}

// 20. File Upload
printSection("File Upload");
if ($tenantId) {
    $headers = ['X-Tenant-ID' => $tenantId];
    testApi('POST', '/uploads/presigned-urls', [
        'type' => 'image',
        'entity_type' => 'school',
        'entity_id' => $schoolId ?? 1
    ], $headers, 'Get Presigned URLs');
}

// 21. Academic Management
printSection("Academic Management");
if ($tenantId) {
    $headers = ['X-Tenant-ID' => $tenantId];
    testApi('GET', '/academic-years', null, $headers, 'List Academic Years');
    testApi('GET', '/terms', null, $headers, 'List Terms');
    testApi('GET', '/classes', null, $headers, 'List Classes');
    testApi('GET', '/subjects', null, $headers, 'List Subjects');
}

// 22. Student Management (Use school admin token)
printSection("Student Management");
if ($tenantId) {
    if ($schoolAdminToken) {
        $token = $schoolAdminToken;
    }
    $headers = ['X-Tenant-ID' => $tenantId];
    testApi('GET', '/students', null, $headers, 'List Students');
}

// 23. Teacher Management (Use school admin token)
printSection("Teacher Management");
if ($tenantId) {
    if ($schoolAdminToken) {
        $token = $schoolAdminToken;
    }
    $headers = ['X-Tenant-ID' => $tenantId];
    testApi('GET', '/teachers', null, $headers, 'List Teachers');
}

// 24. Attendance (Use school admin token)
printSection("Attendance");
if ($tenantId) {
    if ($schoolAdminToken) {
        $token = $schoolAdminToken;
    }
    $headers = ['X-Tenant-ID' => $tenantId];
    testApi('GET', '/attendance/students', null, $headers, 'Get Student Attendance');
    testApi('GET', '/attendance/teachers', null, $headers, 'Get Teacher Attendance');
}

// 25. Reports (Use school admin token)
printSection("Reports");
if ($tenantId) {
    if ($schoolAdminToken) {
        $token = $schoolAdminToken;
    }
    $headers = ['X-Tenant-ID' => $tenantId];
    testApi('GET', '/reports/academic', null, $headers, 'Academic Report');
    testApi('GET', '/reports/financial', null, $headers, 'Financial Report');
    testApi('GET', '/reports/attendance', null, $headers, 'Attendance Report');
}

// 26. Super Admin Analytics
printSection("Super Admin Analytics");
if ($superAdminToken) {
    $token = $superAdminToken;
    testApi('GET', '/super-admin/analytics', null, [], 'Super Admin Analytics');
    testApi('GET', '/super-admin/database', null, [], 'Database Status');
    testApi('GET', '/super-admin/security', null, [], 'Security Logs');
}

// Print summary
printSummary();

echo "\n{$blue}Testing complete!{$reset}\n";

