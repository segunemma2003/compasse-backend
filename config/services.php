<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'api_key' => env('GOOGLE_API_KEY'),
    ],

    /*
    | Video: "meet" = simple browser room link (no Google Cloud). "mux" = optional in-app stream (MUX_* keys).
    */
    'livestream' => [
        'provider' => env('LIVESTREAM_PROVIDER', 'meet'), // meet | mux
    ],

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'currency'   => env('PAYSTACK_CURRENCY', 'NGN'),
    ],

    'flutterwave' => [
        'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        'currency'   => env('FLUTTERWAVE_CURRENCY', 'NGN'),
    ],

    'payments' => [
        'default_provider' => env('PAYMENTS_DEFAULT_PROVIDER', 'paystack'),
    ],

    'mux' => [
        'token_id'     => env('MUX_TOKEN_ID'),
        'token_secret' => env('MUX_TOKEN_SECRET'),
        'webhook_secret' => env('MUX_WEBHOOK_SECRET'),
    ],

];
