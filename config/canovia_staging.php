<?php

return [
    // Isolated experimental service only. Never enable in the production
    // Canovia deployment or copy its keys, sessions or database credentials.
    'isolated' => env('CANOVIA_STAGING_ISOLATED', false),
    'web_access_enabled' => env('CANOVIA_STAGING_WEB_ACCESS_ENABLED', false),
];
