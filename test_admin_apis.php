<?php

/**
 * Comprehensive School Admin API Test Script
 * Tests all major endpoints available to school admins
 */

$baseUrl = 'http://localhost:8000/api/v1';
$superAdminEmail = 'superadmin@compasse.net';
$superAdminPassword = 'Nigeria@60';

// Color output functions
function success($msg) { echo "\033[32m✓ $msg\033[0m\n"; }
function error($msg) { echo "\033[31m✗ $msg\033[0m\n"; }
function info($msg) { echo "\033[34mℹ $msg\033[0m\n"; }
function section($msg) { echo "\n\033[1;33m=== $msg ===\033[0m\n"; }

// Helper function to make API requests
function apiRequest($url, $method = 'GET', $data = null, $token = null, $subdomain = null) {
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = "Authorization: Bearer $token";
    }
    if ($subdomain) {
        $headers[] = "X-Subdomain: $subdomain";
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'status' => $httpCode,
        'body' => json_decode($response, true),
        'raw' => $response
    ];
}

// Test results tracker
$testResults = [
    'passed' => 0,
    'failed' => 0,
    'skipped' => 0
];

function recordTest($passed, $name) {
    global $testResults;
    if ($passed) {
        $testResults['passed']++;
        success($name);
    } else {
        $testResults['failed']++;
        error($name);
    }
}

