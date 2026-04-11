<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever a school's landing page settings are changed.
 *
 * Listeners:
 *   - InvalidateLandingPageCache (queued) — clears public + admin Redis caches
 */
class LandingPageUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $subdomain,
        public readonly int $schoolId,
    ) {}
}
