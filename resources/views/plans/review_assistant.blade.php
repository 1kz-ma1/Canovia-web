@extends('layouts.app')

@section('title', '計画更新 | ' . $plan->title)

@section('content')
    @php
        $operationLabels = [
            'update_plan' => '計画更新',
            'update_availability' => '作業可能時間更新',
            'create_work_log' => '作業ログ追加',
            'create_task' => 'タスク追加',
            'update_task' => 'タスク更新',
            'keep_task' => 'タスク維持',
            'cancel_task' => 'タスク中止',
            'reorder_tasks' => '後続タスク再編',
        ];
    @endphp

    <div
        id="plan-review-root"
        class="mx-auto max-w-6xl space-y-6"
        data-funnel-root
        data-event-url="{{ route('behavior_events.store') }}"
        data-plan-id="{{ $plan->id }}"
    >
        <header class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                                <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900 font-heading">計画を更新</h1>
                <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-600">
                    実績、分かったこと、予定との違い、方針変更をまとめて報告できます。何を変更するべきかは外部AI側で判断し、反映前に差分を確認します。
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <a href="{{ route('plans.show', $plan) }}" class="btn-secondary">計画詳細へ戻る</a>

                @if ($draft || $proposal)
                    <form method="POST" action="{{ route('plans.review_assistant.reset', $plan) }}" data-async-plan-review data-loading-skip data-reveal-target="#review-input-section">
                        @csrf
                        @if ($workSessionContext)
                            <input type="hidden" name="work_session_id" value="{{ $workSessionContext->id }}">
                        @endif
                        <button type="submit" class="btn-secondary" data-async-plan-review-submit>最初からやり直す</button>
                        <div class="hidden rounded-xl border px-3 py-2 text-sm leading-6" data-async-plan-review-status aria-live="polite"></div>
                    </form>
                @endif
            </div>
        </header>

        @if (session('status'))
            <div class="assistant-notice assistant-notice-success">
                {{ session('status') }}
            </div>
        @endif

        @php
            $pageErrors = collect($errors->getBag('default')->getMessages())
                ->except(['operations_json'])
                ->flatten();
        @endphp
        @if ($pageErrors->isNotEmpty())
            <div id="review-errors" class="assistant-notice assistant-notice-error">
                <p class="font-bold">入力内容を確認してください。</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    @foreach ($pageErrors as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="page-card border-cyan-300/15 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black tracking-[.14em] text-cyan-300">PERSONALIZED CONTEXT</p>
                    <h2 class="mt-1 text-sm font-black text-slate-100">Canovia Memoryも計画更新の参考にします</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-400">会話などで本人が明示したMemory {{ ($futureMemos ?? collect())->count() }}件を、関係する範囲だけプロンプトへ含めます。</p>
                </div>
            </div>
        </section>

        <section class="assistant-context-grid">
            <div class="metric-card">
                <p class="text-xs text-slate-500">対象計画</p>
                <p class="mt-1 font-bold text-slate-900">{{ $plan->title }}</p>
            </div>
            <div class="metric-card">
                <p class="text-xs text-slate-500">現在進捗</p>
                <p class="mt-1 text-xl font-bold text-slate-900">{{ $progress['weighted_progress_percent'] }}%</p>
            </div>
            <div class="metric-card">
                <p class="text-xs text-slate-500">{{ ($progress['availability_configured'] ?? false) ? '今日の作業目安' : '1日必要時間' }}</p>
                <p class="mt-1 text-xl font-bold text-slate-900">{{ $progress['remaining_days'] === null ? '—' : $progress['daily_required_minutes'] . '分' }}</p>
                @if (($progress['availability_configured'] ?? false) && $progress['today_available_minutes'] !== null)
                    <p class="mt-1 text-xs text-slate-500">作業可能 {{ $progress['today_available_minutes'] }}分</p>
                @endif
            </div>
            <div class="metric-card">
                <p class="text-xs text-slate-500">残り作業時間</p>
                <p class="mt-1 text-xl font-bold text-slate-900">{{ $progress['remaining_minutes'] }}分</p>
            </div>
            <div class="metric-card">
                <p class="text-xs text-slate-500">計画状態</p>
                <p class="mt-1 font-bold text-slate-900">{{ $progress['status'] }}</p>
            </div>
        </section>

        <main class="assistant-chat-shell">
            <div class="assistant-message-row assistant-message-left">
                <div class="assistant-avatar">CV</div>
                <div class="assistant-bubble assistant-bubble-support">
                    <p class="assistant-speaker">Canovia サポーター</p>
                    @if ($workSessionContext)
                        <p class="mt-2 leading-7">
                            今回の作業時間など、Canoviaで確定できる事実はすでに記録しました。ここから先は、普段使っているAIとの会話で「何が進んだか」を整理して計画へ反映できます。
                        </p>
                        <div class="assistant-notice assistant-notice-info mt-4">
                            <p class="font-semibold">今回記録済みの事実</p>
                            <p class="mt-2 text-sm leading-6">
                                {{ $workSessionContext->task?->title ?? '計画全体の作業' }} ・
                                {{ $workSessionLog?->actual_minutes ?? max(1, (int) ceil(($workSessionContext->actual_seconds ?? 0) / 60)) }}分
                                @if (($workSessionContext->paused_seconds ?? 0) > 0)
                                    ・一時停止 {{ (int) floor($workSessionContext->paused_seconds / 60) }}分
                                @endif
                            </p>
                            <p class="mt-2 text-xs leading-5 text-slate-500">同じ作業時間をAI JSONから重複登録しないよう、プロンプト側で明示します。</p>
                        </div>
                    @else
                        <p class="mt-2 leading-7">
                            Canoviaが現在の計画・タスク・最近の実績をまとめます。今回の状況は、普段使っているAIとの会話から確認してもらえます。
                        </p>
                        <div class="assistant-notice assistant-notice-info mt-4">
                            入力は必須ではありません。空欄なら、AIが現在の会話を使い、必要な場合だけ「今回何がありましたか？」と聞くよう指示します。
                        </div>
                    @endif
                </div>
            </div>

            <div id="review-input-section" class="assistant-message-row assistant-message-right" data-guide-target="plan-update-input">
                <div class="assistant-bubble assistant-bubble-user assistant-form-bubble">
                    <p class="assistant-speaker">あなた</p>

                    <form method="POST" action="{{ route('plans.review_assistant.prompt', $plan) }}" class="mt-4 space-y-4" data-async-plan-review data-loading-skip data-reveal-target="#review-prompt-section">
                        @csrf
                        <input type="hidden" name="flow" value="result_recording">
                        @if ($workSessionContext)
                            <input type="hidden" name="work_session_id" value="{{ $workSessionContext->id }}">
                        @endif

                        <div>
                            <label for="activity_summary" class="mb-2 block text-sm font-semibold">先に伝えておきたいこと（任意）</label>
                            <textarea
                                id="activity_summary"
                                name="activity_summary"
                                rows="4"
                                class="form-control"
                                placeholder="{{ $workSessionContext ? '例：さっき話していたAPの50問の続き。弱点補強まで進めた。' : '例：この前話していた50問演習の内容を計画に反映したい。' }}"
                            >{{ old('activity_summary', $draft['activity_summary'] ?? '') }}</textarea>
                            <p class="mt-2 text-xs leading-5 text-slate-400">
                                空欄でも生成できます。日付・作業時間・関連Taskなどをここで分類する必要はありません。
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <button type="submit" class="btn-primary" data-async-plan-review-submit>
                                AI用プロンプトを生成
                            </button>
                            <div class="hidden w-full rounded-xl border px-3 py-2 text-sm leading-6" data-async-plan-review-status aria-live="polite"></div>
                            @if ($workSessionContext)
                                <a href="{{ route('home') }}" class="btn-secondary">今は戻る（作業記録は保存済み）</a>
                            @endif
                        </div>
                    </form>
                </div>
                <div class="assistant-avatar assistant-avatar-user">YOU</div>
            </div>

            @if ($draft && ! empty($draft['prompt']))
                <div id="review-prompt-section" class="assistant-message-row assistant-message-left">
                    <div class="assistant-avatar">CV</div>
                    <div class="assistant-bubble assistant-bubble-support assistant-wide-bubble">
                        <p class="assistant-speaker">Canovia サポーター</p>
                        <p class="mt-2 leading-7">
                            現在の計画、タスク、最近の実績@if ($workSessionContext) と今回の作業記録@endif をまとめました。次の内容を普段使っているAIへ送ってください。
                        </p>

                        <textarea id="reviewPrompt" readonly tabindex="-1" aria-hidden="true" class="sr-only">{{ $draft['prompt'] }}</textarea>

                        <div class="mt-4 rounded-2xl border border-cyan-300/15 bg-cyan-300/[0.035] p-4">
                            <p class="text-sm font-semibold text-slate-100">AIへ渡す更新コンテキストはCanovia側で準備済みです</p>
                            <p class="mt-1 text-xs leading-5 text-slate-400">原文を読む必要はありません。普段使っているAIへそのまま送ってください。</p>
                            <button type="button" class="btn-primary mt-4" data-copy-target="#reviewPrompt" data-funnel-event="plan_update_prompt_copy_clicked">プロンプトをコピー</button>

                            <details class="ai-handoff-details mt-4 rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                                <summary class="cursor-pointer text-xs font-semibold text-slate-300">このプロンプトに含まれる情報</summary>
                                <ul class="mt-3 space-y-2 text-xs leading-5 text-slate-500">
                                    <li>・現在のPlan、Task、進捗、期限、作業可能時間</li>
                                    <li>・最近の作業実績と、今回入力した補足内容</li>
                                    <li>・未来メモなどAI共有対象の本人コンテキスト</li>
                                    <li>・更新可能な操作、正しいPlan / Task ID</li>
                                    <li>・JSON形式、進捗・作業時間の重複登録を防ぐ安全条件</li>
                                </ul>
                            </details>
                        </div>
                    </div>
                </div>

                <div class="assistant-message-row assistant-message-right">
                    <div class="assistant-bubble assistant-bubble-user assistant-form-bubble">
                        <p class="assistant-speaker">あなた</p>
                        <p class="mt-2 text-sm leading-6">
                            外部AI側で最終JSONをコピーしたら、Canoviaでは1回押すだけで貼り付けと確認へ進めます。
                        </p>

                        <form method="POST" action="{{ route('plans.review_assistant.preview', $plan) }}" class="mt-4 space-y-4" data-review-json-preview>
                            @csrf

                            <button
                                type="button"
                                class="btn-primary w-full md:w-auto"
                                data-paste-target="#review_operations_json"
                                data-paste-submit="1"
                                data-paste-fallback="#reviewOperationsJsonManual"
                                data-paste-status="#reviewOperationsJsonPasteStatus"
                            >クリップボードから貼り付けて確認</button>
                            <p id="reviewOperationsJsonPasteStatus" class="hidden text-xs leading-5 text-slate-400" aria-live="polite"></p>

                            <details id="reviewOperationsJsonManual" class="ai-handoff-details rounded-xl border border-slate-800 bg-slate-950/35 p-3" @if($errors->has('operations_json') || old('operations_json')) open @endif>
                                <summary class="cursor-pointer text-xs font-semibold text-slate-300">手動で貼り付ける / JSONを確認する</summary>
                                <textarea
                                    id="review_operations_json"
                                    name="operations_json"
                                    rows="10"
                                    class="form-control mt-3 font-mono text-xs"
                                    required
                                    placeholder='{"schema_version":"2.0","flow":"result_recording","target_plan":{"id":{{ $plan->id }},"title":"計画名","category":"カテゴリ"},"summary":"AIが判断した更新内容","operations":[]}'
                                >{{ old('operations_json') }}</textarea>
                                <button type="submit" class="btn-secondary mt-3 w-full md:w-auto" data-async-plan-review-submit>
                                    このJSONを読み込んで確認
                                </button>
                            </details>

                            <div class="hidden rounded-xl border px-3 py-2 text-sm leading-6" data-async-plan-review-status aria-live="polite"></div>
                        </form>
                    </div>
                    <div class="assistant-avatar assistant-avatar-user">YOU</div>
                </div>

                @if ($errors->has('operations_json'))
                    @php
                        $jsonErrorMessage = $errors->first('operations_json');
                        $jsonErrorKind = str_contains($jsonErrorMessage, '構文')
                            || str_contains($jsonErrorMessage, '閉じ括弧')
                            || str_contains($jsonErrorMessage, '引用符')
                            ? 'syntax'
                            : 'contract';
                        $jsonRepairPrompt = implode("\n", [
                            'Canoviaに貼り付けたJSONでエラーが発生しました。',
                            '下にある「元のCanoviaプロンプト」を仕様・計画ID・タスクIDの唯一の正として扱ってください。',
                            'エラー内容を満たすために必要な箇所だけ修正し、正しいID・数値・進捗情報・操作は可能な限り保持してください。',
                            '存在しないtask_idを推測で置き換えないでください。元のCanoviaプロンプトに記載されたIDだけを使用してください。',
                            'flow、target_plan、操作type、必須項目は元のCanoviaプロンプトの操作仕様に従ってください。',
                            '修正後はJSONとして構文解析できることに加え、エラーで指摘されたCanovia側の条件を満たしているか確認してください。',
                            '説明文・Markdown・コードフェンスを付けず、有効なJSONだけを最後の回答として返してください。',
                            '',
                            '【Canoviaのエラー】',
                            $jsonErrorMessage,
                            '',
                            '【エラーになったJSON】',
                            old('operations_json', ''),
                            '',
                            '【元のCanoviaプロンプト（正しい仕様・ID・現在値）】',
                            $draft['prompt'] ?? '元のプロンプトを取得できませんでした。',
                        ]);
                    @endphp
                    <div class="assistant-message-row assistant-message-left">
                        <div class="assistant-avatar">CV</div>
                        <div class="assistant-bubble assistant-bubble-support assistant-wide-bubble">
                            <p class="assistant-speaker">Canovia サポーター</p>
                            <h3 class="mt-2 text-lg font-bold text-slate-100">
                                {{ $jsonErrorKind === 'syntax' ? 'JSONの構文を読み込めませんでした' : 'JSONは読めましたが、Canoviaの条件に合わない箇所があります' }}
                            </h3>
                            <p class="mt-2 text-sm leading-6 text-slate-300">
                                入力内容は残しています。修正依頼には、今回のエラーだけでなく元のCanoviaプロンプトと正しい計画・タスクIDも含めます。
                                コピーして、JSONを作ったAIへそのまま送ってください。
                            </p>
                            <div class="assistant-notice assistant-notice-error mt-4">
                                <p class="font-bold">Canoviaが検出した内容</p>
                                <p class="mt-1 text-sm leading-6">{{ $jsonErrorMessage }}</p>
                            </div>
                            <textarea id="reviewJsonRepairPrompt" readonly tabindex="-1" aria-hidden="true" class="sr-only">{{ $jsonRepairPrompt }}</textarea>
                            <button type="button" class="btn-primary mt-3" data-copy-target="#reviewJsonRepairPrompt">修正依頼をコピー</button>
                            <details class="ai-handoff-details mt-3 rounded-xl border border-rose-300/10 bg-slate-950/25 p-3">
                                <summary class="cursor-pointer text-xs font-semibold text-rose-100/80">修正依頼に含まれる情報</summary>
                                <p class="mt-2 text-xs leading-5 text-slate-500">Canoviaが検出したエラー、貼り付けたJSON、元の更新Prompt、正しいPlan / Task IDと現在値を含みます。</p>
                            </details>
                        </div>
                    </div>
                @endif
            @endif

            @if ($proposal)
                @php
                    $proposalAnalysis = $proposal['analysis'] ?? [];
                    $proposalCanApply = $proposal['can_apply'] ?? true;
                    $proposalAtomic = $proposalAnalysis['atomic_apply'] ?? false;
                @endphp
                <div id="review-proposal-section" class="assistant-message-row assistant-message-left">
                    <div class="assistant-avatar">CV</div>
                    <div class="assistant-bubble assistant-bubble-support assistant-wide-bubble">
                        <p class="assistant-speaker">Canovia サポーター</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <h2 class="text-xl font-bold text-slate-900">反映前の変更プレビュー</h2>
                            <span class="badge badge-slate">{{ $proposal['action_label'] ?? 'AI JSON操作' }}</span>
                        </div>
                        <p class="mt-2 leading-7 text-slate-600">{{ $proposal['summary'] }}</p>

                        @foreach (($proposalAnalysis['normalization_notes'] ?? []) as $note)
                            <div class="assistant-notice assistant-notice-info mt-4">
                                <span class="font-bold">自動調整：</span>{{ $note }}
                            </div>
                        @endforeach

                        @foreach (($proposalAnalysis['warnings'] ?? []) as $warning)
                            <div class="assistant-notice assistant-notice-warning mt-4">{{ $warning }}</div>
                        @endforeach

                        @if (! $proposalCanApply)
                            <div class="assistant-notice assistant-notice-error mt-4">
                                <p class="font-bold">このJSONはまだ反映できません。</p>
                                <p class="mt-1 text-sm leading-6">不足している項目だけを、対象ごとに表示しています。</p>

                                @if (! empty($proposalAnalysis['blocking_issue_groups']))
                                    <div class="mt-3 space-y-3">
                                        @foreach ($proposalAnalysis['blocking_issue_groups'] as $group)
                                            <div class="rounded-xl bg-white/70 p-3 ring-1 ring-red-200">
                                                <p class="font-bold text-red-900">{{ $group['title'] }}</p>
                                                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-800">
                                                    @foreach (($group['items'] ?? []) as $issue)
                                                        <li>{{ $issue }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                                        @foreach (($proposalAnalysis['blocking_issues'] ?? []) as $issue)
                                            <li>{{ $issue }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @elseif ($proposalAtomic)
                            <div class="assistant-notice assistant-notice-info mt-4">
                                計画再編は一部だけ反映すると整合性が崩れるため、全操作を一括反映します。
                            </div>
                        @else
                            <div class="assistant-notice assistant-notice-warning mt-4">
                                チェックした項目だけ反映します。「タスク中止」は削除ではなく履歴を残したまま進捗計算から除外します。
                            </div>
                        @endif

                        @if ($proposalCanApply)
                            <div class="sticky top-3 z-40 mt-5 rounded-2xl border border-cyan-300/20 bg-slate-950/90 p-3 shadow-[0_14px_40px_rgba(2,6,23,.42)] backdrop-blur-xl">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold text-slate-50">この内容で計画を更新しますか？</p>
                                        <p class="mt-1 text-xs text-slate-400">詳細を確認したら、そのまま確定できます。</p>
                                    </div>
                                    <button type="submit" form="review-apply-form" class="btn-primary shrink-0">この内容で確定して更新</button>
                                </div>
                            </div>
                        @endif

                        <details class="mt-5 rounded-2xl border border-slate-700 bg-slate-950/55 p-4">
                            <summary class="cursor-pointer font-bold text-slate-200">変更前のロードマップを見る</summary>
                            <div class="mt-4 opacity-80">
                                @include('plans.partials.roadmap', [
                                    'roadmap' => $proposal['roadmap_before'] ?? ['nodes' => []],
                                    'roadmapPlan' => $plan,
                                    'roadmapCanEdit' => false,
                                    'roadmapMode' => 'preview',
                                ])
                            </div>
                        </details>

                        <div class="mt-5 rounded-2xl border border-emerald-300/20 bg-slate-950/80 p-4 sm:p-5">
                            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-emerald-300">反映後のロードマップ</p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-50">反映後の道筋</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-400">マップで変更後の流れを確認できます。必要ならリストにも切り替えられます。</p>
                                </div>
                            </div>
                            @include('plans.partials.roadmap', [
                                'roadmap' => $proposal['roadmap_preview'] ?? ['nodes' => []],
                                'roadmapPlan' => $plan,
                                'roadmapCanEdit' => false,
                                'roadmapMode' => 'preview',
                            ])
                        </div>

                        <form id="review-apply-form" method="POST" action="{{ route('plans.review_assistant.apply', $plan) }}" class="mt-5 space-y-4">
                            @csrf
                            <input type="hidden" name="proposal_token" value="{{ $proposal['token'] }}">

                            @if ($proposalAtomic)
                                @foreach ($proposal['operations'] as $index => $operation)
                                    <input type="hidden" name="selected_operations[]" value="{{ $index }}">
                                @endforeach
                            @else
                                <details class="rounded-2xl border border-slate-300 bg-white/70 p-4">
                                    <summary class="cursor-pointer font-bold text-slate-900">変更の詳細・反映項目を選ぶ</summary>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">ロードマップに直接出ない作業ログや作業可能時間もここで確認できます。</p>
                                    <div class="mt-4 space-y-3">
                                        @foreach ($proposal['operations'] as $index => $operation)
                                            @php $isDanger = $operation['type'] === 'cancel_task'; @endphp
                                            <label class="assistant-operation-card {{ $isDanger ? 'assistant-operation-danger' : '' }}">
                                                <input type="checkbox" name="selected_operations[]" value="{{ $index }}" checked class="mt-1">
                                                <span class="min-w-0 flex-1">
                                                    <span class="flex flex-wrap items-center gap-2">
                                                        <span class="badge {{ $isDanger ? 'badge-red' : 'badge-slate' }}">{{ $operationLabels[$operation['type']] ?? $operation['type'] }}</span>
                                                        <span class="font-bold text-slate-900">{{ $operation['display'] }}</span>
                                                    </span>
                                                    @if (! empty($operation['reason']))<span class="mt-2 block text-sm leading-6 text-slate-600">理由：{{ $operation['reason'] }}</span>@endif
                                                    @if (! empty($operation['next_action_note']))<span class="mt-1 block text-sm leading-6 text-sky-700">次回ここから：{{ $operation['next_action_note'] }}</span>@endif
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                </details>
                            @endif

                            @if ($proposalCanApply)
                                <div class="flex flex-wrap gap-3 pt-2">
                                    <button type="submit" class="btn-primary">
                                        {{ $proposalAtomic ? 'この内容で確定して一括更新' : 'この内容で確定して更新' }}
                                    </button>
                                </div>
                            @endif
                        </form>
                    </div>
                </div>
            @endif
        </main>
    </div>

    {{-- Clipboard copy/paste is handled centrally in resources/js/app.js. --}}

@endsection
