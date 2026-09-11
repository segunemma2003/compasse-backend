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

    /**
     * The current tenant's subdomain, resolved the way it actually is at
     * runtime — NOT via config('tenant.subdomain') directly.
     *
     * All real API traffic hits api.compasse.net with an X-Subdomain
     * header (TenantMiddleware::resolveTenantFromApiRequest()), which
     * initializes stancl/tenancy but never writes to the
     * config('tenant.*') keys — those are only set by this app's own
     * TenantMiddleware in its OTHER branch (real subdomain hosts like
     * demoschool.compasse.net), which production doesn't actually use.
     * So config('tenant.subdomain') stays at its config/tenant.php
     * default the whole time — an ARRAY ({enabled, wildcard,
     * main_domain}), not a string. Two call sites string-interpolated it
     * directly ("https://{$subdomain}...") and crashed with "Array to
     * string conversion" on every real request: enrolling a student with
     * a guardian attached (ManagesGuardianAccounts::portalUrl(), used for
     * the credentials/welcome email body) and every read of an admission
     * cycle (AdmissionCycle::registration_url is an appended attribute,
     * computed on every serialization). Confirmed live 2026-09-11.
     */
    public static function currentSubdomain(): ?string
    {
        if (function_exists('tenancy') && tenancy()->initialized) {
            return tenant('subdomain') ?? tenant('id');
        }

        $configured = config('tenant.subdomain');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }
}
