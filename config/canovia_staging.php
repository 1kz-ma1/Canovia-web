<?php

return [
    // Isolated experimental service only. Never enable in the production
    // Canovia deployment or copy its keys, sessions or database credentials.
    'isolated' => env('CANOVIA_STAGING_ISOLATED', false),
    'web_access_enabled' => env('CANOVIA_STAGING_WEB_ACCESS_ENABLED', false),
    // One-shot synthetic user bootstrap: leave disabled unless an operator
    // explicitly provisions a STAGING-only password out-of-band.
    // Keep SQLite by default. PostgreSQL requires an operator-pinned
    // internal Render DB URL and an independent reviewed cutover.
    'database_mode' => env('CANOVIA_STAGING_DB_MODE', 'sqlite'),
    'postgres_id' => env('CANOVIA_STAGING_POSTGRES_ID', ''),
    // Same DB user's exact identity, supplied by Render's fromDatabase:user
    // reference during an approved staging-only Blueprint sync.
    'postgres_user' => env('CANOVIA_STAGING_POSTGRES_USER', ''),
    'allow_synthetic_owner_bootstrap' => env('CANOVIA_STAGING_ALLOW_SYNTHETIC_OWNER_BOOTSTRAP', false),
    'synthetic_owner_password' => env('CANOVIA_STAGING_SYNTHETIC_OWNER_PASSWORD', ''),
];
