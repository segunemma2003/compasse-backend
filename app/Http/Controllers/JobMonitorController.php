<?php

namespace App\Http\Controllers;

use App\Models\SchedulerLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class JobMonitorController extends Controller
{
    /**
     * Overall queue stats.
     */
    public function stats(): JsonResponse
    {
        $repo = $this->horizonRepo();

        $pending   = rescue(fn () => $repo ? $repo->countPending()   : DB::table('jobs')->count(), 0);
        $completed = rescue(fn () => $repo ? $repo->countCompleted()  : 0, 0);
        $failed    = rescue(fn () => DB::table('failed_jobs')->count(), 0);
        $recent    = rescue(fn () => $repo ? $repo->totalRecent()     : 0, 0);

        // Horizon process status
        $horizonStatus = 'unknown';
        try {
            Artisan::call('horizon:status');
            $output        = Artisan::output();
            $horizonStatus = str_contains(strtolower($output), 'running') ? 'running'
                : (str_contains(strtolower($output), 'paused') ? 'paused' : 'inactive');
        } catch (\Throwable) {}

        return response()->json([
            'pending'        => $pending,
            'completed'      => $completed,
            'failed'         => $failed,
            'recent'         => $recent,
            'horizon_status' => $horizonStatus,
        ]);
    }

    /**
     * List pending jobs from Horizon.
     */
    public function pendingJobs(Request $request): JsonResponse
    {
        $repo = $this->horizonRepo();
        if (!$repo) {
            return response()->json(['jobs' => [], 'total' => 0, 'notice' => 'Horizon is not available.']);
        }

        try {
            $afterIndex = $request->input('after', null);
            $jobs       = $repo->getPending($afterIndex);
            $total      = $repo->countPending();

            return response()->json([
                'jobs'  => $this->formatHorizonJobs($jobs),
                'total' => $total,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['jobs' => [], 'total' => 0, 'error' => $e->getMessage()]);
        }
    }

    /**
     * List completed/succeeded jobs from Horizon.
     */
    public function completedJobs(Request $request): JsonResponse
    {
        $repo = $this->horizonRepo();
        if (!$repo) {
            return response()->json(['jobs' => [], 'total' => 0, 'notice' => 'Horizon is not available.']);
        }

        try {
            $afterIndex = $request->input('after', null);
            $jobs       = $repo->getCompleted($afterIndex);
            $total      = $repo->countCompleted();

            return response()->json([
                'jobs'  => $this->formatHorizonJobs($jobs),
                'total' => $total,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['jobs' => [], 'total' => 0, 'error' => $e->getMessage()]);
        }
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
                'id'            => $job->id,
                'uuid'          => $job->uuid,
                'display_name'  => $payload['displayName'] ?? ($payload['data']['commandName'] ?? 'Unknown'),
                'queue'         => $job->queue,
                'connection'    => $job->connection,
                'exception'     => $this->trimException($job->exception),
                'exception_full'=> $job->exception,   // full stack trace for the modal
                'failed_at'     => $job->failed_at,
                'payload'       => [
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
     * List scheduler run logs — gracefully returns empty if table not yet migrated.
     */
    public function schedulerLogs(Request $request): JsonResponse
    {
        if (!Schema::hasTable('scheduler_logs')) {
            return response()->json([
                'logs'     => [],
                'total'    => 0,
                'per_page' => 25,
                'page'     => 1,
                'notice'   => 'Run php artisan migrate to create the scheduler_logs table.',
            ]);
        }

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

    private function horizonRepo(): ?\Laravel\Horizon\Contracts\JobRepository
    {
        try {
            return app(\Laravel\Horizon\Contracts\JobRepository::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatHorizonJobs(\Illuminate\Support\Collection $jobs): array
    {
        return $jobs->map(function ($job) {
            $job = is_array($job) ? (object) $job : $job;
            return [
                'id'           => $job->id ?? null,
                'display_name' => $job->displayName ?? ($job->display_name ?? 'Unknown'),
                'queue'        => $job->queue ?? 'default',
                'status'       => $job->status ?? 'unknown',
                'pushed_at'    => isset($job->pushed_at)    ? date('Y-m-d H:i:s', (int) $job->pushed_at)    : null,
                'reserved_at'  => isset($job->reserved_at)  ? date('Y-m-d H:i:s', (int) $job->reserved_at)  : null,
                'completed_at' => isset($job->completed_at) ? date('Y-m-d H:i:s', (int) $job->completed_at) : null,
            ];
        })->values()->all();
    }

    private function trimException(string $exception): string
    {
        $first = strtok($exception, "\n");
        return strlen($first) > 300 ? substr($first, 0, 300) . '…' : $first;
    }
}
