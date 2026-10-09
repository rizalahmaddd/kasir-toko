<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'fonnte' => [
        'token' => env('FONNTE_TOKEN'),
        'url' => env('FONNTE_URL', 'https://api.fonnte.com/send'),
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'bundle_id' => env('APPLE_BUNDLE_ID', 'com.kasir.pos'),
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'redirect' => env('APPLE_REDIRECT_URI'),
    ],

    'sumopod' => [
        'api_key' => env('SUMOPOD_API_KEY'),
        'webhook_secret' => env('SUMOPOD_WEBHOOK_SECRET'),
        'api_url' => env('SUMOPOD_API_URL', 'https://api-pay-sandbox.sumopod.com/api/v1/payments'),
    ],

    'app_release' => [
        'upload_token' => env('APP_RELEASE_UPLOAD_TOKEN'),
    ],

];
