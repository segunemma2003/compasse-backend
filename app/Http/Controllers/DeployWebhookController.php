<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DeployWebhookController extends Controller
{
    /**
     * Trigger a background production deploy (git pull, migrate, restart services).
     * Called by GitHub Actions over HTTPS — no inbound SSH from CI required.
     */
    public function trigger(Request $request): JsonResponse
    {
        $token = config('app.deploy_webhook_token');

        if (!$token || !hash_equals($token, (string) $request->header('X-Deploy-Token', ''))) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], Response::HTTP_FORBIDDEN);
        }

        $script = base_path('scripts/ci-deploy-production.sh');
        if (!is_file($script)) {
            return response()->json(['success' => false, 'message' => 'Deploy script not found'], 500);
        }

        $logFile = storage_path('logs/deploy-webhook.log');

        // Runs as www-data (PHP-FPM), which has no sudo rights. The deploy script needs
        // sudo for chown/systemctl/supervisorctl/certbot, so it's run as the "deploy" user
        // via a sudoers rule scoped to this exact command (see /etc/sudoers.d). That rule
        // only matches the bare command below, so no env vars/extra args can be passed
        // through — PROJECT_DIR/CERTBOT_EMAIL/CF_Token fall back to the script's defaults.
        $command = sprintf(
            'nohup sudo -n -u deploy /usr/bin/bash %s >> %s 2>&1 &',
            escapeshellarg($script),
            escapeshellarg($logFile)
        );

        Log::info('Deploy webhook triggered', [
            'sha'    => $request->input('sha'),
            'branch' => $request->input('ref'),
            'actor'  => $request->input('actor'),
        ]);

        exec($command);

        return response()->json([
            'success' => true,
            'message' => 'Deploy started in background',
            'log'     => 'storage/logs/deploy-webhook.log',
        ], Response::HTTP_ACCEPTED);
    }

    public function status(Request $request): JsonResponse
    {
        $token = config('app.deploy_webhook_token');
        if ($token && !hash_equals($token, (string) $request->header('X-Deploy-Token', ''))) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], Response::HTTP_FORBIDDEN);
        }

        $logFile = storage_path('logs/deploy-webhook.log');
        $lines   = is_file($logFile) ? (file($logFile) ?: []) : [];
        $tail    = implode('', array_slice($lines, -40));

        $completed = str_contains($tail, 'Deployment completed successfully');
        $running   = is_file($logFile) && !$completed && (time() - filemtime($logFile)) < 1800;

        return response()->json([
            'success'   => true,
            'completed' => $completed,
            'running'   => $running,
            'log_tail'  => $tail,
        ]);
    }
}
