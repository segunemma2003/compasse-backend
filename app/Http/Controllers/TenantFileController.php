<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Serves files from a tenant's own "public" disk.
 *
 * Why this exists: FilesystemTenancyBootstrapper suffixes the `public` disk's
 * ROOT per tenant (storage/tenant{id}/app/public/...) but does NOT suffix its
 * URL — Storage::disk('public')->url() always builds {APP_URL}/storage/{key},
 * which only ever resolves through the *central* storage/app/public symlink.
 * A file saved while tenancy was active is therefore never reachable at the
 * URL we hand back to the browser. This route resolves the tenant explicitly
 * from the path (mirroring PublicAdmissionController's pattern) and streams
 * the file from that tenant's own disk instead of relying on the symlink.
 */
class TenantFileController extends Controller
{
    public function show(Request $request, string $subdomain, string $path)
    {
        // Reject path traversal / absolute paths before touching the filesystem.
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..')) {
            abort(404);
        }

        $tenant = Tenant::where('subdomain', strtolower(trim($subdomain)))->first();
        if (! $tenant || $tenant->status !== 'active') {
            abort(404);
        }

        tenancy()->initialize($tenant);

        try {
            $disk = Storage::disk('public');

            if (! $disk->exists($path)) {
                abort(404);
            }

            return response($disk->get($path), 200)
                ->header('Content-Type', $disk->mimeType($path) ?: 'application/octet-stream')
                ->header('Cache-Control', 'public, max-age=31536000, immutable');
        } finally {
            tenancy()->end();
        }
    }
}
