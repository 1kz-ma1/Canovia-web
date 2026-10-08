<?php

return [
    // EXPERIMENTAL. Deterministic Question Bank lookahead for the first
    // Understanding/Practice pilot, NOT a final adaptive queue optimum.
    'adaptive_learning' => [
        'locked_queue_size' => 2,
    ],

    'question_bank_selection' => [
        'exposure_history_session_limit' => 24,
        'recent_session_window' => 3,
    ],

    'practice_routing' => [
        'history_attempt_limit' => 12,

        'mastery' => [
            'remediation_correct_streak' => 2,
            'mastery_correct_count' => 3,
            'cooldown_sets' => 2,
            'mastered_cooldown_sets' => 4,
            'perfect_score_percent' => 100,
        ],

        'parent_exposure' => [
            'question_window' => 10,
            'high_exposure_threshold' => 0.40,
            'high_confidence_threshold' => 0.75,
            'broad_max_questions' => 2,
            'high_exposure_max_questions' => 1,
            'unclassified_max_questions' => 3,
        ],

        'broad_assessment' => [
            'weakness_recheck_questions' => 2,
            'retention_questions' => 2,
            'minimum_exploration_questions' => 6,
        ],

        'focused_remediation' => [
            'primary_ratio' => 0.60,
            'secondary_ratio' => 0.20,
            'diagnostic_ratio' => 0.20,
        ],

        'task_intent' => [
            'broad_terms' => [
                '分野横断',
                '横断問題',
                '弱点探索',
                '全範囲',
                '模試',
                '模擬',
                '本番演習',
            ],
            'focused_terms' => [
                '弱点補強',
                '弱点補完',
                '初期弱点',
                '重点補強',
                '集中補強',
                '集中復習',
                '克服',
                '復習',
            ],
        ],

        'parent_topic_aliases' => [
            'データベース' => [
                'データベース', 'database', 'sql', 'group by', 'having', 'join',
                'outer join', '副問合せ', 'サブクエリ', 'トランザクション',
                '正規化', 'nosql', 'base', 'インデックス',
            ],
            'ネットワーク' => [
                'ネットワーク', 'network', 'tcp', 'udp', 'ip', 'dns', 'dhcp',
                'http', 'https', 'smtp', 'nat', 'napt', 'vlan', 'mtu', 'mss',
                'ルーティング', 'crc',
            ],
            'セキュリティ' => [
                'セキュリティ', 'security', '暗号', '認証', 'oauth', 'saml',
                'xss', 'csrf', 'sql injection', '脆弱性', 'マルウェア',
                'ファイアウォール', '電子署名', '証明書',
            ],
            'アルゴリズム' => [
                'アルゴリズム', 'algorithm', '二分探索', '線形探索', '探索',
                'ソート', '整列', '木', 'グラフ', '再帰', '計算量',
            ],
            'OS' => [
                'os', 'オペレーティングシステム', 'スケジューリング',
                'プロセス', 'スレッド', 'デッドロック', '排他制御',
                'メモリ管理', '仮想記憶', 'ページング', 'リアルタイムos',
            ],
            'コンピュータ構成' => [
                'コンピュータ構成', 'cpu', 'プロセッサ', 'vliw', 'キャッシュ',
                '主記憶', 'メモリ', 'gpu', '命令', 'アーキテクチャ',
            ],
            '性能・可用性' => [
                '性能', '可用性', '信頼性', 'mtbf', 'mttr', '稼働率', 'mips',
                'flops', 'hpc', 'キャパシティ', '応答時間', 'スループット',
            ],
            '開発' => [
                '開発', 'ソフトウェア', 'プログラミング', 'テスト', 'uml',
                'オブジェクト指向', 'デザインパターン', 'アジャイル',
                'devops', 'git', 'api',
            ],
            'プロジェクトマネジメント' => [
                'プロジェクト', 'project management', 'pmbok', 'wbs', 'evm',
                'クリティカルパス', '見積り', 'リスク管理',
            ],
            'サービスマネジメント' => [
                'サービスマネジメント', 'itil', 'インシデント', '問題管理',
                'sla', 'サービスレベル', '変更管理',
            ],
            'システム監査' => [
                'システム監査', '監査', 'audit', 'フォローアップ',
                'コントロール',
            ],
            'ストラテジ' => [
                'ストラテジ', '経営戦略', '事業戦略', 'システム戦略',
                'マーケティング', 'bsc', '4p', '4c', '投資評価',
                'フィージビリティ', 'soa',
            ],
            '経営・法務' => [
                '法務', '契約', '知的財産', '著作権', '個人情報',
                '労働', '会計', '財務', '企業活動', '生産管理', 'mes', 'plm',
            ],
            'AI・データ活用' => [
                'ai', '機械学習', 'roc', 'データ分析', 'アソシエーション',
                'データサイエンス',
            ],
        ],
    ],

    'exam_convergence' => [
        'history_attempt_limit' => 16,

        'graduation' => [
            'minimum_targeted_sessions' => 2,
            'minimum_targeted_question_budget' => 8,
            'minimum_score_percent' => 80,
        ],

        'reinforcement' => [
            'maximum_sessions_per_cycle' => 3,
            'maximum_question_budget_per_cycle' => 20,
        ],

        'reentry' => [
            'general_exam_window_attempts' => 3,
            'required_failure_attempts' => 2,
        ],

        'deadline' => [
            'general_practice_days' => 30,
            'exam_mode_days' => 14,
        ],

        'practice' => [
            'normal_question_count' => 10,
        ],
    ],
];
