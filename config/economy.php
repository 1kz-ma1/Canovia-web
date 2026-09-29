<?php

use App\Enums\FeatureKey;
use App\Enums\ProductKey;

return [
    'products' => [
        ProductKey::PremiumCore->value => [
            'label' => 'Premium Core',
            'kind' => 'core',
            'includes' => [],
            'requires' => [],
            'feature_keys' => [
                FeatureKey::AutomaticAiExecution->value,
                FeatureKey::CanoviaCompanion->value,
            ],
        ],
        ProductKey::StudyPack->value => [
            'label' => 'Study Pack',
            'kind' => 'pack',
            'includes' => [],
            'requires' => [ProductKey::PremiumCore->value],
            'feature_keys' => [
                FeatureKey::StudyLongTermWeaknessProfile->value,
            ],
        ],
        ProductKey::CareerPack->value => [
            'label' => 'Career Pack',
            'kind' => 'pack',
            'includes' => [],
            'requires' => [ProductKey::PremiumCore->value],
            'feature_keys' => [
                FeatureKey::CareerNativeCaptureAnalysis->value,
            ],
        ],
        ProductKey::DeveloperPack->value => [
            'label' => 'Developer Pack',
            'kind' => 'pack',
            'includes' => [],
            'requires' => [ProductKey::PremiumCore->value],
            'feature_keys' => [
                FeatureKey::DeveloperGithubEvidence->value,
                FeatureKey::DeveloperGithubWrite->value,
            ],
        ],
        ProductKey::CreatorPack->value => [
            'label' => 'Creator Pack',
            'kind' => 'pack',
            'includes' => [],
            'requires' => [ProductKey::PremiumCore->value],
            'feature_keys' => [],
        ],
        ProductKey::AllAccess->value => [
            'label' => 'All Access',
            'kind' => 'bundle',
            'includes' => [
                ProductKey::PremiumCore->value,
                ProductKey::StudyPack->value,
                ProductKey::CareerPack->value,
                ProductKey::DeveloperPack->value,
                ProductKey::CreatorPack->value,
            ],
            'requires' => [],
            'feature_keys' => [],
        ],
        ProductKey::AiCapacityBoost->value => [
            'label' => 'AI Capacity Boost',
            'kind' => 'capacity',
            'includes' => [],
            'requires' => [ProductKey::PremiumCore->value],
            'feature_keys' => [],
        ],
    ],

    'ai_capacity' => [
        'default' => 'standard',
        'tiers' => [
            'standard' => [
                'label' => 'Standard',
                'description' => 'Native AIの標準利用枠。利用権とは別に判定します。',
            ],
            'boosted' => [
                'label' => 'Boosted',
                'description' => 'Native AIの拡張利用枠。Packの機能解放とは独立しています。',
            ],
        ],
    ],
];
