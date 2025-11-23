<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind custom subdomain resolver for stancl/tenancy
        // Must be in register() to be available when middleware is resolved
        $this->app->bind(
            \Stancl\Tenancy\Resolvers\RequestDataTenantResolver::class,
            \App\Resolvers\SubdomainTenantResolver::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Configure stancl/tenancy to use X-Subdomain header for tenant resolution
        \Stancl\Tenancy\Middleware\InitializeTenancyByRequestData::$header = 'X-Subdomain';
        
        // Ensure Sanctum uses the tenant-aware PersonalAccessToken model
        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);
    }
}
