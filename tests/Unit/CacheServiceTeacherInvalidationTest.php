<?php

namespace Tests\Unit;

use App\Services\CacheService;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Regression test for the production bug fixed 2026-09-11: editing a
 * teacher looked like it "didn't save". It did — TeacherController::update
 * called invalidateTeacherCache($teacher->id), which only cleared the
 * single-record key pattern (teacher:{id}:*), never the teachers:list:*
 * pattern TeacherController::index() actually caches its response under.
 * The list the UI re-fetches right after an edit kept serving the stale,
 * pre-edit cached response.
 *
 * Exercises the real invalidateByPattern() code path (via a fake redis
 * double, since CacheService only touches $this->redis when
 * config('cache.default') === 'redis' — not the case in the default
 * testing environment) rather than asserting on internals.
 */
class CacheServiceTeacherInvalidationTest extends TestCase
{
    public function test_invalidating_a_teacher_also_clears_the_shared_list_cache(): void
    {
        $fakeRedis = new class {
            public array $keysCalledWith = [];
            public array $deleted = [];

            public function keys(string $pattern): array
            {
                $this->keysCalledWith[] = $pattern;
                // Pretend one matching key exists per pattern, so del() is exercised too.
                return ["cache-key-for:{$pattern}"];
            }

            public function del(array $keys): int
            {
                $this->deleted = array_merge($this->deleted, $keys);
                return count($keys);
            }
        };

        $service = new CacheService();
        $prop = new ReflectionProperty(CacheService::class, 'redis');
        $prop->setAccessible(true);
        $prop->setValue($service, $fakeRedis);

        $service->invalidateTeacherCache(42);

        $this->assertContains('teachers:list:*', $fakeRedis->keysCalledWith, 'the shared list cache was never targeted for invalidation');
        $this->assertContains('teacher:42:*', $fakeRedis->keysCalledWith, 'the single-record cache was never targeted for invalidation');
        $this->assertNotEmpty($fakeRedis->deleted);
    }

    public function test_invalidating_with_no_teacher_id_still_clears_the_list_cache(): void
    {
        // store() calls invalidateByPattern('teachers:*') separately today,
        // but invalidateTeacherCache() itself should be safe to call with no
        // ID (e.g. a future call site that only cares about the list).
        $fakeRedis = new class {
            public array $keysCalledWith = [];
            public function keys(string $pattern): array { $this->keysCalledWith[] = $pattern; return []; }
            public function del(array $keys): int { return 0; }
        };

        $service = new CacheService();
        $prop = new ReflectionProperty(CacheService::class, 'redis');
        $prop->setAccessible(true);
        $prop->setValue($service, $fakeRedis);

        $service->invalidateTeacherCache();

        $this->assertSame(['teachers:list:*'], $fakeRedis->keysCalledWith);
    }
}
