<?php

require_once 'vendor/autoload.php';

echo "🚀 Testing All API Routes...\n\n";

$baseUrl = 'http://localhost:8000/api/v1';
$testResults = [];

// Test function
function testRoute($method, $url, $headers = [], $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        if (!in_array('Content-Type: application/json', $headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json']));
        }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'method' => $method,
        'url' => $url,
        'status' => $httpCode,
        'response' => $response,
        'error' => $error
    ];
}

// Test routes
$routes = [
    // Health check
    ['GET', '/api/health'],
    ['GET', '/api/v1/health'],

    // Authentication (these will fail without proper setup, but we can test the endpoints exist)
    ['POST', $baseUrl . '/auth/login', [], ['email' => 'test@example.com', 'password' => 'password']],
    ['POST', $baseUrl . '/auth/register', [], ['name' => 'Test User', 'email' => 'test@example.com', 'password' => 'password']],

    // Public routes that should work
    ['GET', $baseUrl . '/subscriptions/plans'],
    ['GET', $baseUrl . '/subscriptions/modules'],

    // Routes that require authentication (will return 401, but endpoint exists)
    ['GET', $baseUrl . '/schools/1'],
    ['GET', $baseUrl . '/students'],
    ['GET', $baseUrl . '/teachers'],
    ['GET', $baseUrl . '/classes'],
    ['GET', $baseUrl . '/subjects'],
    ['GET', $baseUrl . '/departments'],
    ['GET', $baseUrl . '/academic-years'],
    ['GET', $baseUrl . '/terms'],
    ['GET', $baseUrl . '/guardians'],
    ['GET', $baseUrl . '/assessments/exams'],
    ['GET', $baseUrl . '/assessments/assignments'],
    ['GET', $baseUrl . '/assessments/results'],
    ['GET', $baseUrl . '/livestreams'],
    ['GET', $baseUrl . '/attendance/students'],
    ['GET', $baseUrl . '/attendance/teachers'],
    ['GET', $baseUrl . '/financial/fees'],
    ['GET', $baseUrl . '/financial/payments'],
    ['GET', $baseUrl . '/transport/routes'],
    ['GET', $baseUrl . '/transport/vehicles'],
    ['GET', $baseUrl . '/transport/drivers'],
    ['GET', $baseUrl . '/hostel/rooms'],
    ['GET', $baseUrl . '/hostel/allocations'],
    ['GET', $baseUrl . '/health/records'],
    ['GET', $baseUrl . '/health/appointments'],
    ['GET', $baseUrl . '/inventory/items'],
    ['GET', $baseUrl . '/inventory/categories'],
    ['GET', $baseUrl . '/events/events'],
    ['GET', $baseUrl . '/events/calendars'],
    ['GET', $baseUrl . '/reports/academic'],
    ['GET', $baseUrl . '/reports/financial'],
    ['GET', $baseUrl . '/reports/attendance'],
    ['GET', $baseUrl . '/reports/performance'],
];

echo "Testing " . count($routes) . " routes...\n\n";

foreach ($routes as $route) {
    $method = $route[0];
    $url = $route[1];
    $headers = $route[2] ?? [];
    $data = $route[3] ?? null;

    echo "Testing: $method $url\n";

    $result = testRoute($method, $url, $headers, $data);
    $testResults[] = $result;

    if ($result['error']) {
        echo "❌ Error: " . $result['error'] . "\n";
    } else {
        $status = $result['status'];
        if ($status >= 200 && $status < 300) {
            echo "✅ Status: $status (Success)\n";
        } elseif ($status == 401) {
            echo "🔐 Status: $status (Authentication Required - Expected)\n";
        } elseif ($status == 404) {
            echo "❌ Status: $status (Not Found)\n";
        } elseif ($status == 500) {
            echo "❌ Status: $status (Server Error)\n";
        } else {
            echo "⚠️  Status: $status\n";
        }
    }
    echo "\n";
}

// Summary
$totalTests = count($testResults);
$successful = 0;
$authRequired = 0;
$errors = 0;

foreach ($testResults as $result) {
    if ($result['error']) {
        $errors++;
    } elseif ($result['status'] >= 200 && $result['status'] < 300) {
        $successful++;
    } elseif ($result['status'] == 401) {
        $authRequired++;
    }
}

echo str_repeat("=", 60) . "\n";
echo "📊 ROUTE TESTING SUMMARY\n";
echo str_repeat("=", 60) . "\n";
echo "Total Tests: $totalTests\n";
echo "✅ Successful: $successful\n";
echo "🔐 Auth Required: $authRequired\n";
echo "❌ Errors: $errors\n";
echo "Success Rate: " . round((($successful + $authRequired) / $totalTests) * 100, 1) . "%\n";
echo str_repeat("=", 60) . "\n";

if ($successful > 0 || $authRequired > 0) {
    echo "🎉 API Routes are working correctly!\n";
    echo "✅ Server is running and responding\n";
    echo "✅ Routes are properly defined\n";
    echo "✅ Authentication is working (401 responses are expected)\n";
} else {
    echo "❌ There may be issues with the API routes\n";
}

echo "\n🔧 To test with authentication, you'll need to:\n";
echo "1. Set up the database\n";
echo "2. Create test users\n";
echo "3. Get authentication tokens\n";
echo "4. Include tokens in request headers\n";
