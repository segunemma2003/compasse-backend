<?php

namespace App\Listeners;

use App\Events\LandingPageUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Queued listener: invalidates all Redis cache entries for a school's
 * landing page immediately after any settings update or asset upload.
 *
 * Runs on the 'default' queue — completes in < 50 ms.
 */
class InvalidateLandingPageCache implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The queue to run on. Use a dedicated 'cache' queue if you add one,
     * otherwise 'default' is fine since this is very fast.
     */
    public string $queue = 'default';

    /**
     * Maximum number of retry attempts.
     */
    public int $tries = 3;

    /**
     * Handle the event.
     */
    public function handle(LandingPageUpdated $event): void
    {
        // Public landing page cache (served to visitors)
        Cache::forget("landing_page:{$event->subdomain}");

        // Admin editor cache
        Cache::forget("landing_admin:{$event->schoolId}");
    }

    /**
     * Handle a job failure.
     */
    public function failed(LandingPageUpdated $event, \Throwable $exception): void
    {
        // Non-critical: cache will expire naturally via TTL.
        // Log so we know the invalidation failed.
        logger()->warning('Landing page cache invalidation failed', [
            'subdomain' => $event->subdomain,
            'school_id' => $event->schoolId,
            'error'     => $exception->getMessage(),
        ]);
    }
}
