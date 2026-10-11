<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\Teacher;
use App\Services\CacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cached lists are cleared whenever their records change, by any path —
 * not only by the controller actions that remembered to. The teachers page
 * stayed empty after a successful bulk upload because the upload never
 * cleared the cached list.
 */
class CacheClearsOnModelChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['teachers', 'exams'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, function ($t) {
                $t->id();
                $t->string('first_name')->nullable();
                $t->string('name')->nullable();
                $t->string('email')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
    }

    public function test_creating_or_deleting_a_teacher_clears_the_cached_list(): void
    {
        $cache = app(CacheService::class);

        $cache->set('teachers:list:page1', ['empty list']);
        $teacher = Teacher::create(['first_name' => 'Ruth', 'email' => 'ruth@example.test']);
        $this->assertNull($cache->get('teachers:list:page1'), 'a new teacher must not be hidden behind a stale list');

        $cache->set('teachers:list:page1', ['Ruth']);
        $teacher->delete();
        $this->assertNull($cache->get('teachers:list:page1'), 'a deleted teacher must not linger on the list');
    }

    public function test_invalidating_one_exam_also_clears_the_exam_list(): void
    {
        $cache = app(CacheService::class);
        $cache->set('exams:list:7:abc', ['Maths', 'English']);

        $cache->invalidateExamCache(3);

        $this->assertNull($cache->get('exams:list:7:abc'));
    }
}
