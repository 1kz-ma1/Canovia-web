<?php

return [
    // OFF until separate owner-facing opt-in and release verification.
    // Does not authorize automatic notification or cross-Plan monitoring.
    'learning_context_watch' => [
        'persistence_enabled' => env('CANOVIA_LEARNING_CONTEXT_WATCH_PERSISTENCE_ENABLED', false),
        // Shadow-only source mutation epoch; never used for Watch freshness.
        'epoch_shadow_enabled' => env('CANOVIA_LEARNING_CONTEXT_EPOCH_SHADOW_ENABLED', false),
    ],

    'reasoning' => [
        // deterministic | auto | openai
        'mode' => env('CANOVIA_INTELLIGENCE_REASONING_MODE', 'deterministic'),

        'router_version' => '53.3',

        'auto' => [
            // AI is only useful when there is genuine selection uncertainty.
            'max_baseline_confidence' => (float) env(
                'CANOVIA_INTELLIGENCE_AUTO_MAX_BASELINE_CONFIDENCE',
                0.70,
            ),
            'min_candidates' => (int) env(
                'CANOVIA_INTELLIGENCE_AUTO_MIN_CANDIDATES',
                2,
            ),
        ],

        'openai' => [
            'max_output_tokens' => (int) env(
                'CANOVIA_INTELLIGENCE_OPENAI_MAX_OUTPUT_TOKENS',
                700,
            ),
        ],

        // Pricing is intentionally environment-configured. Do not hard-code
        // provider pricing into product logic because it changes independently.
        'cost' => [
            'input_usd_per_million_tokens' => env(
                'CANOVIA_INTELLIGENCE_INPUT_USD_PER_MILLION_TOKENS',
            ),
            'output_usd_per_million_tokens' => env(
                'CANOVIA_INTELLIGENCE_OUTPUT_USD_PER_MILLION_TOKENS',
            ),
        ],
    ],
];
