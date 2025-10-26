<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for multi-tenant functionality including database
    | management, subdomain handling, and tenant isolation.
    |
    */

    'database' => [
        'prefix' => env('TENANT_DB_PREFIX', 'tenant_'),
        'connection_name' => env('TENANT_DB_CONNECTION', 'tenant'),
        'auto_create' => env('TENANT_AUTO_CREATE_DB', true),
        'auto_migrate' => env('TENANT_AUTO_MIGRATE', true),
    ],

    'subdomain' => [
        'enabled' => env('TENANT_SUBDOMAIN_ENABLED', true),
        'wildcard' => env('TENANT_WILDCARD_DOMAIN', '*.samschool.com'),
        'main_domain' => env('TENANT_MAIN_DOMAIN', 'samschool.com'),
    ],

    'isolation' => [
        'database_per_tenant' => env('TENANT_DB_PER_TENANT', true),
        'cache_per_tenant' => env('TENANT_CACHE_PER_TENANT', true),
        'storage_per_tenant' => env('TENANT_STORAGE_PER_TENANT', true),
    ],

    'middleware' => [
        'tenant_resolution' => [
            'subdomain' => true,
            'header' => true,
            'parameter' => true,
        ],
        'module_access' => [
            'enabled' => true,
            'strict_mode' => env('TENANT_STRICT_MODULE_ACCESS', true),
        ],
    ],

    'limits' => [
        'max_tenants_per_plan' => [
            'free' => 1,
            'basic' => 5,
            'premium' => 20,
            'enterprise' => -1, // unlimited
        ],
        'max_schools_per_tenant' => [
            'free' => 1,
            'basic' => 3,
            'premium' => 10,
            'enterprise' => -1, // unlimited
        ],
    ],

    'features' => [
        'auto_provisioning' => env('TENANT_AUTO_PROVISIONING', true),
        'custom_domains' => env('TENANT_CUSTOM_DOMAINS', true),
        'ssl_certificates' => env('TENANT_SSL_CERTIFICATES', true),
        'backup_automation' => env('TENANT_BACKUP_AUTOMATION', true),
    ],

    'security' => [
        'data_isolation' => env('TENANT_DATA_ISOLATION', true),
        'cross_tenant_access' => env('TENANT_CROSS_ACCESS', false),
        'audit_logging' => env('TENANT_AUDIT_LOGGING', true),
    ],
];
