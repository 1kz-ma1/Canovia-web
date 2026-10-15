<?php

/**
 * Manually curated, public-only Canovia development roadmap.
 *
 * Edit these entries through a reviewed PR when an owner wants to publish an
 * update. Do NOT import GitHub commits, private plans, CI, or deployment status.
 * This file is not a feature flag, a product release level, or an entitlement.
 *
 * Released entries require explicit owner sign-off plus a real deployed/client
 * acceptance date and an audience scope. See the operator runbook.
 */
return [
    'items' => [
        [
            'key' => 'progress-and-records',
            'title' => '活動の記録と現在地の見える化',
            'description' => '計画・タスク・実績をつなぎ、取り組みの現在地や次の行動を理解しやすくする体験を改善しています。',
            'area' => '全般',
            'status' => 'in_development',
            'updated_on' => '2026-10-15',
            'is_published' => true,
        ],
        [
            'key' => 'first-use-choices',
            'title' => '自分に合った始め方',
            'description' => '「診断する」「Canoviaに相談する」「まず見てみる」の初回導線を検証しています。一般提供はまだ確認していません。',
            'area' => '全般',
            'status' => 'ready',
            'updated_on' => '2026-10-15',
            'is_published' => true,
        ],
        [
            'key' => 'external-activity-sync',
            'title' => '外部アプリの作業記録との連携',
            'description' => '将来的に外部サービスでの作業をCanoviaの記録や進捗とつなぐ構想です。初回公開では自動連携を提供しません。',
            'area' => '開発',
            'status' => 'planned',
            'updated_on' => '2026-10-15',
            'is_published' => true,
        ],
        // Do not add 'released' entries solely because source code was merged.
        // A released item also needs owner_release_verified=true,
        // release_verified_on (YYYY-MM-DD) and access_scope=public|limited.
    ],
];
