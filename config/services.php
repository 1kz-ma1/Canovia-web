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

    'github' => [
        'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),
        // Optional service-owned read token for higher rate limits.
        // Per-user private repository access is intentionally not implemented here.
        'read_token' => env('GITHUB_READ_TOKEN'),

        // V46.4 GitHub App write adapter. Private keys are server-side only.
        'app_id' => env('GITHUB_APP_ID'),
        'app_private_key' => env('GITHUB_APP_PRIVATE_KEY'),
        'app_private_key_base64' => env('GITHUB_APP_PRIVATE_KEY_BASE64'),
        'app_install_url' => env('GITHUB_APP_INSTALL_URL'),
        // GitHub App webhook shared secret. Never expose to users or clients.
        'app_webhook_secret' => env('GITHUB_APP_WEBHOOK_SECRET'),
    ],

];
