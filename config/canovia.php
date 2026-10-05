<?php

return [
    'version' => env('CANOVIA_APP_VERSION', env('PACEKEEPER_APP_VERSION', 'v29')),
    'onboarding_version' => (int) env('CANOVIA_ONBOARDING_VERSION', 1),
    // V41.7: the Super Admin is one account only. Prefer immutable user ID;
    // admin_email remains a migration fallback until the ID is configured.
    'super_admin_user_id' => env('CANOVIA_SUPER_ADMIN_USER_ID'),
    'admin_email' => env('CANOVIA_ADMIN_EMAIL', env('PACEKEEPER_ADMIN_EMAIL')),
    'admin_password' => env('CANOVIA_ADMIN_PASSWORD', env('FEEDBACK_ADMIN_PASSWORD', env('TEMPLATE_ADMIN_PASSWORD'))),
    // Legacy config key kept while older admin code/routes are phased out.
    'feedback_admin_password' => env('FEEDBACK_ADMIN_PASSWORD', env('CANOVIA_ADMIN_PASSWORD', env('TEMPLATE_ADMIN_PASSWORD'))),

    // Canonical public origin. Route generation and shared URLs use this URL in production.
    'canonical_url' => rtrim((string) env('CANOVIA_CANONICAL_URL', env('APP_URL', '')), '/'),

    // V56.1 App Store / support metadata. Configure these explicitly in
    // production before the iOS Soft Launch.
    'support_email' => trim((string) env('CANOVIA_SUPPORT_EMAIL', '')),
    'operator_name' => trim((string) env('CANOVIA_OPERATOR_NAME', 'Canovia')),

    // Old public hosts that should hand users over to the canonical Canovia origin.
    'legacy_hosts' => array_values(array_filter(array_map(
        static fn (string $host) => trim(strtolower($host)),
        explode(',', (string) env('CANOVIA_LEGACY_HOSTS', 'pacekeeper-d3mm.onrender.com'))
    ))),

    'redirect_legacy_hosts' => filter_var(
        env('CANOVIA_REDIRECT_LEGACY_HOSTS', false),
        FILTER_VALIDATE_BOOL
    ),

    // V55.7: non-production UX validation only. No external API/OAuth is used.
    'execution_setup_validation_enabled' => filter_var(
        env('CANOVIA_EXECUTION_SETUP_VALIDATION_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),
];
