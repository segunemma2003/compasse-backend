<?php

require_once 'vendor/autoload.php';

use Illuminate\Http\Request;
use Illuminate\Http\Response;

echo "🚀 Testing API Routes (Simple Test)...\n\n";

// Test basic health endpoint
$healthUrl = 'http://localhost:8000/api/health';
echo "Testing: GET $healthUrl\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $healthUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "❌ Error: $error\n";
} else {
    echo "✅ Status: $httpCode\n";
    if ($response) {
        $data = json_decode($response, true);
        if ($data) {
            echo "✅ Response: " . json_encode($data, JSON_PRETTY_PRINT) . "\n";
        } else {
            echo "✅ Response: $response\n";
        }
    }
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "🎯 Route Testing Complete!\n";
echo "If you see a 200 status, the server is running correctly.\n";
echo "If you see connection errors, make sure to start the server with:\n";
echo "php artisan serve\n";
echo str_repeat("=", 50) . "\n";
