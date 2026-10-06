<?php

use App\Enums\FeatureKey;
use App\Enums\ReleaseLevel;
use App\Enums\WorkspaceMode;

return [
    /*
    |--------------------------------------------------------------------------
    | Public Release Level
    |--------------------------------------------------------------------------
    |
    | Keep the default at Internal Preview while the staged-release foundation
    | is introduced so a deploy does not silently remove existing surfaces.
    | Early Access can lower this with CANOVIA_PUBLIC_RELEASE_LEVEL once the
    | corresponding release gate has been verified.
    |
    */
    'public_level' => (int) env(
        'CANOVIA_PUBLIC_RELEASE_LEVEL',
        ReleaseLevel::InternalPreview->value,
    ),

    /*
    |--------------------------------------------------------------------------
    | Early Access target
    |--------------------------------------------------------------------------
    |
    | This is a planning target only. It never changes Public Release Level.
    |
    */
    'early_access_target' => ReleaseLevel::ProductPreview->value,

    /*
    |--------------------------------------------------------------------------
    | Workspace minimum levels
    |--------------------------------------------------------------------------
    |
    | The registry remains the canonical catalog. This map only says when a
    | dedicated Workspace becomes public for the resolved actor level.
    |
    */
    'workspace_minimum' => [
        WorkspaceMode::Overview->value => ReleaseLevel::CoreStable->value,
        WorkspaceMode::Study->value => ReleaseLevel::EarlyAccessCore->value,
        WorkspaceMode::Development->value => ReleaseLevel::EarlyAccessCore->value,
        WorkspaceMode::Career->value => ReleaseLevel::BetaExpansion->value,
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature minimum levels
    |--------------------------------------------------------------------------
    |
    | This is publication maturity, not entitlement. A Feature may satisfy this
    | level and still be denied by FeatureAccessService or disabled by an
    | individual Feature Flag.
    |
    | Level 2 intentionally has no paid capability unlocks. Product Preview is
    | presentation-only during Early Access; Premium / Pro / Dev Pro remain
    | Coming Soon until a later monetization migration.
    |
    */
    'feature_minimum' => [
        FeatureKey::AiPractice->value => ReleaseLevel::EarlyAccessCore->value,
        FeatureKey::AdvancedAnalytics->value => ReleaseLevel::EarlyAccessCore->value,
        FeatureKey::QuestionPack->value => ReleaseLevel::EarlyAccessCore->value,
        FeatureKey::ProjectArtifact->value => ReleaseLevel::EarlyAccessCore->value,
        FeatureKey::ConversationalOnboarding->value => ReleaseLevel::EarlyAccessCore->value,
        FeatureKey::StudyScopeCapture->value => ReleaseLevel::EarlyAccessCore->value,

        FeatureKey::AutomaticAiExecution->value => ReleaseLevel::BetaExpansion->value,
        FeatureKey::CanoviaCompanion->value => ReleaseLevel::BetaExpansion->value,
        FeatureKey::StudyLongTermWeaknessProfile->value => ReleaseLevel::BetaExpansion->value,
        FeatureKey::CareerNativeCaptureAnalysis->value => ReleaseLevel::BetaExpansion->value,
        FeatureKey::DeveloperGithubEvidence->value => ReleaseLevel::BetaExpansion->value,

        // Repository mutation remains Internal Preview until a dedicated
        // rollout / rollback contract is approved.
        FeatureKey::DeveloperGithubWrite->value => ReleaseLevel::InternalPreview->value,
    ],

    /*
    |--------------------------------------------------------------------------
    | Release Gate contract
    |--------------------------------------------------------------------------
    |
    | Automated checks only validate structural publication contracts.
    | Manual checks remain visible and intentionally cannot be auto-approved by
    | this config. Passing automated checks means "candidate for human review",
    | not "safe to promote automatically".
    |
    */
    'gate' => [
        'levels' => [
            ReleaseLevel::CoreStable->value => [
                'required_workspaces' => [
                    WorkspaceMode::Overview->value,
                ],
                'required_features' => [],
                'required_routes' => [
                    'home',
                    'navigation.index',
                    'inbox.index',
                ],
                'manual_checks' => [
                    'Core Goal / Plan / Task / Evidence flow smoke test',
                    'Settings / account recovery flow confirmation',
                    'No blocker 500 or unintended 403 on core paths',
                ],
            ],

            ReleaseLevel::EarlyAccessCore->value => [
                'required_workspaces' => [
                    WorkspaceMode::Study->value,
                    WorkspaceMode::Development->value,
                ],
                'required_features' => [
                    FeatureKey::AiPractice->value,
                    FeatureKey::QuestionPack->value,
                    FeatureKey::ProjectArtifact->value,
                    FeatureKey::StudyScopeCapture->value,
                ],
                'required_routes' => [
                    'workspace.study.top',
                    'workspace.development.top',
                    'plans.tasks.study_practice.show',
                    'plans.study_scope.index',
                    'plans.artifacts.index',
                    'feedback.index',
                    'behavior_events.store',
                ],
                'manual_checks' => [
                    'Study / Development first-use flow on mobile and desktop',
                    'Early Access disclosure copy review',
                    'Downgrade to Level 0 preserves data and usable core flow',
                    'Feedback path and telemetry payload review',
                ],
            ],

            ReleaseLevel::ProductPreview->value => [
                'required_workspaces' => [],
                'required_features' => [],
                'required_routes' => [
                    // V58.21 intentionally keeps L2 blocked until a canonical
                    // Free / Premium / Pro / Dev Pro Coming Soon surface exists.
                    'product.preview.index',
                ],
                'manual_checks' => [
                    'Premium / Pro / Dev Pro preview copy reviewed against Monetization Spec',
                    'Preview clearly states Coming Soon and exposes no checkout path',
                    'Study / Development experience differences are understandable without pricing',
                ],
            ],

            ReleaseLevel::BetaExpansion->value => [
                'required_workspaces' => [
                    WorkspaceMode::Career->value,
                ],
                'required_features' => [],
                'required_routes' => [
                    'workspace.career.index',
                    'plans.career.index',
                ],
                'manual_checks' => [
                    'Career dedicated UI / basic flow alignment',
                    'Selected Beta user downgrade restores hidden data safely',
                    'Beta-only capability telemetry and support path verified',
                ],
            ],

            ReleaseLevel::InternalPreview->value => [
                'required_workspaces' => [],
                'required_features' => [],
                'required_routes' => [],
                'manual_checks' => [
                    'Internal-only surfaces are not reachable from lower levels',
                ],
            ],
        ],
    ],

    // User-specific beta access must never unlock Internal Preview.
    'maximum_user_override' => ReleaseLevel::BetaExpansion->value,
];
