<?php

return [
    'tiers' => [
        'free' => [
            'name' => 'Free',
            'tagline' => 'Execute',
            'status' => 'available',
            'summary' => '自分で進めるためのCanoviaの中核を、そのまま使えます。',
            'highlights' => [
                'Goal / Plan / Task / Evidence',
                'Studyの基本演習・採点・記録',
                'Developmentの基本管理・Repository連携',
                '外部AI向け基本Prompt生成',
            ],
        ],
        'premium' => [
            'name' => 'Premium',
            'tagline' => 'Guide',
            'status' => 'coming_soon',
            'summary' => '次に何をするか、どう進めるかの判断をCanoviaがより深く支援します。',
            'highlights' => [
                '弱点・進捗・状況の軽量推論',
                '次の行動・改善候補の提案',
                'Development Rules / lightweight specification',
                'AIへの指示品質を高める支援',
            ],
        ],
        'pro' => [
            'name' => 'Pro',
            'tagline' => 'Understand',
            'status' => 'coming_soon',
            'summary' => '教材やRepositoryの内容まで理解し、必要なContextをCanoviaが組み立てます。',
            'highlights' => [
                '教材 / 試験範囲 / 長期履歴の横断理解',
                'Repository内容・関連Code / Testの検索',
                'Specification / Decision / Evidenceの横断Context',
                '高精度なPrompt / 再計画',
            ],
        ],
        'dev_pro' => [
            'name' => 'Dev Pro',
            'tagline' => 'Observe & Improve',
            'status' => 'coming_soon',
            'summary' => '公開後の実利用を観測し、改善候補の発見から検証までを継続支援します。',
            'highlights' => [
                'Feature Adoption / Funnel / Release Impact',
                '実利用と狙いの差をProduct Intelligence化',
                '改善仮説・Decision Package',
                '将来的なbounded Agent実装 / 検証',
            ],
        ],
    ],

    'experiences' => [
        'study' => [
            'label' => 'Study',
            'description' => '学習結果が次の学習判断へつながるまで。',
            'tiers' => [
                'free' => [
                    '演習する',
                    '採点する',
                    '正誤・学習量を記録する',
                ],
                'premium' => [
                    '演習履歴を見る',
                    '弱点を推定する',
                    '次の演習配分を最適化する',
                ],
                'pro' => [
                    '教材・試験範囲を読む',
                    '長期弱点と照合する',
                    '学習Plan / Contextを再構築する',
                ],
            ],
        ],
        'development' => [
            'label' => 'Development',
            'description' => 'Taskから実装、公開後の改善まで。',
            'tiers' => [
                'free' => [
                    'Task / Repositoryを整理する',
                    '既知Contextで基本Promptを作る',
                    '外部AIへ実装を渡す',
                ],
                'premium' => [
                    'PR / Issueから状況を推定する',
                    'Rules / 方針 / 制約を組み立てる',
                    'より良いPromptへ変換する',
                ],
                'pro' => [
                    'Repository内容を検索する',
                    'Code / Spec / Testを束ねる',
                    'Implementation Contextを生成する',
                ],
                'dev_pro' => [
                    '公開後の利用を観測する',
                    '価値 / 離脱 / Release影響を解釈する',
                    '改善候補を作り次の検証へつなぐ',
                ],
            ],
        ],
    ],

    'disclosures' => [
        'Early Access中はFreeのみ実利用できます。',
        'Premium / Pro / Dev ProはComing Soonです。',
        '価格・正式提供時期・AI利用枠は未定です。',
        'この画面から購入・決済はできません。',
    ],
];
