<?php

namespace App\Http\Controllers;

use App\Models\SchedulerLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class JobMonitorController extends Controller
{
    /**
     * Overall queue stats.
     */
    public function stats(): JsonResponse
    {
        $pending = DB::table('jobs')->count();
        $failed  = DB::table('failed_jobs')->count();

        // Try to get Horizon metrics from Redis
        $horizonStats = null;
        try {
            $metrics = app(\Laravel\Horizon\Contracts\MetricsRepository::class);
            $horizonStats = [
                'throughput'    => $metrics->throughput(),
                'runtime'       => $metrics->runtimeForQueue('default'),
            ];
        } catch (\Throwable) {
            // Horizon metrics unavailable
        }

        // Horizon process status
        $horizonStatus = 'unknown';
        try {
            Artisan::call('horizon:status');
            $output = Artisan::output();
            $horizonStatus = str_contains(strtolower($output), 'running') ? 'running'
                : (str_contains(strtolower($output), 'paused') ? 'paused' : 'inactive');
        } catch (\Throwable) {}

        return response()->json([
            'pending'        => $pending,
            'failed'         => $failed,
            'horizon_status' => $horizonStatus,
            'horizon'        => $horizonStats,
        ]);
    }

    /**
     * List failed jobs with pagination.
     */
    public function failedJobs(Request $request): JsonResponse
    {
        $query = DB::table('failed_jobs')->orderByDesc('failed_at');

        if ($request->filled('queue')) {
            $query->where('queue', $request->queue);
        }

        $total = $query->count();
        $jobs  = $query->paginate(20);

        $items = collect($jobs->items())->map(function ($job) {
            $payload = json_decode($job->payload, true);
            return [
                'id'           => $job->id,
                'uuid'         => $job->uuid,
                'display_name' => $payload['displayName'] ?? ($payload['data']['commandName'] ?? 'Unknown'),
                'queue'        => $job->queue,
                'connection'   => $job->connection,
                'exception'    => $this->trimException($job->exception),
                'failed_at'    => $job->failed_at,
                'payload'      => [
                    'max_tries' => $payload['maxTries'] ?? null,
                    'timeout'   => $payload['timeout']  ?? null,
                ],
            ];
        });

        return response()->json([
            'jobs'     => $items,
            'total'    => $total,
            'per_page' => $jobs->perPage(),
            'page'     => $jobs->currentPage(),
        ]);
    }

    /**
     * Retry a failed job by UUID.
     */
    public function retryJob(string $uuid): JsonResponse
    {
        $exists = DB::table('failed_jobs')->where('uuid', $uuid)->exists();
        if (!$exists) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return response()->json(['message' => 'Job queued for retry']);
    }

    /**
     * Retry all failed jobs.
     */
    public function retryAll(): JsonResponse
    {
        $count = DB::table('failed_jobs')->count();
        Artisan::call('queue:retry', ['id' => ['all']]);

        return response()->json(['message' => "All {$count} failed job(s) queued for retry"]);
    }

    /**
     * Delete a single failed job.
     */
    public function deleteJob(string $uuid): JsonResponse
    {
        $deleted = DB::table('failed_jobs')->where('uuid', $uuid)->delete();
        if (!$deleted) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        return response()->json(['message' => 'Failed job deleted']);
    }

    /**
     * Clear all failed jobs.
     */
    public function clearFailed(): JsonResponse
    {
        $count = DB::table('failed_jobs')->count();
        Artisan::call('queue:flush');

        return response()->json(['message' => "Cleared {$count} failed job(s)"]);
    }

    /**
     * List scheduler run logs.
     */
    public function schedulerLogs(Request $request): JsonResponse
    {
        $query = SchedulerLog::orderByDesc('started_at');

        if ($request->filled('command')) {
            $query->where('command', $request->command);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $logs = $query->paginate(25);

        return response()->json([
            'logs'     => $logs->items(),
            'total'    => $logs->total(),
            'per_page' => $logs->perPage(),
            'page'     => $logs->currentPage(),
        ]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function trimException(string $exception): string
    {
        // Return first line + up to 300 chars so it's readable in the UI
        $first = strtok($exception, "\n");
        return strlen($first) > 300 ? substr($first, 0, 300) . '…' : $first;
    }
}
