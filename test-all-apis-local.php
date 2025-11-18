<?php

/**
 * Comprehensive API Test Script for Local Testing
 * Tests all implemented endpoints
 */

$baseUrl = 'http://localhost:8000/api/v1';
$healthUrl = 'http://localhost:8000/api/health';
$token = null;
$tenantId = null;
$schoolId = null;
$studentId = null;
$teacherId = null;

// Colors for output
$green = "\033[32m";
$red = "\033[31m";
$yellow = "\033[33m";
$blue = "\033[34m";
$reset = "\033[0m";

function testApi($method, $endpoint, $data = null, $headers = [], $description = '') {
    global $baseUrl, $token, $green, $red, $yellow, $blue, $reset;

    $url = $baseUrl . $endpoint;

    // Add token to headers if available
    if ($token) {
        $headers['Authorization'] = 'Bearer ' . $token;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if ($method === 'POST' || $method === 'PUT') {
        curl_setopt($ch, CURLOPT_POST, true);
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

    // Rebuild headers array properly
    $headerArray = [];
    foreach ($headers as $key => $value) {
        $headerArray[] = "$key: $value";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);

    if ($method === 'PUT' || $method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $success = $status >= 200 && $status < 300;
    $color = $success ? $green : ($status >= 400 && $status < 500 ? $yellow : $red);

    echo sprintf(
        "%s[%s] %s %s%s\n",
        $color,
        $status,
        str_pad($method, 6),
        $description ?: $endpoint,
        $reset
    );

    if ($error) {
        echo "  {$red}Error: $error{$reset}\n";
        return false;
    }

    if (!$success && $status !== 404) {
        $responseData = json_decode($response, true);
        if (isset($responseData['error'])) {
            echo "  {$yellow}Error: {$responseData['error']}{$reset}\n";
        }
    }

    return ['status' => $status, 'response' => json_decode($response, true), 'success' => $success];
}

echo "{$blue}========================================{$reset}\n";
echo "{$blue}COMPREHENSIVE API TESTING - LOCAL{$reset}\n";
echo "{$blue}========================================{$reset}\n\n";

// 1. Health Check
echo "{$blue}1. Health Check{$reset}\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $healthUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$success = $status >= 200 && $status < 300;
$color = $success ? $green : $red;
echo sprintf("%s[%s] GET    Health Check%s\n", $color, $status, $reset);
if ($success) {
    echo "  {$green}✓ Server is running{$reset}\n";
} else {
    echo "  {$red}✗ Server may not be running or health endpoint not accessible{$reset}\n";
}
echo "\n";

// 2. Authentication
echo "{$blue}2. Authentication{$reset}\n";
$loginResult = testApi('POST', '/auth/login', [
    'email' => 'superadmin@compasse.net',
    'password' => 'Nigeria@60'
], [], 'Super Admin Login');

if ($loginResult && $loginResult['success'] && isset($loginResult['response']['token'])) {
    $token = $loginResult['response']['token'];
    echo "  {$green}✓ Token obtained{$reset}\n";
} else {
    echo "  {$red}✗ Failed to get token. Some tests will be skipped.{$reset}\n";
}
echo "\n";

// 3. Get Current User
echo "{$blue}3. Get Current User{$reset}\n";
$meResult = testApi('GET', '/auth/me', null, [], 'Get Current User');
if ($meResult && isset($meResult['response']['user'])) {
    echo "  {$green}✓ User: {$meResult['response']['user']['email']}{$reset}\n";
}
echo "\n";

// 4. Tenant Management (Super Admin)
echo "{$blue}4. Tenant Management{$reset}\n";
$tenantsResult = testApi('GET', '/tenants', null, [], 'List Tenants');
if ($tenantsResult && $tenantsResult['success'] && isset($tenantsResult['response']['tenants']['data'][0])) {
    $tenantId = $tenantsResult['response']['tenants']['data'][0]['id'];
    echo "  {$green}✓ Tenant ID: $tenantId{$reset}\n";
}
echo "\n";

// 5. School Management
echo "{$blue}5. School Management{$reset}\n";
$schoolData = [
    'name' => 'Test School ' . time(),
    'address' => '123 Test Street',
    'phone' => '+1234567890',
    'email' => 'test@school.com',
    'website' => 'https://testschool.com',
    'status' => 'active'
];

// Add tenant_id to school creation
if ($tenantId) {
    $schoolData['tenant_id'] = $tenantId;
}

$createSchoolResult = testApi('POST', '/schools', $schoolData, ['X-Tenant-ID' => $tenantId], 'Create School');
if ($createSchoolResult && $createSchoolResult['success'] && isset($createSchoolResult['response']['school']['id'])) {
    $schoolId = $createSchoolResult['response']['school']['id'];
    echo "  {$green}✓ School ID: $schoolId{$reset}\n";

    // Test get school
    testApi('GET', "/schools/$schoolId", null, ['X-Tenant-ID' => $tenantId], 'Get School');

    // Test list schools
    testApi('GET', '/schools', null, ['X-Tenant-ID' => $tenantId], 'List Schools');
}
echo "\n";

// 6. User Management
echo "{$blue}6. User Management{$reset}\n";
testApi('GET', '/users', null, ['X-Tenant-ID' => $tenantId], 'List Users');
testApi('GET', '/users?role=teacher', null, ['X-Tenant-ID' => $tenantId], 'List Users by Role');
echo "\n";

// 7. Academic Management
echo "{$blue}7. Academic Management{$reset}\n";
testApi('GET', '/classes', null, ['X-Tenant-ID' => $tenantId], 'List Classes');
testApi('GET', '/subjects', null, ['X-Tenant-ID' => $tenantId], 'List Subjects');
testApi('GET', '/academic-years', null, ['X-Tenant-ID' => $tenantId], 'List Academic Years');
testApi('GET', '/terms', null, ['X-Tenant-ID' => $tenantId], 'List Terms');
echo "\n";

// 8. Student Management
echo "{$blue}8. Student Management{$reset}\n";
testApi('GET', '/students', null, ['X-Tenant-ID' => $tenantId], 'List Students');
testApi('POST', '/students/generate-admission-number', [], ['X-Tenant-ID' => $tenantId], 'Generate Admission Number');
echo "\n";

// 9. Teacher Management
echo "{$blue}9. Teacher Management{$reset}\n";
testApi('GET', '/teachers', null, ['X-Tenant-ID' => $tenantId], 'List Teachers');
echo "\n";

// 10. Guardian Management
echo "{$blue}10. Guardian Management{$reset}\n";
testApi('GET', '/guardians', null, ['X-Tenant-ID' => $tenantId], 'List Guardians');
echo "\n";

// 11. Assessment Module
echo "{$blue}11. Assessment Module{$reset}\n";
testApi('GET', '/assessments/exams', null, ['X-Tenant-ID' => $tenantId], 'List Exams');
testApi('GET', '/assessments/assignments', null, ['X-Tenant-ID' => $tenantId], 'List Assignments');
testApi('GET', '/assessments/results', null, ['X-Tenant-ID' => $tenantId], 'List Results');
echo "\n";

// 12. Quiz System
echo "{$blue}12. Quiz System{$reset}\n";
testApi('GET', '/quizzes', null, ['X-Tenant-ID' => $tenantId], 'List Quizzes');
echo "\n";

// 13. Grades System
echo "{$blue}13. Grades System{$reset}\n";
testApi('GET', '/grades', null, ['X-Tenant-ID' => $tenantId], 'List Grades');
echo "\n";

// 14. Timetable
echo "{$blue}14. Timetable{$reset}\n";
testApi('GET', '/timetable', null, ['X-Tenant-ID' => $tenantId], 'Get Timetable');
echo "\n";

// 15. Announcements
echo "{$blue}15. Announcements{$reset}\n";
testApi('GET', '/announcements', null, ['X-Tenant-ID' => $tenantId], 'List Announcements');
echo "\n";

// 16. Library
echo "{$blue}16. Library Management{$reset}\n";
testApi('GET', '/library/books', null, ['X-Tenant-ID' => $tenantId], 'List Books');
testApi('GET', '/library/stats', null, ['X-Tenant-ID' => $tenantId], 'Library Stats');
echo "\n";

// 17. Houses
echo "{$blue}17. Houses System{$reset}\n";
testApi('GET', '/houses', null, ['X-Tenant-ID' => $tenantId], 'List Houses');
echo "\n";

// 18. Sports
echo "{$blue}18. Sports Management{$reset}\n";
testApi('GET', '/sports/activities', null, ['X-Tenant-ID' => $tenantId], 'List Sports Activities');
testApi('GET', '/sports/teams', null, ['X-Tenant-ID' => $tenantId], 'List Sports Teams');
testApi('GET', '/sports/events', null, ['X-Tenant-ID' => $tenantId], 'List Sports Events');
echo "\n";

// 19. Staff
echo "{$blue}19. Staff Management{$reset}\n";
testApi('GET', '/staff', null, ['X-Tenant-ID' => $tenantId], 'List Staff');
echo "\n";

// 20. Achievements
echo "{$blue}20. Achievements{$reset}\n";
testApi('GET', '/achievements', null, ['X-Tenant-ID' => $tenantId], 'List Achievements');
echo "\n";

// 21. Settings
echo "{$blue}21. Settings{$reset}\n";
testApi('GET', '/settings', null, ['X-Tenant-ID' => $tenantId], 'Get Settings');
testApi('GET', '/settings/school', null, ['X-Tenant-ID' => $tenantId], 'Get School Settings');
echo "\n";

// 22. Financial
echo "{$blue}22. Financial Management{$reset}\n";
testApi('GET', '/financial/fees', null, ['X-Tenant-ID' => $tenantId], 'List Fees');
testApi('GET', '/financial/payments', null, ['X-Tenant-ID' => $tenantId], 'List Payments');
echo "\n";

// 23. Attendance
echo "{$blue}23. Attendance{$reset}\n";
testApi('GET', '/attendance', null, ['X-Tenant-ID' => $tenantId], 'List Attendance');
testApi('GET', '/attendance/students', null, ['X-Tenant-ID' => $tenantId], 'Student Attendance');
testApi('GET', '/attendance/teachers', null, ['X-Tenant-ID' => $tenantId], 'Teacher Attendance');
echo "\n";

// 24. Transport
echo "{$blue}24. Transport{$reset}\n";
testApi('GET', '/transport/routes', null, ['X-Tenant-ID' => $tenantId], 'List Routes');
testApi('GET', '/transport/vehicles', null, ['X-Tenant-ID' => $tenantId], 'List Vehicles');
echo "\n";

// 25. Hostel
echo "{$blue}25. Hostel Management{$reset}\n";
testApi('GET', '/hostel/rooms', null, ['X-Tenant-ID' => $tenantId], 'List Rooms');
testApi('GET', '/hostel/allocations', null, ['X-Tenant-ID' => $tenantId], 'List Allocations');
echo "\n";

// 26. Health
echo "{$blue}26. Health Management{$reset}\n";
testApi('GET', '/health/records', null, ['X-Tenant-ID' => $tenantId], 'List Health Records');
testApi('GET', '/health/appointments', null, ['X-Tenant-ID' => $tenantId], 'List Appointments');
echo "\n";

// 27. Inventory
echo "{$blue}27. Inventory Management{$reset}\n";
testApi('GET', '/inventory/items', null, ['X-Tenant-ID' => $tenantId], 'List Items');
testApi('GET', '/inventory/categories', null, ['X-Tenant-ID' => $tenantId], 'List Categories');
echo "\n";

// 28. Events
echo "{$blue}28. Events Management{$reset}\n";
testApi('GET', '/events/events', null, ['X-Tenant-ID' => $tenantId], 'List Events');
testApi('GET', '/events/upcoming', null, ['X-Tenant-ID' => $tenantId], 'Upcoming Events');
echo "\n";

// 29. Livestream
echo "{$blue}29. Livestream{$reset}\n";
testApi('GET', '/livestreams/livestreams', null, ['X-Tenant-ID' => $tenantId], 'List Livestreams');
echo "\n";

// 30. Communication
echo "{$blue}30. Communication{$reset}\n";
testApi('GET', '/communication/messages', null, ['X-Tenant-ID' => $tenantId], 'List Messages');
testApi('GET', '/communication/notifications', null, ['X-Tenant-ID' => $tenantId], 'List Notifications');
echo "\n";

// 31. Reports
echo "{$blue}31. Reports{$reset}\n";
testApi('GET', '/reports/academic', null, ['X-Tenant-ID' => $tenantId], 'Academic Report');
testApi('GET', '/reports/financial', null, ['X-Tenant-ID' => $tenantId], 'Financial Report');
testApi('GET', '/reports/attendance', null, ['X-Tenant-ID' => $tenantId], 'Attendance Report');
echo "\n";

// 32. Subscriptions
echo "{$blue}32. Subscriptions{$reset}\n";
testApi('GET', '/subscriptions/plans', null, ['X-Tenant-ID' => $tenantId], 'List Plans');
testApi('GET', '/subscriptions/modules', null, ['X-Tenant-ID' => $tenantId], 'List Modules');
testApi('GET', '/subscriptions/status', null, ['X-Tenant-ID' => $tenantId], 'Subscription Status');
echo "\n";

// 33. Dashboards
echo "{$blue}33. Dashboards{$reset}\n";
testApi('GET', '/dashboard/admin', null, ['X-Tenant-ID' => $tenantId], 'Admin Dashboard');
testApi('GET', '/dashboard/teacher', null, ['X-Tenant-ID' => $tenantId], 'Teacher Dashboard');
testApi('GET', '/dashboard/student', null, ['X-Tenant-ID' => $tenantId], 'Student Dashboard');
testApi('GET', '/dashboard/parent', null, ['X-Tenant-ID' => $tenantId], 'Parent Dashboard');
echo "\n";

// 34. Super Admin Analytics
echo "{$blue}34. Super Admin Analytics{$reset}\n";
testApi('GET', '/super-admin/analytics', null, [], 'Super Admin Analytics');
testApi('GET', '/super-admin/database', null, [], 'Database Status');
testApi('GET', '/super-admin/security', null, [], 'Security Logs');
echo "\n";

// 35. File Upload
echo "{$blue}35. File Upload{$reset}\n";
testApi('GET', '/uploads/presigned-urls?type=image&entity_type=school', null, ['X-Tenant-ID' => $tenantId], 'Get Presigned URLs');
echo "\n";

// 36. Password Reset
echo "{$blue}36. Password Reset{$reset}\n";
testApi('POST', '/auth/forgot-password', [
    'email' => 'test@example.com',
    'tenant_id' => $tenantId
], [], 'Forgot Password');
echo "\n";

// Summary
echo "{$blue}========================================{$reset}\n";
echo "{$blue}TESTING COMPLETE{$reset}\n";
echo "{$blue}========================================{$reset}\n";
echo "\n";
echo "Note: Some endpoints may return 404 or 422 if data doesn't exist yet.\n";
echo "This is expected behavior. The important thing is that the endpoints are accessible.\n";

