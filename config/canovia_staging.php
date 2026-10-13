<?php

return [
    // Isolated experimental service only. Never enable in the production
    // Canovia deployment or copy its keys, sessions or database credentials.
    'isolated' => env('CANOVIA_STAGING_ISOLATED', false),
    'web_access_enabled' => env('CANOVIA_STAGING_WEB_ACCESS_ENABLED', false),
    // Independent manual approval prevents accidental exposure from a
    // single web-access toggle. Neither switch is enabled by default.
    'web_access_explicitly_approved' => env('CANOVIA_STAGING_WEB_ACCESS_EXPLICITLY_APPROVED', false),
    // Separate stage-only route allowlists. Never open the full Canovia app.
    // Login pilot exposes only login/account/logout; later IdP linking needs
    // an independent switch and verified signed-JWT provider configuration.
    'login_pilot_enabled' => env('CANOVIA_STAGING_LOGIN_PILOT_ENABLED', false),
    'account_link_pilot_enabled' => env('CANOVIA_STAGING_ACCOUNT_LINK_PILOT_ENABLED', false),
    // Third independent approval: one synthetic Development Plan; MCP read stays OFF.
    'plan_consent_pilot_enabled' => env('CANOVIA_STAGING_PLAN_CONSENT_PILOT_ENABLED', false),
    // Independent approval for MCP POST + OAuth protected-resource metadata.
    // Never conflate prior identity/Plan consent approval with read exposure.
    'synthetic_read_pilot_enabled' => env('CANOVIA_STAGING_SYNTHETIC_READ_PILOT_ENABLED', false),
    'mcp_explicitly_approved' => env('CANOVIA_STAGING_MCP_EXPLICITLY_APPROVED', false),
    // Separate operator attestation for one Auth0 CIMD registration:
    // same Auth0 application's internal tpc_ ID and externally registered
    // ChatGPT stable metadata URL. Default OFF. Never applies to production,
    // account-link tokens, arbitrary URL-shaped azp or delegated grants.
    'mcp_cimd_mapping_verified' => env('CANOVIA_STAGING_MCP_CIMD_MAPPING_VERIFIED', false),
    'mcp_cimd_external_client_id' => env('CANOVIA_STAGING_MCP_CIMD_EXTERNAL_CLIENT_ID', ''),
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
    // Separate, one-shot operator approval for resetting ONLY the fixed
    // synthetic actor in an isolated, closed stage. Never persist a rotation
    // secret beyond its single startup.
    'allow_synthetic_password_rotation' => env('CANOVIA_STAGING_ALLOW_SYNTHETIC_PASSWORD_ROTATION', false),
    'rotate_synthetic_password_on_start' => env('CANOVIA_STAGING_ROTATE_SYNTHETIC_PASSWORD_ON_START', false),
    'synthetic_owner_rotated_password' => env('CANOVIA_STAGING_SYNTHETIC_OWNER_ROTATED_PASSWORD', ''),

];
