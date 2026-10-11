<?php

namespace Tests\Unit;

use App\Services\CacheService;
use Tests\TestCase;

/**
 * invalidateByPattern() used to KEYS/DEL the raw pattern on Redis, but real
 * keys carry the cache prefix and tenancy tag, so it never matched: cached
 * teacher/exam/attendance/assignment lists stayed stale after changes (a
 * deleted teacher reappeared on refresh). Groups are now versioned instead.
 * Replaces CacheServiceTeacherInvalidationTest, which asserted the old
 * KEYS-pattern calls against a fake Redis and so passed while production
 * stayed broken; these check the observable behaviour instead.
 */
class CacheServiceInvalidationTest extends TestCase
{
    public function test_invalidating_a_pattern_hides_every_key_in_its_group(): void
    {
        $cache = new CacheService();
        $cache->set('teachers:list:page1', ['Ada']);
        $cache->set('teachers:list:page2', ['Bola']);
        $cache->set('exams:list:page1', ['Maths']);

        $cache->invalidateByPattern('teachers:list:*');

        $this->assertNull($cache->get('teachers:list:page1'));
        $this->assertNull($cache->get('teachers:list:page2'));
        $this->assertSame(['Maths'], $cache->get('exams:list:page1'), 'other groups are untouched');

        $cache->set('teachers:list:page1', ['Chi']);
        $this->assertSame(['Chi'], $cache->get('teachers:list:page1'), 'fresh writes are readable again');
    }

    public function test_invalidate_teacher_cache_clears_the_list(): void
    {
        $cache = new CacheService();
        $cache->set('teachers:list:abc', ['stale']);

        $cache->invalidateTeacherCache(5);

        $this->assertNull($cache->get('teachers:list:abc'));

        // Also with no teacher id (bulk actions call it that way).
        $cache->set('teachers:list:abc', ['stale']);
        $cache->invalidateTeacherCache();
        $this->assertNull($cache->get('teachers:list:abc'));
    }
}
