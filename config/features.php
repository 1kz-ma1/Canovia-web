<?php

use App\Enums\FeatureKey;

$canoviaAi = (bool) env('FEATURE_CANOVIA_AI', env('FEATURE_PACEKEEPER_AI', false));

return [
    // Feature Flags control runtime publication/visibility only.
    // Per-actor usage rights belong to FeatureAccessService / config/entitlements.php.
    'flags' => [
        FeatureKey::AutomaticAiExecution->value => [
            'enabled' => $canoviaAi,
            'environment' => null,
            'platform' => 'all',
            'minimum_app_version' => null,
        ],
        FeatureKey::CanoviaCompanion->value => [
            'enabled' => $canoviaAi,
            'environment' => null,
            'platform' => 'all',
            'minimum_app_version' => null,
        ],
        FeatureKey::ConversationalOnboarding->value => [
            'enabled' => $canoviaAi,
            'environment' => null,
            'platform' => 'all',
            'minimum_app_version' => null,
        ],
    ],

    // Legacy internal keys kept for backwards compatibility with older deployments.
    'canovia_ai' => $canoviaAi,
    'pacekeeper_ai' => $canoviaAi,
];
