<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\PerformanceService;
use Illuminate\Support\Facades\Log;

class PerformanceMiddleware
{
    protected $performanceService;

    public function __construct(PerformanceService $performanceService)
    {
        $this->performanceService = $performanceService;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Start performance monitoring
        $this->performanceService->startQueryMonitoring();
        $startTime = microtime(true);

        $response = $next($request);

        // Calculate response time
        $responseTime = (microtime(true) - $startTime) * 1000; // milliseconds

        // Stop monitoring and get analysis
        $analysis = $this->performanceService->stopQueryMonitoring();

        // Log slow requests
        if ($responseTime > 3000) { // 3 seconds
            Log::warning('Slow API request detected', [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'response_time' => $responseTime,
                'query_count' => $analysis['total_queries'] ?? 0,
                'slow_queries' => $analysis['slow_queries'] ?? [],
            ]);
        }

        // Add performance headers
        $response->headers->set('X-Response-Time', $responseTime . 'ms');
        $response->headers->set('X-Query-Count', $analysis['total_queries'] ?? 0);
        $response->headers->set('X-Performance-Score', $analysis['performance_score'] ?? 0);

        return $response;
    }
}
