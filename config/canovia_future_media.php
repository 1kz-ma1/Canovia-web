<?php

// Explicit artwork presentation only. Never joins public copy to Support votes.
// No title matching, record creation or release/publication state is derived here.
return [
    'public_roadmap' => [
        'first-use-choices' => ['file' => 'first-use-choices.webp', 'fit' => 'contain',
            'alt' => '本や光を描いた、自分の関心を探す構想イラスト'],
        'progress-and-records' => ['file' => 'shared-context.webp',
            'alt' => '計画・学習・開発の記録をつなぐ文脈を描いた構想イラスト'],
        'external-activity-sync' => ['file' => 'github-integration.webp',
            'alt' => '外部の開発活動とCanoviaの連携を描いた将来の構想イラスト'],
    ],
    // No reviewed artwork mapping exists for current social/Support records.
    'support' => [],
];
