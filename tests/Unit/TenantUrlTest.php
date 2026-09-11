<?php

namespace Tests\Unit;

use App\Support\TenantUrl;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression test for the production bug fixed 2026-09-11: uploaded
 * pictures/signatures/etc. 404'd. FilesystemTenancyBootstrapper suffixes
 * the `public` disk's ROOT per tenant (storage/tenant{id}/app/public/...)
 * but never its `url`, which stays {APP_URL}/storage/... — resolving only
 * through the *central* storage/app/public symlink, not where the file
 * actually is. TenantUrl::forPublicDiskKey() routes tenant-context URLs
 * through TenantFileController instead.
 *
 * This only covers the "no tenant context" branch — the tenant branch
 * needs a real initialized tenant, which this repo has no test
 * infrastructure for yet (see also: tests/RouteTest.php, which assumes
 * exactly that and has never actually run). Covered live instead: a real
 * file was written to a live tenant's storage and fetched back through
 * GET /api/v1/files/{subdomain}/{path} on 2026-09-11 (see chat history /
 * deploy notes), which is the part this unit test can't reach.
 */
class TenantUrlTest extends TestCase
{
    public function test_outside_tenant_context_it_falls_back_to_the_plain_disk_url(): void
    {
        $this->assertFalse(
            function_exists('tenancy') && tenancy()->initialized,
            'this test assumes no tenant is initialized — if that changes, the assertion below is no longer testing the fallback branch'
        );

        $key = 'uploads/example.jpg';
        $this->assertSame(Storage::disk('public')->url($key), TenantUrl::forPublicDiskKey($key));
    }
}
