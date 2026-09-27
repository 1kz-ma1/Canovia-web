<?php

return [
    'version' => 2,
    'categories' => [
        'start' => [
            'label' => 'はじめる',
            'description' => 'Canoviaの基本',
            'icon' => '✦',
        ],
        'daily' => [
            'label' => '今を進める',
            'description' => 'Home・Task・Timer',
            'icon' => '▶',
        ],
        'inbox' => [
            'label' => 'Inbox',
            'description' => '現実の情報をCanoviaへ渡す',
            'icon' => '▣',
        ],
        'study' => [
            'label' => '学習する',
            'description' => 'AI演習・Recall・教材',
            'icon' => '◉',
        ],
        'ai' => [
            'label' => '計画を更新する',
            'description' => '実績や変化をPlanへ戻す',
            'icon' => '✧',
        ],
        'together' => [
            'label' => '共同計画',
            'description' => '招待・参加・一緒に進める',
            'icon' => '◎',
        ],
        'assets' => [
            'label' => '資料・成果物',
            'description' => 'Resourceや制作物を整理する',
            'icon' => '◇',
        ],
    ],
    'guides' => [
        'first_plan' => [
            'category' => 'start',
            'title' => '目標から仮Planを作る',
            'description' => '未来を一言で渡し、現在地を必要な分だけ確認して仮Planへ進みます。',
            'keywords' => ['計画', 'Plan', '作成', '最初', '目標', '現在地', 'Goal Context'],
            'start_path' => '/',
            'steps' => [
                [
                    'path' => '/',
                    'target' => 'create-plan',
                    'title' => '新しい計画から始めます',
                    'copy' => 'ホームの「新しい計画」から、進めたいことを登録します。',
                    'advance' => 'click',
                ],
                [
                    'path' => '/plans/create',
                    'target' => 'plan-form',
                    'title' => '未来を一言だけ渡します',
                    'copy' => '最初は目標だけで大丈夫です。次の画面でCurrent Stateを1問ずつ確認し、途中でも仮Planへ進めます。',
                    'advance' => 'next',
                ],
            ],
        ],
        'home_next_action' => [
            'category' => 'daily',
            'title' => '次にやることを確認する',
            'description' => 'HomeでCurrent TaskとPrimary Actionを確認します。',
            'keywords' => ['ホーム', '次', 'Task', 'おすすめ', 'Current Task', 'Primary Action'],
            'start_path' => '/',
            'steps' => [
                [
                    'path' => '/',
                    'target' => 'home-now',
                    'title' => '今と次はHomeに集約します',
                    'copy' => 'Canoviaが現在Taskと実行方法を1つに絞ります。AI演習やRecallなど適切なToolがあれば、それを優先します。',
                    'advance' => 'next',
                ],
            ],
        ],
        'timer_fallback' => [
            'category' => 'daily',
            'title' => '専用ToolがないTaskをTimerで進める',
            'description' => 'AI演習やRecallなどがないTaskだけ、TimerをFallbackに使います。',
            'keywords' => ['タイマー', 'Timer', 'Fallback', '作業', '時間', '開始'],
            'start_path' => '/',
            'steps' => [
                [
                    'path' => '/',
                    'target' => 'timer-fallback',
                    'title' => 'TimerはFallbackです',
                    'copy' => '専用の実行Toolを特定できないTaskでは、集中タイマーをPrimary Actionとして使えます。',
                    'advance' => 'next',
                ],
            ],
        ],
        'inbox_capture' => [
            'category' => 'inbox',
            'title' => 'Inboxに何か渡す',
            'description' => 'テキスト・URL・画像・PDFを、分類前のままCanoviaへ預けます。',
            'keywords' => ['Inbox', 'スクショ', '画像', 'PDF', 'URL', 'メモ', '取り込み'],
            'start_path' => '/inbox',
            'steps' => [
                [
                    'path' => '/inbox',
                    'target' => 'inbox-capture',
                    'title' => '分類を考えず、まずInboxへ',
                    'copy' => '思いつきや外部情報はここへ入れます。関連Planを決められる場合だけ選べば十分です。',
                    'advance' => 'next',
                ],
            ],
        ],
        'inbox_organize' => [
            'category' => 'inbox',
            'title' => 'Inboxを整理する',
            'description' => '行き先候補を確認し、人が確定して既存機能へ接続します。',
            'keywords' => ['Inbox', '整理', '振り分け', 'Candidate', 'AI', '確認'],
            'start_path' => '/inbox',
            'steps' => [
                [
                    'path' => '/inbox',
                    'target' => 'inbox-route',
                    'title' => '整理先は人が確定します',
                    'copy' => 'AIが候補を出していても、Plan・Task・変換先はここで確認してから確定します。AIを使わず手動でも整理できます。',
                    'advance' => 'next',
                ],
            ],
        ],
        'plan_update' => [
            'category' => 'ai',
            'title' => '状況変化をPlanへ反映する',
            'description' => '実績や発見をAIへ渡し、現在の事実に合わせてPlanを更新します。',
            'keywords' => ['計画更新', 'AI', '実績', '方針', '進捗', '予定変更'],
            'start_path' => '/roadmap',
            'steps' => [
                [
                    'path' => '/roadmap',
                    'target' => 'plan-update',
                    'title' => '選択中のPlanを更新します',
                    'copy' => '作業結果や予定変更があるときだけ、現在のPlanと一緒にAIへ相談できます。',
                    'advance' => 'click',
                ],
                [
                    'target' => 'plan-update-input',
                    'title' => '変わったことだけ入力します',
                    'copy' => '最初の計画を守るためではなく、今回の実績・発見・状況変化を事実として渡します。',
                    'advance' => 'next',
                ],
            ],
        ],
        'study_practice' => [
            'category' => 'study',
            'title' => 'AI演習で理解を確認する',
            'description' => '問題演習が適切な学習Taskで、理解確認・弱点補強を行います。',
            'keywords' => ['AI演習', '問題', '資格', '学習', 'Question Bank'],
            'start_path' => '/roadmap',
            'steps' => [
                [
                    'path' => '/roadmap',
                    'target' => 'plan-detail',
                    'title' => '対象の学習Planを開きます',
                    'copy' => 'AI演習はTask単位なので、対象Planの詳細へ進みます。',
                    'advance' => 'click',
                ],
                [
                    'target' => 'study-practice',
                    'title' => 'AI演習を開きます',
                    'copy' => 'Question Practiceが適切なTaskだけAI演習がPrimaryになります。',
                    'advance' => 'click',
                ],
                [
                    'target' => 'practice-strategy',
                    'title' => '今回の出題方針を確認できます',
                    'copy' => '学習履歴とTask状態から、初回確認・弱点補強・定着確認など今回の方針を選びます。',
                    'advance' => 'next',
                ],
            ],
        ],
        'recall' => [
            'category' => 'study',
            'title' => 'Recallで覚える',
            'description' => '単語・用語など、想起が適切なTaskをRecallで進めます。',
            'keywords' => ['Recall', '暗記', '単語', '語彙', 'フラッシュカード', '想起'],
            'start_path' => '/roadmap',
            'steps' => [
                [
                    'path' => '/roadmap',
                    'target' => 'plan-detail',
                    'title' => '対象の学習Planを開きます',
                    'copy' => 'RecallはTask内容から学習方法として選ばれます。',
                    'advance' => 'click',
                ],
                [
                    'target' => 'study-recall',
                    'title' => 'Recallを開始します',
                    'copy' => '答えを隠して思い出し、Again / Hard / Good / Easyで次回確認間隔を調整します。',
                    'advance' => 'click',
                ],
            ],
        ],
        'recall_material' => [
            'category' => 'study',
            'title' => '教材からRecallカード候補を作る',
            'description' => '参考書写真・スクショ・PDFをCandidateへ変換し、確認後にDeckへ追加します。',
            'keywords' => ['Recall', '教材', '参考書', 'スクショ', 'PDF', 'Candidate'],
            'start_path' => '/inbox',
            'steps' => [
                [
                    'path' => '/inbox',
                    'target' => 'inbox-capture',
                    'title' => '教材はInboxから渡せます',
                    'copy' => '参考書写真やPDFをInboxへ入れ、Recall教材を整理先として選べます。',
                    'advance' => 'next',
                ],
            ],
        ],
        'resources' => [
            'category' => 'assets',
            'title' => '関連資料をPlanにまとめる',
            'description' => 'Drive・OneDrive・GitHub等の資料URLをPlanとTaskへ紐づけます。',
            'keywords' => ['資料', 'Resource', 'URL', 'Drive', 'GitHub', 'Task'],
            'start_path' => '/roadmap',
            'steps' => [
                [
                    'path' => '/roadmap',
                    'target' => 'plan-resources',
                    'title' => '選択中のPlanの関連資料を開きます',
                    'copy' => 'Plan単位で参考資料をまとめ、必要なTaskへ紐づけられます。',
                    'advance' => 'click',
                ],
                [
                    'target' => 'resource-add',
                    'title' => '資料を追加します',
                    'copy' => '共有URLを登録します。Inboxの対応URLから整理することもできます。',
                    'advance' => 'next',
                ],
            ],
        ],
        'collaboration_create' => [
            'category' => 'together',
            'title' => '共同計画を作って招待する',
            'description' => '自分のPlanを共同計画にして、URLや参加コードで招待します。',
            'keywords' => ['共同計画', '招待', '共有', 'メンバー', 'URL', '参加コード'],
            'start_path' => '/roadmap',
            'requires_auth' => true,
            'steps' => [
                [
                    'path' => '/roadmap',
                    'target' => 'collaboration-settings',
                    'title' => '共同計画の設定を開きます',
                    'copy' => '選択中のPlanを共同計画にしたり、既存の共同計画を管理できます。',
                    'advance' => 'click',
                ],
                [
                    'target' => 'collaboration-primary',
                    'title' => '共同計画を有効にして招待します',
                    'copy' => '初回は共同計画を有効にします。有効化済みなら、共有URLや参加コードを確認できます。',
                    'advance' => 'next',
                ],
            ],
        ],
        'collaboration_join' => [
            'category' => 'together',
            'title' => '共同計画に参加する',
            'description' => 'もらった参加コードから共同Planへ参加します。',
            'keywords' => ['共同計画', '参加', 'コード', '招待', 'Join'],
            'start_path' => '/my-plans',
            'requires_auth' => true,
            'steps' => [
                [
                    'path' => '/my-plans',
                    'target' => 'collaboration-join',
                    'title' => '共同計画への参加画面を開きます',
                    'copy' => '招待された側は「共同計画に参加」から進みます。',
                    'advance' => 'click',
                ],
                [
                    'target' => 'collaboration-code',
                    'title' => '参加コードを入力します',
                    'copy' => '参加コードを入力します。共有URLを受け取った場合は、そのURLから直接参加できます。',
                    'advance' => 'next',
                ],
            ],
        ],
    ],
];
