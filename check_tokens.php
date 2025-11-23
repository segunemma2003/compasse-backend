<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$tenant = \App\Models\Tenant::where('subdomain', 'testsch927320')->first();

if (!$tenant) {
    echo "Tenant not found!\n";
    exit;
}

echo "Tenant: {$tenant->id} | {$tenant->subdomain} | DB: {$tenant->database_name}\n\n";

// Initialize tenancy
tenancy()->initialize($tenant);

echo "Current DB connection: " . config('database.default') . "\n";
echo "Current DB name: " . DB::connection()->getDatabaseName() . "\n\n";

// Check tokens
$tokens = DB::table('personal_access_tokens')->get();
echo "Tokens in tenant database: " . $tokens->count() . "\n";
foreach ($tokens as $token) {
    echo "  ID: {$token->id} | User: {$token->tokenable_id} | Token: " . substr($token->token, 0, 20) . "...\n";
}

tenancy()->end();

