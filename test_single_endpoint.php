<?php

$baseUrl = 'http://localhost:8000/api/v1';
$subdomain = 'testsch927320';
$adminEmail = 'admin@testsch927320.samschool.com';
$adminPassword = 'Password@12345';

// Login first
$ch = curl_init("$baseUrl/auth/login");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    "X-Subdomain: $subdomain"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'email' => $adminEmail,
    'password' => $adminPassword
]));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Login Response ($httpCode):\n";
echo $response . "\n\n";

$loginData = json_decode($response, true);
if (!isset($loginData['token'])) {
    exit("No token received!\n");
}

$token = $loginData['token'];
echo "Token: $token\n\n";

// Test /auth/me
$ch = curl_init("$baseUrl/auth/me");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    "Authorization: Bearer $token",
    "X-Subdomain: $subdomain"
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "/auth/me Response ($httpCode):\n";
echo $response . "\n\n";

// Test /users
$ch = curl_init("$baseUrl/users");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    "Authorization: Bearer $token",
    "X-Subdomain: $subdomain"
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "/users Response ($httpCode):\n";
echo $response . "\n";

