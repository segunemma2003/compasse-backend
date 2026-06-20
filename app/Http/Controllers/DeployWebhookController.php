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

        $projectDir = base_path();
        $logFile    = storage_path('logs/deploy-webhook.log');
        $envExport  = sprintf(
            'export PROJECT_DIR=%s CERTBOT_EMAIL=%s CF_Token=%s',
            escapeshellarg($projectDir),
            escapeshellarg(config('mail.from.address', 'admin@compasse.net')),
            escapeshellarg(env('CF_Token', ''))
        );

        $command = sprintf(
            '%s && nohup bash %s >> %s 2>&1 &',
            $envExport,
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
