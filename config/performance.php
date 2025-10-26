<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Performance Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for performance optimization including caching,
    | database optimization, and response time targets.
    |
    */

    'cache' => [
        'default_ttl' => env('CACHE_DEFAULT_TTL', 3600), // 1 hour
        'student_ttl' => env('CACHE_STUDENT_TTL', 300), // 5 minutes
        'teacher_ttl' => env('CACHE_TEACHER_TTL', 300), // 5 minutes
        'school_ttl' => env('CACHE_SCHOOL_TTL', 600), // 10 minutes
        'stats_ttl' => env('CACHE_STATS_TTL', 3600), // 1 hour
        'subscription_ttl' => env('CACHE_SUBSCRIPTION_TTL', 3600), // 1 hour
    ],

    'database' => [
        'slow_query_threshold' => env('SLOW_QUERY_THRESHOLD', 1000), // 1 second
        'max_queries_per_request' => env('MAX_QUERIES_PER_REQUEST', 100),
        'enable_query_logging' => env('ENABLE_QUERY_LOGGING', false),
    ],

    'response_time' => [
        'target' => env('RESPONSE_TIME_TARGET', 3000), // 3 seconds
        'warning_threshold' => env('RESPONSE_TIME_WARNING', 2000), // 2 seconds
        'critical_threshold' => env('RESPONSE_TIME_CRITICAL', 5000), // 5 seconds
    ],

    'optimization' => [
        'enable_eager_loading' => env('ENABLE_EAGER_LOADING', true),
        'enable_query_caching' => env('ENABLE_QUERY_CACHING', true),
        'enable_result_caching' => env('ENABLE_RESULT_CACHING', true),
        'enable_api_caching' => env('ENABLE_API_CACHING', true),
    ],

    'monitoring' => [
        'enable_performance_monitoring' => env('ENABLE_PERFORMANCE_MONITORING', true),
        'log_slow_requests' => env('LOG_SLOW_REQUESTS', true),
        'log_slow_queries' => env('LOG_SLOW_QUERIES', true),
        'performance_report_interval' => env('PERFORMANCE_REPORT_INTERVAL', 3600), // 1 hour
    ],

    'limits' => [
        'max_students_per_school' => env('MAX_STUDENTS_PER_SCHOOL', 10000),
        'max_teachers_per_school' => env('MAX_TEACHERS_PER_SCHOOL', 1000),
        'max_classes_per_school' => env('MAX_CLASSES_PER_SCHOOL', 100),
        'max_subjects_per_school' => env('MAX_SUBJECTS_PER_SCHOOL', 50),
    ],

    'caching_strategies' => [
        'student_data' => [
            'enabled' => true,
            'ttl' => 300, // 5 minutes
            'tags' => ['students', 'school'],
        ],
        'teacher_data' => [
            'enabled' => true,
            'ttl' => 300, // 5 minutes
            'tags' => ['teachers', 'school'],
        ],
        'school_data' => [
            'enabled' => true,
            'ttl' => 600, // 10 minutes
            'tags' => ['school'],
        ],
        'subscription_data' => [
            'enabled' => true,
            'ttl' => 3600, // 1 hour
            'tags' => ['subscription', 'school'],
        ],
        'exam_data' => [
            'enabled' => true,
            'ttl' => 1800, // 30 minutes
            'tags' => ['exams', 'school'],
        ],
        'statistics' => [
            'enabled' => true,
            'ttl' => 3600, // 1 hour
            'tags' => ['stats', 'school'],
        ],
    ],
];
