<?php

return [
    /*
    |--------------------------------------------------------------------------
    | New Relic Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for New Relic APM monitoring integration.
    | This provides comprehensive application performance monitoring.
    |
    */

    'enabled' => env('NEW_RELIC_ENABLED', true),
    'license_key' => env('NEW_RELIC_LICENSE_KEY'),
    'app_name' => env('NEW_RELIC_APP_NAME', 'SamSchool Management System'),
    'environment' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Settings
    |--------------------------------------------------------------------------
    */
    'application' => [
        'name' => env('NEW_RELIC_APP_NAME', 'SamSchool Management System'),
        'version' => env('NEW_RELIC_APP_VERSION', '1.0.0'),
        'environment' => env('APP_ENV', 'production'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction Settings
    |--------------------------------------------------------------------------
    */
    'transactions' => [
        'enabled' => env('NEW_RELIC_TRANSACTIONS_ENABLED', true),
        'trace_threshold' => env('NEW_RELIC_TRACE_THRESHOLD', 0.5), // seconds
        'slow_sql_threshold' => env('NEW_RELIC_SLOW_SQL_THRESHOLD', 0.1), // seconds
        'max_trace_segments' => env('NEW_RELIC_MAX_TRACE_SEGMENTS', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Metrics
    |--------------------------------------------------------------------------
    */
    'custom_metrics' => [
        'enabled' => env('NEW_RELIC_CUSTOM_METRICS_ENABLED', true),
        'prefix' => env('NEW_RELIC_CUSTOM_METRICS_PREFIX', 'SamSchool'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Tracking
    |--------------------------------------------------------------------------
    */
    'errors' => [
        'enabled' => env('NEW_RELIC_ERRORS_ENABLED', true),
        'capture_errors' => env('NEW_RELIC_CAPTURE_ERRORS', true),
        'capture_exceptions' => env('NEW_RELIC_CAPTURE_EXCEPTIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Monitoring
    |--------------------------------------------------------------------------
    */
    'database' => [
        'enabled' => env('NEW_RELIC_DATABASE_ENABLED', true),
        'slow_query_threshold' => env('NEW_RELIC_SLOW_QUERY_THRESHOLD', 0.1),
        'capture_sql' => env('NEW_RELIC_CAPTURE_SQL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy Support
    |--------------------------------------------------------------------------
    */
    'multi_tenancy' => [
        'enabled' => env('NEW_RELIC_MULTI_TENANCY_ENABLED', true),
        'track_tenant_metrics' => env('NEW_RELIC_TRACK_TENANT_METRICS', true),
        'tenant_attribute_name' => 'tenant_id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Monitoring
    |--------------------------------------------------------------------------
    */
    'performance' => [
        'enabled' => env('NEW_RELIC_PERFORMANCE_ENABLED', true),
        'track_response_times' => env('NEW_RELIC_TRACK_RESPONSE_TIMES', true),
        'track_memory_usage' => env('NEW_RELIC_TRACK_MEMORY_USAGE', true),
        'track_cpu_usage' => env('NEW_RELIC_TRACK_CPU_USAGE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Business Metrics
    |--------------------------------------------------------------------------
    */
    'business_metrics' => [
        'enabled' => env('NEW_RELIC_BUSINESS_METRICS_ENABLED', true),
        'track_user_activity' => env('NEW_RELIC_TRACK_USER_ACTIVITY', true),
        'track_api_usage' => env('NEW_RELIC_TRACK_API_USAGE', true),
        'track_feature_usage' => env('NEW_RELIC_TRACK_FEATURE_USAGE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerting
    |--------------------------------------------------------------------------
    */
    'alerting' => [
        'enabled' => env('NEW_RELIC_ALERTING_ENABLED', true),
        'response_time_threshold' => env('NEW_RELIC_RESPONSE_TIME_THRESHOLD', 3.0), // seconds
        'error_rate_threshold' => env('NEW_RELIC_ERROR_RATE_THRESHOLD', 5.0), // percentage
        'memory_usage_threshold' => env('NEW_RELIC_MEMORY_USAGE_THRESHOLD', 80.0), // percentage
    ],

    /*
    |--------------------------------------------------------------------------
    | Distributed Tracing
    |--------------------------------------------------------------------------
    */
    'distributed_tracing' => [
        'enabled' => env('NEW_RELIC_DISTRIBUTED_TRACING_ENABLED', true),
        'cross_application_tracing' => env('NEW_RELIC_CROSS_APP_TRACING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Attributes
    |--------------------------------------------------------------------------
    */
    'custom_attributes' => [
        'enabled' => env('NEW_RELIC_CUSTOM_ATTRIBUTES_ENABLED', true),
        'max_attributes' => env('NEW_RELIC_MAX_ATTRIBUTES', 64),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Integration
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => env('NEW_RELIC_LOGGING_ENABLED', true),
        'log_level' => env('NEW_RELIC_LOG_LEVEL', 'info'),
        'capture_logs' => env('NEW_RELIC_CAPTURE_LOGS', true),
    ],
];
