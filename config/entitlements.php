<?php

use App\Enums\FeatureKey;

return [
    /*
    |--------------------------------------------------------------------------
    | Entitlement policy
    |--------------------------------------------------------------------------
    |
    | This configuration answers "may this actor use this published feature?"
    | It does NOT decide whether a feature is publicly exposed. Feature rollout
    | and beta visibility remain the responsibility of config/features.php.
    |
    | Existing user-facing capabilities remain free. V41.5 adds capability-level
    | keys for future Premium/Packs while keeping Feature code independent from
    | Product, billing, Gift, and Sponsor details.
    |
    */

    'default_policy' => 'allow',

    'features' => [
        FeatureKey::AiPractice->value => [
            'label' => 'AI Practice',
            'free' => true,
        ],
        FeatureKey::AdvancedAnalytics->value => [
            'label' => 'Advanced Analytics',
            'free' => true,
        ],
        FeatureKey::QuestionPack->value => [
            'label' => 'Question Pack',
            'free' => true,
        ],
        FeatureKey::ProjectArtifact->value => [
            'label' => 'Project Artifact',
            'free' => true,
        ],
        FeatureKey::AutomaticAiExecution->value => [
            'label' => 'Automatic AI Execution',
            // V41.8: manual external-AI handoff remains Free. Only Canovia-side
            // provider execution is a Premium Core capability.
            'free' => false,
        ],
        FeatureKey::CanoviaCompanion->value => [
            'label' => 'Canovia Companion',
            'free' => false,
        ],
        FeatureKey::StudyLongTermWeaknessProfile->value => [
            'label' => 'Study Long-term Weakness Profile',
            'free' => false,
        ],
        FeatureKey::CareerNativeCaptureAnalysis->value => [
            'label' => 'Career Native Capture Analysis',
            'free' => false,
        ],
        FeatureKey::DeveloperGithubEvidence->value => [
            'label' => 'Developer GitHub Evidence',
            'free' => false,
        ],
    ],
];
