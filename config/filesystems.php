<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
            // Bulk uploads (students/teachers/staff/scores) are written here by
            // PHP-FPM (www-data) and read + deleted by the bulk-uploads queue
            // worker, which runs as a different OS user (`deploy` — see
            // supervisor/compasse-bulk-worker.conf) that isn't a member of the
            // www-data group. Flysystem's default directory mode (0755, but
            // observed as low as 0700 depending on umask) leaves every
            // freshly-created upload subdirectory unreadable to the worker the
            // first time that upload type is used for a tenant — confirmed
            // live 2026-09-12: a brand-new "scores" subdirectory came out
            // `drwx------ www-data:www-data`, and the worker's very next job
            // failed with "Upload file not found. It may have expired." even
            // though the file was sitting right there. These are transient
            // CSVs deleted immediately after processing, not data retained at
            // rest, so world rw is the pragmatic fix here (vs. reconciling
            // unix groups across two independently-managed processes).
            'permissions' => [
                'file' => ['public' => 0664, 'private' => 0666],
                'dir'  => ['public' => 0775, 'private' => 0777],
            ],
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
