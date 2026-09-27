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

    // A student/guardian paying a school fee online goes through PayHub
    // (a custodial ledger + payout platform) instead of Compasse talking to
    // Paystack/Flutterwave directly — PayHub picks the rail, holds the
    // money, and a school withdraws it via PayHub's own approval flow.
    // See app/Services/PayHubService.php.
    'payhub' => [
        'base_url'       => env('PAYHUB_BASE_URL', 'http://localhost:8000'),
        'admin_email'    => env('PAYHUB_ADMIN_EMAIL'),
        'admin_password' => env('PAYHUB_ADMIN_PASSWORD'),
        // Which rail PayHub should use for a fee charge when the caller
        // doesn't ask for a specific one.
        'default_provider' => env('PAYHUB_DEFAULT_PROVIDER', 'paystack'),
        // Live vs test API keys when PayHub provisions a company for a
        // school for the first time.
        'live' => env('PAYHUB_LIVE', false),
    ],

    'mux' => [
        'token_id'     => env('MUX_TOKEN_ID'),
        'token_secret' => env('MUX_TOKEN_SECRET'),
        'webhook_secret' => env('MUX_WEBHOOK_SECRET'),
    ],

];
