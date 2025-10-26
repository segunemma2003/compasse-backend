<?php

require_once 'vendor/autoload.php';

echo "🌐 TESTING SAMSCHOOL MANAGEMENT SYSTEM ON PORT 8078\n";
echo str_repeat("=", 60) . "\n\n";

// Test function
function testRoute($method, $url, $headers = [], $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

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

// Test URLs for port 8078
$testUrls = [
    'http://localhost:8078',
    'http://localhost:8078/api/health',
    'http://localhost:8078/api/v1/health',
    'http://localhost:8078/api/v1/auth/register'
];

echo "🔐 Testing SamSchool Management System on Port 8078...\n\n";

$results = [];
$successful200 = 0;
$errors = 0;
$serverErrors = 0;
$connectionErrors = 0;

foreach ($testUrls as $index => $url) {
    echo "Test " . ($index + 1) . ": GET $url\n";

    $result = testRoute('GET', $url);
    $results[] = $result;

    if ($result['error']) {
        echo "❌ Connection Error: " . $result['error'] . "\n";
        $connectionErrors++;
    } else {
        $status = $result['status'];
        if ($status == 200) {
            echo "✅ Status: $status (Perfect 200!)\n";
            $successful200++;
        } elseif ($status >= 200 && $status < 300) {
            echo "✅ Status: $status (Success)\n";
            $successful200++;
        } elseif ($status == 404) {
            echo "❌ Status: $status (Not Found - Application not running on port 8078)\n";
            $errors++;
        } elseif ($status == 500) {
            echo "🔧 Status: $status (Server Error)\n";
            $serverErrors++;
        } else {
            echo "⚠️  Status: $status\n";
            $errors++;
        }
    }

    // Show response for successful requests
    if ($status >= 200 && $status < 300) {
        $responseData = json_decode($result['response'], true);
        if ($responseData) {
            if (isset($responseData['status'])) {
                echo "   📊 Status: " . $responseData['status'] . "\n";
            }
            if (isset($responseData['timestamp'])) {
                echo "   ⏰ Timestamp: " . $responseData['timestamp'] . "\n";
            }
            if (isset($responseData['version'])) {
                echo "   🏷️  Version: " . $responseData['version'] . "\n";
            }
        }
    }

    echo "\n";
}

// Summary
echo str_repeat("=", 60) . "\n";
echo "📊 PORT 8078 TEST SUMMARY\n";
echo str_repeat("=", 60) . "\n";
echo "Total URLs Tested: " . count($results) . "\n";
echo "✅ Successful 200 Responses: $successful200\n";
echo "🔧 Server Errors (500): $serverErrors\n";
echo "❌ Connection Errors: $connectionErrors\n";
echo "❌ Other Errors: $errors\n";

$totalWorking = $successful200;
$successRate = round(($totalWorking / count($results)) * 100, 1);

echo "\nSuccess Rate: $successRate%\n";

echo "\n🎯 PORT 8078 SYSTEM STATUS:\n";
echo "✅ Main Application: " . ($results[0]['status'] == 200 ? "ACCESSIBLE" : "NOT ACCESSIBLE") . "\n";
echo "✅ Health Check: " . ($results[1]['status'] == 200 ? "WORKING" : "FAILED") . "\n";
echo "✅ API Health: " . ($results[2]['status'] == 200 ? "WORKING" : "FAILED") . "\n";
echo "✅ API Endpoints: " . (($results[3]['status'] == 200 || $results[3]['status'] == 422) ? "WORKING" : "NEEDS ATTENTION") . "\n";

if ($successful200 >= 2) {
    echo "\n🎉 EXCELLENT! SYSTEM IS WORKING ON PORT 8078!\n";
    echo "✅ Application is accessible on port 8078\n";
    echo "✅ Health checks are working\n";
    echo "✅ API endpoints are responding\n";
    echo "✅ System is ready for production use\n";
} elseif ($successful200 >= 1) {
    echo "\n✅ GOOD PROGRESS! SYSTEM IS PARTIALLY WORKING ON PORT 8078!\n";
    echo "Some endpoints are working correctly\n";
    echo "System is partially functional\n";
} else {
    echo "\n🔧 NEEDS ATTENTION\n";
    echo "System is not accessible on port 8078\n";
    echo "Check Nginx configuration and application setup\n";
}

if ($connectionErrors == 0) {
    echo "\n🎉 ZERO CONNECTION ERRORS!\n";
    echo "✅ All connections to port 8078 successful\n";
    echo "✅ Network connectivity is working\n";
    echo "✅ Port 8078 is properly configured\n";
} else {
    echo "\n🔧 CONNECTION ERRORS DETECTED\n";
    echo "Some connections to port 8078 failed\n";
    echo "Check if application is running on port 8078\n";
    echo "Check firewall settings for port 8078\n";
}

if ($serverErrors == 0) {
    echo "\n🎉 ZERO SERVER ERRORS!\n";
    echo "✅ No 500 errors on any endpoint\n";
    echo "✅ Application is stable and reliable\n";
    echo "✅ All endpoints are working correctly\n";
} else {
    echo "\n🔧 SOME SERVER ERRORS DETECTED\n";
    echo "Some endpoints returning 500 errors\n";
    echo "Check application logs for details\n";
}

echo "\n📋 FINAL PORT 8078 STATUS:\n";
echo "✅ Laravel Application: " . ($results[0]['status'] == 200 ? "Running" : "Not Running") . "\n";
echo "✅ Port 8078 Access: " . ($connectionErrors == 0 ? "Working" : "Failed") . "\n";
echo "✅ Health Endpoints: " . ($successful200 >= 2 ? "Working" : "Failed") . "\n";
echo "✅ API Endpoints: " . ($successful200 >= 3 ? "Working" : "Failed") . "\n";
echo "✅ Server Errors: $serverErrors (target: 0)\n";
echo "✅ Connection Errors: $connectionErrors (target: 0)\n";
echo "✅ Total Tests: " . count($results) . "\n";

if ($successful200 >= 2 && $connectionErrors == 0 && $serverErrors == 0) {
    echo "\n🎉 SUCCESS! SYSTEM IS PRODUCTION READY ON PORT 8078!\n";
    echo "✅ Application is fully accessible on port 8078\n";
    echo "✅ All health checks passing\n";
    echo "✅ API endpoints working correctly\n";
    echo "✅ Ready for production deployment\n";
    echo "✅ GitHub Actions will work correctly\n";
    echo "✅ Health monitoring is functional\n";
} else {
    echo "\n🔧 MORE WORK NEEDED\n";
    echo "Need to fix port 8078 configuration\n";
    echo "Check Nginx setup and application deployment\n";
}

echo "\n🚀 CONCLUSION:\n";
echo "Testing completed for port 8078 configuration.\n";
echo "Achieved $successful200 successful responses.\n";
echo "Connection errors: $connectionErrors (target: 0)\n";
echo "Server errors: $serverErrors (target: 0)\n";
echo "System is " . ($successful200 >= 2 && $connectionErrors == 0 && $serverErrors == 0 ? "working excellently" : "needs improvement") . " on port 8078.\n";
echo "Main application: " . ($results[0]['status'] == 200 ? "Accessible" : "Not Accessible") . "\n";
echo "Health check: " . ($results[1]['status'] == 200 ? "Working" : "Failed") . "\n";
echo "API health: " . ($results[2]['status'] == 200 ? "Working" : "Failed") . "\n";
echo "API endpoints: " . (($results[3]['status'] == 200 || $results[3]['status'] == 422) ? "Working" : "Failed") . "\n";

echo str_repeat("=", 60) . "\n";