try {
    section("1. AUTHENTICATION TESTS");
    
    // Login as Super Admin
    info("Logging in as Super Admin...");
    $loginResponse = apiRequest(
        "$baseUrl/auth/login",
        'POST',
        [
            'email' => $superAdminEmail,
            'password' => $superAdminPassword
        ]
    );
    
    if ($loginResponse['status'] !== 200 || !isset($loginResponse['body']['token'])) {
        error("Failed to login as Super Admin");
        echo json_encode($loginResponse['body'], JSON_PRETTY_PRINT) . "\n";
        exit(1);
    }
    
    $superAdminToken = $loginResponse['body']['token'];
    success("Super Admin logged in successfully");
    
    // Create a test school
    section("2. SCHOOL CREATION");
    info("Creating test school...");
    $schoolName = "Test School " . time();
    $subdomain = "testsch" . substr(time(), -6);
    
    $createSchoolResponse = apiRequest(
        "$baseUrl/schools",
        'POST',
        [
            'name' => $schoolName,
            'subdomain' => $subdomain,
            'email' => "info@{$subdomain}.com",
            'phone' => '+1234567890',
            'address' => '123 Test Street',
            'city' => 'Test City',
            'state' => 'Test State',
            'country' => 'Test Country'
        ],
        $superAdminToken
    );
    
    if ($createSchoolResponse['status'] !== 201) {
        error("Failed to create school");
        echo json_encode($createSchoolResponse['body'], JSON_PRETTY_PRINT) . "\n";
        exit(1);
    }
    
    success("School created successfully: $schoolName (subdomain: $subdomain)");
    $schoolId = $createSchoolResponse['body']['data']['id'] ?? $createSchoolResponse['body']['id'];
    
    // Login as School Admin
    section("3. SCHOOL ADMIN LOGIN");
    info("Logging in as School Admin...");
    $adminEmail = "admin@{$subdomain}.samschool.com"; // Stancl's TenantSeeder format
    $adminPassword = "Password@12345";
    
    sleep(2); // Wait for database setup
    
    $adminLoginResponse = apiRequest(
        "$baseUrl/auth/login",
        'POST',
        [
            'email' => $adminEmail,
            'password' => $adminPassword
        ],
        null,
        $subdomain
    );
    
    if ($adminLoginResponse['status'] !== 200 || !isset($adminLoginResponse['body']['token'])) {
        error("Failed to login as School Admin");
        echo "Response: " . json_encode($adminLoginResponse['body'], JSON_PRETTY_PRINT) . "\n";
        exit(1);
    }
    
    $adminToken = $adminLoginResponse['body']['token'];
    success("School Admin logged in successfully");
    info("Admin Email: $adminEmail | Password: $adminPassword");
    
    // Test Admin Profile
    section("4. ADMIN PROFILE & AUTH");
    $meResponse = apiRequest("$baseUrl/auth/me", 'GET', null, $adminToken, $subdomain);
    recordTest($meResponse['status'] === 200, "Get admin profile (/auth/me)");
    
    // Test School Management
    section("5. SCHOOL MANAGEMENT");
    
    $schoolsResponse = apiRequest("$baseUrl/schools", 'GET', null, $adminToken, $subdomain);
    recordTest($schoolsResponse['status'] === 200, "List schools");
    
    $schoolDetailResponse = apiRequest("$baseUrl/schools/$schoolId", 'GET', null, $adminToken, $subdomain);
    recordTest($schoolDetailResponse['status'] === 200, "Get school details");
    
    $updateSchoolResponse = apiRequest(
        "$baseUrl/schools/$schoolId",
        'PUT',
        ['website' => "https://{$subdomain}.example.com"],
        $adminToken,
        $subdomain
    );
    recordTest($updateSchoolResponse['status'] === 200, "Update school details");
    
    // Test User Management
    section("6. USER MANAGEMENT");
    
    $usersResponse = apiRequest("$baseUrl/users", 'GET', null, $adminToken, $subdomain);
    recordTest($usersResponse['status'] === 200, "List users");
    
    $createUserResponse = apiRequest(
        "$baseUrl/users",
        'POST',
        [
            'name' => 'Test Teacher',
            'email' => "teacher." . time() . "@test.com",
            'password' => 'Password@123',
            'role' => 'teacher',
            'status' => 'active'
        ],
        $adminToken,
        $subdomain
    );
    recordTest(in_array($createUserResponse['status'], [200, 201]), "Create user (teacher)");
    
    $userId = $createUserResponse['body']['data']['id'] ?? $createUserResponse['body']['id'] ?? null;
    
    if ($userId) {
        $getUserResponse = apiRequest("$baseUrl/users/$userId", 'GET', null, $adminToken, $subdomain);
        recordTest($getUserResponse['status'] === 200, "Get user details");
        
        $updateUserResponse = apiRequest(
            "$baseUrl/users/$userId",
            'PUT',
            ['name' => 'Updated Teacher Name'],
            $adminToken,
            $subdomain
        );
        recordTest($updateUserResponse['status'] === 200, "Update user");
    } else {
        error("Skipping user detail tests - no user ID");
        $testResults['skipped'] += 2;
    }
    
    // Test Academic Management
    section("7. ACADEMIC MANAGEMENT");
    
    // Academic Years
    $academicYearsResponse = apiRequest("$baseUrl/academic-years", 'GET', null, $adminToken, $subdomain);
    recordTest($academicYearsResponse['status'] === 200, "List academic years");
    
    $createAcademicYearResponse = apiRequest(
        "$baseUrl/academic-years",
        'POST',
        [
            'name' => '2024/2025',
            'start_date' => '2024-09-01',
            'end_date' => '2025-07-31',
            'status' => 'active'
        ],
        $adminToken,
        $subdomain
    );
    recordTest(in_array($createAcademicYearResponse['status'], [200, 201]), "Create academic year");
    
    // Terms
    $termsResponse = apiRequest("$baseUrl/terms", 'GET', null, $adminToken, $subdomain);
    recordTest($termsResponse['status'] === 200, "List terms");
    
    // Departments
    $departmentsResponse = apiRequest("$baseUrl/departments", 'GET', null, $adminToken, $subdomain);
    recordTest($departmentsResponse['status'] === 200, "List departments");
    
    $createDepartmentResponse = apiRequest(
        "$baseUrl/departments",
        'POST',
        [
            'name' => 'Science Department',
            'code' => 'SCI',
            'description' => 'Science subjects',
            'status' => 'active'
        ],
        $adminToken,
        $subdomain
    );
    recordTest(in_array($createDepartmentResponse['status'], [200, 201]), "Create department");
    
    // Classes
    $classesResponse = apiRequest("$baseUrl/classes", 'GET', null, $adminToken, $subdomain);
    recordTest($classesResponse['status'] === 200, "List classes");
    
    $createClassResponse = apiRequest(
        "$baseUrl/classes",
        'POST',
        [
            'name' => 'Class 1A',
            'level' => '1',
            'section' => 'A',
            'capacity' => 30,
            'status' => 'active'
        ],
        $adminToken,
        $subdomain
    );
    recordTest(in_array($createClassResponse['status'], [200, 201]), "Create class");
    
    // Subjects
    $subjectsResponse = apiRequest("$baseUrl/subjects", 'GET', null, $adminToken, $subdomain);
    recordTest($subjectsResponse['status'] === 200, "List subjects");
    
    $createSubjectResponse = apiRequest(
        "$baseUrl/subjects",
        'POST',
        [
            'name' => 'Mathematics',
            'code' => 'MATH101',
            'description' => 'Basic Mathematics',
            'status' => 'active'
        ],
        $adminToken,
        $subdomain
    );
    recordTest(in_array($createSubjectResponse['status'], [200, 201]), "Create subject");
    
    // Test Student Management
    section("8. STUDENT MANAGEMENT");
    
    $studentsResponse = apiRequest("$baseUrl/students", 'GET', null, $adminToken, $subdomain);
    recordTest($studentsResponse['status'] === 200, "List students");
    
    $createStudentResponse = apiRequest(
        "$baseUrl/students",
        'POST',
        [
            'name' => 'Test Student',
            'email' => "student." . time() . "@test.com",
            'admission_number' => 'STU' . time(),
            'date_of_birth' => '2010-01-01',
            'gender' => 'male',
            'status' => 'active'
        ],
        $adminToken,
        $subdomain
    );
    recordTest(in_array($createStudentResponse['status'], [200, 201]), "Create student");
    
    // Test Teacher Management
    section("9. TEACHER MANAGEMENT");
    
    $teachersResponse = apiRequest("$baseUrl/teachers", 'GET', null, $adminToken, $subdomain);
    recordTest($teachersResponse['status'] === 200, "List teachers");
    
    $createTeacherResponse = apiRequest(
        "$baseUrl/teachers",
        'POST',
        [
            'name' => 'Test Teacher Profile',
            'email' => "teacher.profile." . time() . "@test.com",
            'staff_id' => 'TCH' . time(),
            'qualification' => 'B.Ed',
            'status' => 'active'
        ],
        $adminToken,
        $subdomain
    );
    recordTest(in_array($createTeacherResponse['status'], [200, 201]), "Create teacher");
    
    // Test Guardian Management
    section("10. GUARDIAN MANAGEMENT");
    
    $guardiansResponse = apiRequest("$baseUrl/guardians", 'GET', null, $adminToken, $subdomain);
    recordTest($guardiansResponse['status'] === 200, "List guardians");
    
    // Test Assessment Module
    section("11. ASSESSMENT & EXAMINATION");
    
    $examsResponse = apiRequest("$baseUrl/assessments/exams", 'GET', null, $adminToken, $subdomain);
    recordTest($examsResponse['status'] === 200, "List exams");
    
    $assignmentsResponse = apiRequest("$baseUrl/assessments/assignments", 'GET', null, $adminToken, $subdomain);
    recordTest($assignmentsResponse['status'] === 200, "List assignments");
    
    $resultsResponse = apiRequest("$baseUrl/assessments/results", 'GET', null, $adminToken, $subdomain);
    recordTest($resultsResponse['status'] === 200, "List results");
    
    // Test Attendance
    section("12. ATTENDANCE MANAGEMENT");
    
    $attendanceResponse = apiRequest("$baseUrl/attendance", 'GET', null, $adminToken, $subdomain);
    recordTest($attendanceResponse['status'] === 200, "List attendance records");
    
    // Test Reports
    section("13. REPORTS & ANALYTICS");
    
    $academicReportResponse = apiRequest("$baseUrl/reports/academic", 'GET', null, $adminToken, $subdomain);
    recordTest($academicReportResponse['status'] === 200, "Get academic report");
    
    $attendanceReportResponse = apiRequest("$baseUrl/reports/attendance", 'GET', null, $adminToken, $subdomain);
    recordTest($attendanceReportResponse['status'] === 200, "Get attendance report");
    
    $performanceReportResponse = apiRequest("$baseUrl/reports/performance", 'GET', null, $adminToken, $subdomain);
    recordTest($performanceReportResponse['status'] === 200, "Get performance report");
    
    // Test Settings
    section("14. SETTINGS MANAGEMENT");
    
    $settingsResponse = apiRequest("$baseUrl/settings", 'GET', null, $adminToken, $subdomain);
    recordTest($settingsResponse['status'] === 200, "Get settings");
    
    $schoolSettingsResponse = apiRequest("$baseUrl/settings/school", 'GET', null, $adminToken, $subdomain);
    recordTest($schoolSettingsResponse['status'] === 200, "Get school settings");
    
    // Test Dashboard
    section("15. DASHBOARD & STATS");
    
    $adminDashboardResponse = apiRequest("$baseUrl/dashboard/admin", 'GET', null, $adminToken, $subdomain);
    recordTest($adminDashboardResponse['status'] === 200, "Get admin dashboard");
    
    // Test File Upload
    section("16. FILE MANAGEMENT");
    
    $presignedUrlsResponse = apiRequest("$baseUrl/uploads/presigned-urls", 'GET', null, $adminToken, $subdomain);
    recordTest($presignedUrlsResponse['status'] === 200, "Get presigned URLs");
    
    // Test Subscription Info
    section("17. SUBSCRIPTION MANAGEMENT");
    
    $subscriptionsResponse = apiRequest("$baseUrl/subscriptions", 'GET', null, $adminToken, $subdomain);
    recordTest($subscriptionsResponse['status'] === 200, "List subscriptions");
    
    $subscriptionStatusResponse = apiRequest("$baseUrl/subscriptions/status", 'GET', null, $adminToken, $subdomain);
    recordTest($subscriptionStatusResponse['status'] === 200, "Get subscription status");
    
    $modulesResponse = apiRequest("$baseUrl/subscriptions/modules", 'GET', null, $adminToken, $subdomain);
    recordTest($modulesResponse['status'] === 200, "List available modules");
    
    // Test Communication
    section("18. COMMUNICATION");
    
    $messagesResponse = apiRequest("$baseUrl/communication/messages", 'GET', null, $adminToken, $subdomain);
    recordTest($messagesResponse['status'] === 200, "List messages");
    
    $notificationsResponse = apiRequest("$baseUrl/communication/notifications", 'GET', null, $adminToken, $subdomain);
    recordTest($notificationsResponse['status'] === 200, "List notifications");
    
    // Test Additional Features
    section("19. ADDITIONAL FEATURES");
    
    $announcementsResponse = apiRequest("$baseUrl/announcements", 'GET', null, $adminToken, $subdomain);
    recordTest($announcementsResponse['status'] === 200, "List announcements");
    
    $timetableResponse = apiRequest("$baseUrl/timetable", 'GET', null, $adminToken, $subdomain);
    recordTest($timetableResponse['status'] === 200, "Get timetable");
    
    $quizzesResponse = apiRequest("$baseUrl/quizzes", 'GET', null, $adminToken, $subdomain);
    recordTest($quizzesResponse['status'] === 200, "List quizzes");
    
    $gradesResponse = apiRequest("$baseUrl/grades", 'GET', null, $adminToken, $subdomain);
    recordTest($gradesResponse['status'] === 200, "List grades");
    
    // Final Summary
    section("TEST SUMMARY");
    echo "\n";
    success("Passed: {$testResults['passed']}");
    error("Failed: {$testResults['failed']}");
    info("Skipped: {$testResults['skipped']}");
    
    $total = $testResults['passed'] + $testResults['failed'];
    $successRate = $total > 0 ? round(($testResults['passed'] / $total) * 100, 2) : 0;
    echo "\n";
    info("Success Rate: $successRate%");
    
    echo "\n";
    section("TEST CREDENTIALS");
    info("School Subdomain: $subdomain");
    info("Admin Email: $adminEmail");
    info("Admin Password: $adminPassword");
    info("School ID: $schoolId");
    
    exit($testResults['failed'] > 0 ? 1 : 0);
    
} catch (Exception $e) {
    error("Test execution failed: " . $e->getMessage());
    exit(1);
}

