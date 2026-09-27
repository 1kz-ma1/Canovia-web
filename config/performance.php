<?php

return [
    // During the current diagnosis phase, production core-navigation requests
    // are measured by default. Set PERFORMANCE_LOG_ENABLED=false to disable
    // without a code deploy.
    'enabled' => env(
        'PERFORMANCE_LOG_ENABLED',
        env('APP_ENV') === 'production',
    ),

    'paths' => [
        '/',
        '/inbox',
        '/roadmap',
        '/timeline',
        '/navigate',
        '/calendar',
    ],
];
