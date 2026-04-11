<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://compasse.net',
        'https://www.compasse.net',
        // Tenant subdomains — e.g. schoolname.compasse.net
        // The wildcard pattern below covers *.compasse.net
    ],

    'allowed_origins_patterns' => [
        '#^https://[a-z0-9\-]+\.compasse\.net$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,
];
