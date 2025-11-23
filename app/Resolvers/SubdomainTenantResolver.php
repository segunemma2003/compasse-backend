<?php

namespace App\Resolvers;

use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByRequestDataException;
use Stancl\Tenancy\Resolvers\Contracts\CachedTenantResolver;

class SubdomainTenantResolver extends CachedTenantResolver
{
    /** @var bool */
    public static $shouldCache = false;

    /** @var int */
    public static $cacheTTL = 3600; // seconds

    /** @var string|null */
    public static $cacheStore = null; // default

    public function resolveWithoutCache(...$args): Tenant
    {
        $subdomain = $args[0];

        if ($subdomain && $tenant = \App\Models\Tenant::where('subdomain', $subdomain)->first()) {
            return $tenant;
        }

        throw new TenantCouldNotBeIdentifiedByRequestDataException($subdomain);
    }
}

