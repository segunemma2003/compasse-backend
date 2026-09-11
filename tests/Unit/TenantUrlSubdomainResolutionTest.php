<?php

namespace Tests\Unit;

use App\Support\TenantUrl;
use Tests\TestCase;

/**
 * Every real API request hits api.compasse.net with an X-Subdomain header
 * (TenantMiddleware::resolveTenantFromApiRequest()) and never writes to
 * config('tenant.*') — those keys are only set by this app's OTHER
 * TenantMiddleware branch (real subdomain hosts), which production doesn't
 * use. So config('tenant.subdomain') sits at its config/tenant.php default
 * — an array ({enabled, wildcard, main_domain}) — for the entire life of a
 * real request. Two call sites (ManagesGuardianAccounts::portalUrl(),
 * AdmissionCycle::registration_url) used to read it directly and
 * string-interpolate it, crashing with "Array to string conversion" on
 * every real request that reached them: enrolling a student with a
 * guardian, and reading any admission cycle at all (registration_url is an
 * appended attribute, computed on every serialization). Confirmed live
 * 2026-09-11 via a real curl POST to /students with a guardian attached.
 *
 * These tests deliberately do NOT set config(['tenant.subdomain' => ...])
 * the way most other tests in this suite do (a workaround for a *test*-only
 * version of this same quirk) — that would silently mask a regression of
 * this exact bug. tenancy() is never actually initialized in these
 * lightweight tests either, so this exercises the same fallback path a
 * real request takes when config('tenant.*') was never populated.
 */
class TenantUrlSubdomainResolutionTest extends TestCase
{
    public function test_current_subdomain_does_not_crash_when_config_is_the_raw_array_default(): void
    {
        // Whatever config/tenant.php actually ships as the default —
        // deliberately not overridden, unlike the rest of this test suite.
        $this->assertIsArray(config('tenant.subdomain'), 'this test assumes the untouched default shape — if this fails, the default itself changed');

        $result = TenantUrl::currentSubdomain();

        $this->assertNull($result);
    }

    public function test_current_subdomain_returns_a_real_string_when_one_is_configured(): void
    {
        config(['tenant.subdomain' => 'demoschool']);

        $this->assertSame('demoschool', TenantUrl::currentSubdomain());
    }

    public function test_admission_cycle_registration_url_does_not_crash_with_the_default_config(): void
    {
        $this->assertIsArray(config('tenant.subdomain'));

        $cycle = new \App\Models\AdmissionCycle();
        $url = $cycle->registration_url;

        $this->assertStringEndsWith('/apply', $url);
        $this->assertStringNotContainsString('Array', $url);
    }
}
