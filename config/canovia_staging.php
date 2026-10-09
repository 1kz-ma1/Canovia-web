<?php

return [
    // Isolated experimental service only. Never enable in the production
    // Canovia deployment or copy its keys, sessions or database credentials.
    'isolated' => env('CANOVIA_STAGING_ISOLATED', false),
    'web_access_enabled' => env('CANOVIA_STAGING_WEB_ACCESS_ENABLED', false),
    // One-shot synthetic user bootstrap: leave disabled unless an operator
    // explicitly provisions a STAGING-only password out-of-band.
    'allow_synthetic_owner_bootstrap' => env('CANOVIA_STAGING_ALLOW_SYNTHETIC_OWNER_BOOTSTRAP', false),
    'synthetic_owner_password' => env('CANOVIA_STAGING_SYNTHETIC_OWNER_PASSWORD', ''),
];
