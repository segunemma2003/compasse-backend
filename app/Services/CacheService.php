<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class CacheService
{
    protected $defaultTtl = 3600; // 1 hour
    protected $redis;

    public function __construct()
    {
        try {
            // Check if Redis facade is available
            if (class_exists('Illuminate\Support\Facades\Redis')) {
                $this->redis = Redis::connection();
            } else {
                $this->redis = null;
            }
        } catch (\Exception $e) {
            // Redis not available, use file cache instead
            $this->redis = null;
        }
    }

    /**
     * Get cached data
     */
    public function get(string $key, $default = null)
    {
        return Cache::get($key, $default);
    }

    /**
     * Set cached data
     */
    public function set(string $key, $value, int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->defaultTtl;
        return Cache::put($key, $value, $ttl);
    }

    /**
     * Cache school data
     */
    public function cacheSchoolData(int $schoolId, array $data, int $ttl = null): bool
    {
        $key = "school:{$schoolId}:data";
        return $this->set($key, $data, $ttl);
    }

    /**
     * Get cached school data
     */
    public function getCachedSchoolData(int $schoolId): ?array
    {
        $key = "school:{$schoolId}:data";
        return $this->get($key);
    }

    /**
     * Cache student data
     */
    public function cacheStudentData(int $studentId, array $data, int $ttl = null): bool
    {
        $key = "student:{$studentId}:data";
        return $this->set($key, $data, $ttl);
    }

    /**
     * Get cached student data
     */
    public function getCachedStudentData(int $studentId): ?array
    {
        $key = "student:{$studentId}:data";
        return $this->get($key);
    }

    /**
     * Cache teacher data
     */
    public function cacheTeacherData(int $teacherId, array $data, int $ttl = null): bool
    {
        $key = "teacher:{$teacherId}:data";
        return $this->set($key, $data, $ttl);
    }

    /**
     * Get cached teacher data
     */
    public function getCachedTeacherData(int $teacherId): ?array
    {
        $key = "teacher:{$teacherId}:data";
        return $this->get($key);
    }

    /**
     * Cache class data
     */
    public function cacheClassData(int $classId, array $data, int $ttl = null): bool
    {
        $key = "class:{$classId}:data";
        return $this->set($key, $data, $ttl);
    }

    /**
     * Get cached class data
     */
    public function getCachedClassData(int $classId): ?array
    {
        $key = "class:{$classId}:data";
        return $this->get($key);
    }

    /**
     * Cache exam data
     */
    public function cacheExamData(int $examId, array $data, int $ttl = null): bool
    {
        $key = "exam:{$examId}:data";
        return $this->set($key, $data, $ttl);
    }

    /**
     * Get cached exam data
     */
    public function getCachedExamData(int $examId): ?array
    {
        $key = "exam:{$examId}:data";
        return $this->get($key);
    }

    /**
     * Cache subscription data
     */
    public function cacheSubscriptionData(int $schoolId, array $data, int $ttl = null): bool
    {
        $key = "subscription:{$schoolId}:data";
        return $this->set($key, $data, $ttl);
    }

    /**
     * Get cached subscription data
     */
    public function getCachedSubscriptionData(int $schoolId): ?array
    {
        $key = "subscription:{$schoolId}:data";
        return $this->get($key);
    }

    /**
     * Cache statistics
     */
    public function cacheStats(string $type, int $entityId, array $data, int $ttl = null): bool
    {
        $key = "stats:{$type}:{$entityId}";
        return $this->set($key, $data, $ttl);
    }

    /**
     * Get cached statistics
     */
    public function getCachedStats(string $type, int $entityId): ?array
    {
        $key = "stats:{$type}:{$entityId}";
        return $this->get($key);
    }

    /**
     * Cache API response
     */
    public function cacheApiResponse(string $endpoint, array $params, $response, int $ttl = null): bool
    {
        $key = "api:" . md5($endpoint . serialize($params));
        return $this->set($key, $response, $ttl);
    }

    /**
     * Get cached API response
     */
    public function getCachedApiResponse(string $endpoint, array $params)
    {
        $key = "api:" . md5($endpoint . serialize($params));
        return $this->get($key);
    }

    /**
     * Invalidate cache by pattern
     */
    public function invalidateByPattern(string $pattern): int
    {
        if (!$this->redis) {
            // Redis not available, use Cache facade
            Cache::flush();
            return 1;
        }
        
        try {
            $keys = $this->redis->keys($pattern);
            if (empty($keys)) {
                return 0;
            }

            return $this->redis->del($keys);
        } catch (\Exception $e) {
            // Redis error, fallback to Cache facade
            Cache::flush();
            return 1;
        }
    }

    /**
     * Invalidate school cache
     */
    public function invalidateSchoolCache(int $schoolId): int
    {
        return $this->invalidateByPattern("school:{$schoolId}:*");
    }

    /**
     * Invalidate student cache
     */
    public function invalidateStudentCache(int $studentId): int
    {
        return $this->invalidateByPattern("student:{$studentId}:*");
    }

    /**
     * Invalidate teacher cache
     */
    public function invalidateTeacherCache(int $teacherId): int
    {
        return $this->invalidateByPattern("teacher:{$teacherId}:*");
    }

    /**
     * Invalidate class cache
     */
    public function invalidateClassCache(int $classId): int
    {
        return $this->invalidateByPattern("class:{$classId}:*");
    }

    /**
     * Invalidate exam cache
     */
    public function invalidateExamCache(int $examId): int
    {
        return $this->invalidateByPattern("exam:{$examId}:*");
    }

    /**
     * Invalidate subscription cache
     */
    public function invalidateSubscriptionCache(int $schoolId): int
    {
        return $this->invalidateByPattern("subscription:{$schoolId}:*");
    }

    /**
     * Clear all cache
     */
    public function clearAll(): bool
    {
        return Cache::flush();
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        if (!$this->redis) {
            return [
                'memory_used' => 'N/A (File Cache)',
                'connected_clients' => 0,
                'total_commands_processed' => 0,
                'keyspace_hits' => 0,
                'keyspace_misses' => 0,
                'hit_rate' => 0,
            ];
        }
        
        try {
            $info = $this->redis->info();

            return [
                'memory_used' => $info['used_memory_human'] ?? 'Unknown',
                'connected_clients' => $info['connected_clients'] ?? 0,
                'total_commands_processed' => $info['total_commands_processed'] ?? 0,
                'keyspace_hits' => $info['keyspace_hits'] ?? 0,
                'keyspace_misses' => $info['keyspace_misses'] ?? 0,
                'hit_rate' => $this->calculateHitRate($info),
            ];
        } catch (\Exception $e) {
            return [
                'memory_used' => 'N/A (Error)',
                'connected_clients' => 0,
                'total_commands_processed' => 0,
                'keyspace_hits' => 0,
                'keyspace_misses' => 0,
                'hit_rate' => 0,
            ];
        }
    }

    /**
     * Calculate cache hit rate
     */
    protected function calculateHitRate(array $info): float
    {
        $hits = $info['keyspace_hits'] ?? 0;
        $misses = $info['keyspace_misses'] ?? 0;
        $total = $hits + $misses;

        return $total > 0 ? round(($hits / $total) * 100, 2) : 0;
    }
}
