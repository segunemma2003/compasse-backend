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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Configure stancl/tenancy to use X-Subdomain header for tenant resolution
        \Stancl\Tenancy\Middleware\InitializeTenancyByRequestData::$header = 'X-Subdomain';
        
        // Bind custom subdomain resolver for stancl/tenancy
        $this->app->bind(
            \Stancl\Tenancy\Resolvers\RequestDataTenantResolver::class,
            \App\Resolvers\SubdomainTenantResolver::class
        );
        
        // Ensure Sanctum uses the current database connection
        // This allows Sanctum to work with tenant databases
        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);
    }
}
