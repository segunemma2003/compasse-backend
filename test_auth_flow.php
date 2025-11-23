<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Testing Tenant Middleware & Sanctum Flow ===\n\n";

// Step 1: Find the test tenant
$tenant = \App\Models\Tenant::where('subdomain', 'testsch927320')->first();
if (!$tenant) {
    echo "❌ Tenant not found\n";
    exit(1);
}

echo "✓ Tenant found: {$tenant->subdomain}\n";
echo "  Database: {$tenant->database_name}\n\n";

// Step 2: Check current database
echo "Current DB connection: " . config('database.default') . "\n";
echo "Current DB name: " . DB::connection()->getDatabaseName() . "\n\n";

// Step 3: Initialize tenancy manually (simulate middleware)
echo "--- Initializing Tenancy ---\n";
tenancy()->initialize($tenant);

echo "After tenancy init:\n";
echo "  Connection: " . config('database.default') . "\n";
echo "  DB Name: " . DB::connection()->getDatabaseName() . "\n\n";

// Step 4: Check for tokens in tenant DB
echo "--- Checking Tokens in Tenant DB ---\n";
$tokens = DB::table('personal_access_tokens')->get();
echo "Tokens found: " . $tokens->count() . "\n";

if ($tokens->count() > 0) {
    $latestToken = $tokens->last();
    echo "Latest token ID: {$latestToken->id}\n";
    echo "Token (first 20 chars): " . substr($latestToken->token, 0, 20) . "...\n";
    echo "User ID: {$latestToken->tokenable_id}\n\n";
    
    // Step 5: Try to find token using Sanctum
    echo "--- Testing Sanctum Token Lookup ---\n";
    
    // Get the plain token from the database (it's hashed)
    $tokenId = $latestToken->id;
    $fullTokenString = "{$tokenId}|aqw2DJwhN278kVEKO1SEjmmq5qjoYKumxgPtSOJY6007aa13"; // Example
    
    echo "Attempting to find token using Sanctum...\n";
    
    // Test with PersonalAccessToken model
    try {
        $sanctumToken = \App\Models\PersonalAccessToken::findToken($fullTokenString);
        if ($sanctumToken) {
            echo "✓ Sanctum found the token!\n";
            echo "  Token ID: {$sanctumToken->id}\n";
            echo "  User ID: {$sanctumToken->tokenable_id}\n";
        } else {
            echo "❌ Sanctum could NOT find the token\n";
        }
    } catch (\Exception $e) {
        echo "❌ Error finding token: " . $e->getMessage() . "\n";
    }
}

tenancy()->end();

echo "\n=== Test Complete ===\n";

