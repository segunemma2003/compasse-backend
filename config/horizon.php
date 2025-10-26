<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN', null),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. This path
    | will be used to access Horizon from your application's frontend.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors and failed jobs.
    |
    */

    'use' => env('HORIZON_REDIS_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Middleware
    |--------------------------------------------------------------------------
    |
    | This is the middleware that will be applied to the Horizon dashboard.
    | You may modify this middleware as you see fit.
    |
    */

    'middleware' => ['web', 'auth:sanctum', 'role:super_admin'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own threshold to determine what a "long wait" is.
    |
    */

    'waits' => [
        'redis:default' => 60,
        'redis:high' => 30,
        'redis:low' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, you may want to keep
    | the recent jobs for a few hours while the failed jobs for a week.
    |
    */

    'trim' => [
        'recent' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can reduce deployment latency by
    | not waiting on workers to terminate.
    |
    */

    'fast_termination' => env('HORIZON_FAST_TERMINATION', false),

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory a worker may consume
    | before it is terminated and restarted. You should set this value
    | according to the resources available to your application.
    |
    */

    'memory_limit' => env('HORIZON_MEMORY_LIMIT', 64),

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure the queue settings for your application. These
    | settings are used by Horizon to determine how to process jobs.
    |
    */

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => ['default', 'high', 'low'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'maxProcesses' => 10,
                'maxTime' => 0,
                'maxJobs' => 0,
                'memory' => 128,
                'tries' => 3,
                'timeout' => 60,
                'nice' => 0,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'simple',
                'processes' => 3,
                'tries' => 3,
                'timeout' => 60,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Balancer
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure the queue balancer for your
    | application. You may modify this setting as you see fit.
    |
    */

    'balance' => env('HORIZON_BALANCE', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Auto Scaling Strategy
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure the auto scaling strategy for your
    | application. You may modify this setting as you see fit.
    |
    */

    'auto_scaling_strategy' => env('HORIZON_AUTO_SCALING_STRATEGY', 'time'),

    /*
    |--------------------------------------------------------------------------
    | Maximum Processes
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure the maximum number of processes
    | that Horizon may spawn for a given queue. You may modify this
    | setting as you see fit.
    |
    */

    'max_processes' => env('HORIZON_MAX_PROCESSES', 10),

    /*
    |--------------------------------------------------------------------------
    | Maximum Time
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure the maximum time (in seconds) that
    | a worker may run before it is terminated and restarted. You may
    | modify this setting as you see fit.
    |
    */

    'max_time' => env('HORIZON_MAX_TIME', 0),

    /*
    |--------------------------------------------------------------------------
    | Maximum Jobs
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure the maximum number of jobs that
    | a worker may process before it is terminated and restarted. You may
    | modify this setting as you see fit.
    |
    */

    'max_jobs' => env('HORIZON_MAX_JOBS', 0),

    /*
    |--------------------------------------------------------------------------
    | Memory Limit
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure the maximum amount of memory (in MB)
    | that a worker may consume before it is terminated and restarted. You
    | may modify this setting as you see fit.
    |
    */

    'memory' => env('HORIZON_MEMORY', 128),

    /*
    |--------------------------------------------------------------------------
    | Tries
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure the maximum number of times a job
    | may be attempted before it is considered failed. You may modify
    | this setting as you see fit.
    |
    */

    'tries' => env('HORIZON_TRIES', 3),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure the maximum amount of time (in
    | seconds) that a job may run before it is considered failed. You
    | may modify this setting as you see fit.
    |
    */

    'timeout' => env('HORIZON_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Nice
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure the nice value for the worker
    | processes. You may modify this setting as you see fit.
    |
    */

    'nice' => env('HORIZON_NICE', 0),
];
