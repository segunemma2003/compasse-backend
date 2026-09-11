<?php

namespace Tests\Unit;

use App\Jobs\ProcessBulkUploadJob;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression test for the production bug fixed 2026-09-11: bulk student
 * and teacher uploads failed with League\Flysystem\UnableToCreateDirectory.
 * Two workers share the `bulk-uploads` queue (supervisor:
 * compasse-bulk-worker_00/_01), so the first write for a tenant could race
 * both processes into creating that tenant's storage directory at once —
 * confirmed live via the failed_jobs table. The fix: retry (so a transient
 * race self-heals once the other worker wins) and pre-create the storage
 * skeleton with a race-tolerant mkdir before any Storage::disk() call does
 * it lazily and unsafely.
 */
class ProcessBulkUploadJobTest extends TestCase
{
    public function test_the_job_retries_instead_of_failing_on_first_attempt(): void
    {
        $job = new ProcessBulkUploadJob(1);

        $this->assertSame(3, $job->tries, 'tries was reverted to 1 — a transient directory-creation race can no longer self-heal via retry');
        $this->assertSame([5, 15], $job->backoff());
    }

    public function test_ensure_tenant_storage_ready_creates_missing_directories_without_throwing(): void
    {
        $job = new ProcessBulkUploadJob(1);
        $method = new ReflectionMethod(ProcessBulkUploadJob::class, 'ensureTenantStorageReady');
        $method->setAccessible(true);

        // Calling it twice in a row is the same shape as two workers racing
        // to bootstrap the same tenant's storage — must be a no-op the
        // second time, not an exception.
        $method->invoke($job);
        $method->invoke($job);

        foreach (['app', 'app/public', 'app/private', 'framework/cache', 'framework/sessions', 'framework/views'] as $dir) {
            $this->assertDirectoryExists(storage_path($dir));
        }
    }
}
