<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Public URL for a key on the local "public" disk, tenant-aware.
 *
 * FilesystemTenancyBootstrapper suffixes the `public` disk's ROOT per tenant
 * (storage/tenant{id}/app/public/...) but leaves the disk's URL config alone
 * (always {APP_URL}/storage/...), which only resolves through the *central*
 * storage/app/public symlink. Anything saved to the public disk while
 * tenancy was active is therefore unreachable at the URL Storage::url()
 * builds. Route through TenantFileController instead, which resolves the
 * tenant explicitly and streams from that tenant's own disk.
 *
 * Use this everywhere a tenant-scoped public-disk key becomes a URL:
 * profile pictures, signatures, question/report images, generated PDFs, ...
 */
class TenantUrl
{
    public static function forPublicDiskKey(string $key): string
    {
        if (function_exists('tenancy') && tenancy()->initialized) {
            return route('tenant.file.show', [
                'subdomain' => tenant('subdomain') ?? tenant('id'),
                'path'      => $key,
            ]);
        }

        // No tenant context (central/super-admin upload) — the plain disk
        // URL is correct there, since it isn't suffixed either.
        return Storage::disk('public')->url($key);
    }
}
