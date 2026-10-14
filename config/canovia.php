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

    // V56.18: Super Admin-only Study Scenario Lab. Keep disabled unless
    // actively validating Study surfaces in a controlled environment.
    'study_scenario_lab_enabled' => filter_var(
        env('CANOVIA_STUDY_SCENARIO_LAB_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

    // G3-R11: private path row-fencing pilot. OFF until every storage
    // writer participates and the additional DB schema is approved.
    'private_file_path_fence_enabled' => filter_var(
        env('CANOVIA_PRIVATE_FILE_PATH_FENCE_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

    // G3-R13: durable pre-upload intent journal is separately OFF by default;
    // enabling requires the path fence and an approved, migrated ledger.
    'private_file_upload_journal_enabled' => filter_var(
        env('CANOVIA_PRIVATE_FILE_UPLOAD_JOURNAL_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

    // G3-R14: explicitly gated non-production read-only targeted preview.
    // Neither flag authorizes object deletion or production reconciliation.
    'private_file_upload_reconcile_preview_enabled' => filter_var(
        env('CANOVIA_PRIVATE_FILE_UPLOAD_RECONCILE_PREVIEW_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

    // G3-R15: optional double-sampling of weak object metadata and references.
    // No immutable generation guarantee or deletion authorization; OFF.
    'private_file_upload_consistency_probe_enabled' => filter_var(
        env('CANOVIA_PRIVATE_FILE_UPLOAD_CONSISTENCY_PROBE_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

    // G3-R16: config-only provider generation inventory, no storage/network I/O,
    // never a deletion authority. OFF by default; production always blocked.
    'private_file_generation_contract_enabled' => filter_var(
        env('CANOVIA_PRIVATE_FILE_GENERATION_CONTRACT_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

    // G3-R17: synthetic-only write-intent-to-owner evidence fixture.
    // OFF by default; no provider receipt capture or deletion authority.
    'private_file_generation_evidence_enabled' => filter_var(
        env('CANOVIA_PRIVATE_FILE_GENERATION_EVIDENCE_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

    // G3-R18: synthetic-only PutObject receipt handoff for disposable PHP
    // tests. No S3 API, provider attestation or deletion permission. OFF.
    'private_file_write_receipt_handoff_enabled' => filter_var(
        env('CANOVIA_PRIVATE_FILE_WRITE_RECEIPT_HANDOFF_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

    // G3-R19: CI-only crash-point fixture replay, no real object I/O or
    // retry/delete permission. OFF by default, APP_ENV=testing required.
    'private_file_synthetic_crash_matrix_enabled' => filter_var(
        env('CANOVIA_PRIVATE_FILE_SYNTHETIC_CRASH_MATRIX_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

    // A public epoch label, NOT an encryption key. Changing it intentionally
    // blocks old proof reads until an explicit, audited migration exists.
    'private_file_generation_key_epoch' => env(
        'CANOVIA_PRIVATE_FILE_GENERATION_KEY_EPOCH', 'app-key-v1'
    ),
];
