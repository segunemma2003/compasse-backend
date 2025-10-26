<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PerformanceService
{
    protected $cacheService;
    protected $queryLog = [];
    protected $slowQueryThreshold = 1000; // 1 second

    public function __construct(CacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Start query monitoring
     */
    public function startQueryMonitoring(): void
    {
        DB::enableQueryLog();
        $this->queryLog = [];
    }

    /**
     * Stop query monitoring and analyze performance
     */
    public function stopQueryMonitoring(): array
    {
        $queries = DB::getQueryLog();
        $this->queryLog = $queries;

        return $this->analyzeQueries($queries);
    }

    /**
     * Analyze query performance
     */
    protected function analyzeQueries(array $queries): array
    {
        $totalTime = 0;
        $slowQueries = [];
        $duplicateQueries = [];
        $queryCounts = [];

        foreach ($queries as $query) {
            $time = $query['time'];
            $sql = $query['query'];
            $totalTime += $time;

            // Track slow queries
            if ($time > $this->slowQueryThreshold) {
                $slowQueries[] = [
                    'sql' => $sql,
                    'time' => $time,
                    'bindings' => $query['bindings'],
                ];
            }

            // Track duplicate queries
            $normalizedSql = $this->normalizeSql($sql);
            if (isset($queryCounts[$normalizedSql])) {
                $queryCounts[$normalizedSql]++;
            } else {
                $queryCounts[$normalizedSql] = 1;
            }
        }

        // Find duplicate queries
        foreach ($queryCounts as $sql => $count) {
            if ($count > 1) {
                $duplicateQueries[] = [
                    'sql' => $sql,
                    'count' => $count,
                ];
            }
        }

        return [
            'total_queries' => count($queries),
            'total_time' => $totalTime,
            'average_time' => count($queries) > 0 ? round($totalTime / count($queries), 2) : 0,
            'slow_queries' => $slowQueries,
            'duplicate_queries' => $duplicateQueries,
            'performance_score' => $this->calculatePerformanceScore($queries),
        ];
    }

    /**
     * Normalize SQL for duplicate detection
     */
    protected function normalizeSql(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql));
    }

    /**
     * Calculate performance score
     */
    protected function calculatePerformanceScore(array $queries): int
    {
        $totalTime = array_sum(array_column($queries, 'time'));
        $queryCount = count($queries);

        if ($queryCount === 0) {
            return 100;
        }

        $averageTime = $totalTime / $queryCount;

        // Score based on average query time
        if ($averageTime < 100) {
            return 100;
        } elseif ($averageTime < 500) {
            return 80;
        } elseif ($averageTime < 1000) {
            return 60;
        } elseif ($averageTime < 2000) {
            return 40;
        } else {
            return 20;
        }
    }

    /**
     * Optimize database queries
     */
    public function optimizeQueries(): array
    {
        $recommendations = [];

        // Check for missing indexes
        $missingIndexes = $this->findMissingIndexes();
        if (!empty($missingIndexes)) {
            $recommendations[] = [
                'type' => 'missing_indexes',
                'message' => 'Missing database indexes detected',
                'details' => $missingIndexes,
                'priority' => 'high',
            ];
        }

        // Check for N+1 queries
        $nPlusOneQueries = $this->findNPlusOneQueries();
        if (!empty($nPlusOneQueries)) {
            $recommendations[] = [
                'type' => 'n_plus_one',
                'message' => 'N+1 query problems detected',
                'details' => $nPlusOneQueries,
                'priority' => 'high',
            ];
        }

        // Check for slow queries
        $slowQueries = $this->findSlowQueries();
        if (!empty($slowQueries)) {
            $recommendations[] = [
                'type' => 'slow_queries',
                'message' => 'Slow queries detected',
                'details' => $slowQueries,
                'priority' => 'medium',
            ];
        }

        return $recommendations;
    }

    /**
     * Find missing indexes
     */
    protected function findMissingIndexes(): array
    {
        $missingIndexes = [];

        // Check common patterns that need indexes
        $patterns = [
            'tenant_id' => 'Most queries filter by tenant_id',
            'school_id' => 'Most queries filter by school_id',
            'user_id' => 'User-related queries need user_id index',
            'created_at' => 'Date range queries need created_at index',
            'status' => 'Status filtering needs status index',
        ];

        foreach ($patterns as $column => $reason) {
            // Check if index exists by querying information_schema
            $indexExists = DB::select("
                SELECT COUNT(*) as count
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                AND column_name = ?
                AND index_name != 'PRIMARY'
            ", [$column]);

            if ($indexExists[0]->count == 0) {
                $missingIndexes[] = [
                    'column' => $column,
                    'reason' => $reason,
                    'suggestion' => "CREATE INDEX idx_{$column} ON users ({$column})",
                    'priority' => 'high'
                ];
            }
        }

        return $missingIndexes;
    }

    /**
     * Find N+1 queries
     */
    protected function findNPlusOneQueries(): array
    {
        $nPlusOneQueries = [];

        // Analyze query patterns for N+1 problems
        $queries = $this->queryLog;
        $queryPatterns = [];

        foreach ($queries as $query) {
            $sql = $this->normalizeSql($query['query']);
            if (isset($queryPatterns[$sql])) {
                $queryPatterns[$sql]++;
            } else {
                $queryPatterns[$sql] = 1;
            }
        }

        // Find queries that appear multiple times (potential N+1)
        foreach ($queryPatterns as $sql => $count) {
            if ($count > 5) { // Threshold for N+1 detection
                $nPlusOneQueries[] = [
                    'sql' => $sql,
                    'count' => $count,
                    'suggestion' => 'Consider using eager loading or query optimization',
                ];
            }
        }

        return $nPlusOneQueries;
    }

    /**
     * Find slow queries
     */
    protected function findSlowQueries(): array
    {
        $slowQueries = [];

        foreach ($this->queryLog as $query) {
            if ($query['time'] > $this->slowQueryThreshold) {
                $slowQueries[] = [
                    'sql' => $query['query'],
                    'time' => $query['time'],
                    'suggestion' => 'Consider adding indexes or optimizing the query',
                ];
            }
        }

        return $slowQueries;
    }

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics(): array
    {
        $cacheStats = $this->cacheService->getCacheStats();
        $queryAnalysis = $this->stopQueryMonitoring();

        return [
            'cache' => $cacheStats,
            'database' => $queryAnalysis,
            'memory_usage' => $this->getMemoryUsage(),
            'response_time' => $this->getResponseTime(),
        ];
    }

    /**
     * Get memory usage
     */
    protected function getMemoryUsage(): array
    {
        $memoryUsage = memory_get_usage(true);
        $peakMemoryUsage = memory_get_peak_usage(true);

        return [
            'current' => $this->formatBytes($memoryUsage),
            'peak' => $this->formatBytes($peakMemoryUsage),
            'limit' => ini_get('memory_limit'),
        ];
    }

    /**
     * Get response time
     */
    protected function getResponseTime(): float
    {
        $startTime = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        return round((microtime(true) - $startTime) * 1000, 2); // milliseconds
    }

    /**
     * Format bytes to human readable
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Log performance issues
     */
    public function logPerformanceIssue(string $type, array $data): void
    {
        Log::warning("Performance issue detected: {$type}", $data);
    }

    /**
     * Generate performance report
     */
    public function generatePerformanceReport(): array
    {
        $metrics = $this->getPerformanceMetrics();
        $recommendations = $this->optimizeQueries();

        return [
            'timestamp' => now(),
            'metrics' => $metrics,
            'recommendations' => $recommendations,
            'overall_score' => $this->calculateOverallScore($metrics),
        ];
    }

    /**
     * Calculate overall performance score
     */
    protected function calculateOverallScore(array $metrics): int
    {
        $cacheScore = $metrics['cache']['hit_rate'] ?? 0;
        $dbScore = $metrics['database']['performance_score'] ?? 0;

        return round(($cacheScore + $dbScore) / 2);
    }
}
