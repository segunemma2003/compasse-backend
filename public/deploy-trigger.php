<?php

/**
 * Bootstrap deploy hook — reachable at /deploy-trigger.php before Laravel routes are deployed.
 * Set DEPLOY_WEBHOOK_TOKEN in .env and the same value in GitHub Actions secrets.
 */

declare(strict_types=1);

header('Content-Type: application/json');

$projectDir = dirname(__DIR__);
$logFile    = $projectDir . '/storage/logs/deploy-webhook.log';

function readDeployToken(string $envPath): ?string
{
    if (!is_readable($envPath)) {
        return null;
    }

    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^DEPLOY_WEBHOOK_TOKEN=(.*)$/', $line, $matches)) {
            return trim($matches[1], " \t\n\r\0\x0B\"'");
        }
    }

    return null;
}

function tailLog(string $logFile): string
{
    if (!is_file($logFile)) {
        return '';
    }

    $lines = file($logFile) ?: [];

    return implode('', array_slice($lines, -40));
}

function authorize(string $projectDir): bool
{
    $token     = readDeployToken($projectDir . '/.env');
    $provided  = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';

    return $token !== null && $token !== '' && hash_equals($token, $provided);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['action'] ?? '') === 'status') {
    if (!authorize($projectDir)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $tail      = tailLog($logFile);
    $completed = str_contains($tail, 'Deployment completed successfully');
    $running   = is_file($logFile) && !$completed && (time() - filemtime($logFile)) < 1800;

    echo json_encode([
        'success'   => true,
        'completed' => $completed,
        'running'   => $running,
        'log_tail'  => $tail,
    ]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!authorize($projectDir)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$script = $projectDir . '/scripts/ci-deploy-production.sh';
if (!is_file($script)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Deploy script not found']);
    exit;
}

if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0775, true);
}

// Runs as www-data (PHP-FPM), which has no sudo rights. The deploy script needs
// sudo for chown/systemctl/supervisorctl/certbot, so it's run as the "deploy" user
// via a sudoers rule scoped to this exact command (see /etc/sudoers.d).
$command = sprintf(
    'nohup sudo -n -u deploy /usr/bin/bash %s >> %s 2>&1 &',
    escapeshellarg($script),
    escapeshellarg($logFile)
);

exec($command);

http_response_code(202);
echo json_encode([
    'success' => true,
    'message' => 'Deploy started in background',
    'log'     => 'storage/logs/deploy-webhook.log',
]);
