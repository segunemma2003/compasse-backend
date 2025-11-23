<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$databases = DB::select('SHOW DATABASES');

foreach ($databases as $db) {
    $name = $db->Database;
    
    // Match UUIDs, tenant-prefixed, or timestamp-prefixed databases
    if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $name) ||
        preg_match('/^tenant[a-f0-9-]+$/', $name) ||
        preg_match('/^2025[0-9_-]+/', $name)) {
        
        echo "Dropping: $name\n";
        try {
            DB::statement("DROP DATABASE IF EXISTS `$name`");
            echo "✓ Dropped: $name\n";
        } catch (Exception $e) {
            echo "✗ Failed to drop $name: " . $e->getMessage() . "\n";
        }
    }
}

echo "\nCleanup complete!\n";

