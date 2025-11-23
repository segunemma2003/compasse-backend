<?php

namespace App\Resolvers;

use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByRequestDataException;
use Stancl\Tenancy\Resolvers\RequestDataTenantResolver;

class SubdomainTenantResolver extends RequestDataTenantResolver
{
    public function resolve(...$args): Tenant
    {
        $subdomain = $args[0];

        if ($subdomain && $tenant = \App\Models\Tenant::where('subdomain', $subdomain)->first()) {
            return $tenant;
        }

        throw new TenantCouldNotBeIdentifiedByRequestDataException($subdomain);
    }
}

