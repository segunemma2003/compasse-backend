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
        // One OAuth app for all of Compasse (Google Cloud → APIs & Services → Credentials, "Web application").
        // Authorised redirect URI: {APP_URL}/api/v1/integrations/google/callback. Enable the Google Calendar API.
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'frontend_domain' => env('FRONTEND_DOMAIN', 'compasse.net'),
    ],

    // Video rooms when no Google account is connected: the public meet.jit.si, or your own
    // self-hosted Jitsi Meet server (e.g. JITSI_DOMAIN=meet.compasse.net) — no time limits or sign-in.
    // ALOC past questions (questions.aloc.com.ng) for the central question bank. The super admin can
    // also paste the token in Question Bank → Import from ALOC.
    'aloc' => [
        'token' => env('ALOC_ACCESS_TOKEN'),
    ],

    'jitsi' => [
        'domain' => env('JITSI_DOMAIN', 'meet.jit.si'),
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
        // Public address schools' browsers are sent to (base_url is usually
        // an internal address only this server can reach).
        'public_url'     => env('PAYHUB_PUBLIC_URL', 'https://payhub.compasse.net'),
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
