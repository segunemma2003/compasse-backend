<?php

/**
 * Auto Test After Deployment
 * Waits 7 minutes, then runs comprehensive tests
 */

echo "🚀 AUTO TEST AFTER DEPLOYMENT\n";
echo str_repeat("=", 70) . "\n\n";

$waitMinutes = 7;
$waitSeconds = $waitMinutes * 60;

echo "⏳ Waiting {$waitMinutes} minutes for deployment to complete...\n";
echo "   Started at: " . date('Y-m-d H:i:s') . "\n";
echo "   Will test at: " . date('Y-m-d H:i:s', time() + $waitSeconds) . "\n\n";

// Countdown with progress
$startTime = time();
$endTime = $startTime + $waitSeconds;

while (time() < $endTime) {
    $remaining = $endTime - time();
    $minutes = floor($remaining / 60);
    $seconds = $remaining % 60;
    
    printf("\r   ⏱️  Time remaining: %02d:%02d", $minutes, $seconds);
    sleep(1);
}

echo "\n\n";
echo "✅ Wait complete! Starting automated tests...\n";
echo "   Testing at: " . date('Y-m-d H:i:s') . "\n";
echo str_repeat("=", 70) . "\n\n";

// Include and run the comprehensive test script
require __DIR__ . '/test-all-apis-comprehensive.php';

