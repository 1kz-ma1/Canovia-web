<?php

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

    // User-specific beta access must never unlock Internal Preview.
    'maximum_user_override' => ReleaseLevel::BetaExpansion->value,
];
