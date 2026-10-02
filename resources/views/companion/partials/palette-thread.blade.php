@php
    $candidateLabels = [
        'create_task' => 'Task追加候補',
        'update_task' => 'Task変更候補',
        'update_plan' => 'Plan変更候補',
        'record_goal_fact' => 'Goal Context更新候補',
        'create_future_memo' => 'Memory保存候補',
        'create_inbox_item' => 'Inbox候補',
        'prepare_execution_request' => '実行リクエスト候補',
    ];
    $candidateStatusLabels = [
        'pending' => '確認待ち',
        'applied' => '反映済み',
        'dismissed' => '見送り',
    ];
    $fieldLabels = [
        'title' => 'タイトル',
        'description' => '説明',
        'estimated_minutes' => '見積時間',
        'remaining_minutes' => '残り時間',
        'priority' => '優先度',
        'activation_cost' => '着手コスト',
        'next_action_note' => '次のAction',
        'status' => '状態',
        'category' => 'カテゴリ',
        'priority_mode' => '優先度モード',
        'start_date' => '開始日',
        'deadline' => '期限',
        'type' => 'Fact種別',
        'key' => 'Fact Key',
        'label' => 'ラベル',
        'value' => '内容',
        'measurement' => 'Measurement',
        'importance' => '重要度',
        'kind' => '種類',
        'content' => '本文',
        'use_for_ai' => 'AI Contextで使う',
        'instruction' => '実行したいこと',
        'actor_type' => '実行主体',
        'available_minutes' => '今回使える時間',
    ];

    $palettePlan = $thread->plan;
    $paletteTask = $thread->task;
    $paletteMessages = $thread->messages->slice(-12);

    $paletteShortcuts = collect();
    if ($paletteTask && $palettePlan) {
        $paletteShortcuts->push([
            'label' => '実行を開く',
            'url' => route('navigation.index', ['plan_id' => $palettePlan->id]),
        ]);
        $paletteShortcuts->push([
            'label' => 'Taskを編集',
            'url' => route('tasks.edit', $paletteTask),
        ]);
        $paletteShortcuts->push([
            'label' => 'Plan Dashboard',
            'url' => route('plans.dashboard', $palettePlan),
        ]);
    } elseif ($palettePlan) {
        $paletteShortcuts->push([
            'label' => '実行を開く',
            'url' => route('navigation.index', ['plan_id' => $palettePlan->id]),
        ]);
        $paletteShortcuts->push([
            'label' => 'Plan Dashboard',
            'url' => route('plans.dashboard', $palettePlan),
        ]);
    } else {
        $paletteShortcuts = collect([
            ['label' => 'ホーム', 'url' => route('home')],
            ['label' => '星座', 'url' => route('roadmap.index')],
            ['label' => '実行', 'url' => route('navigation.index')],
            ['label' => 'タイムライン', 'url' => route('timeline.index')],
        ]);
    }
@endphp

<div
    class="canovia-companion-thread"
    data-companion-palette-thread
    data-companion-palette-thread-id="{{ $thread->id }}"
>
    <div class="canovia-companion-thread-context">
        <div>
            <span>{{ strtoupper($contextSnapshot['scope'] ?? 'global') }}</span>
            <strong>
                {{ $paletteTask?->title ?? $palettePlan?->title ?? 'Canovia全体' }}
            </strong>
        </div>
        <div class="canovia-companion-thread-context-meta">
            <span>Evidence {{ count($contextSnapshot['recent_evidence'] ?? []) }}</span>
            <span>Inbox {{ data_get($contextSnapshot, 'inbox.pending_count', 0) }}</span>
        </div>
    </div>

    <div class="canovia-companion-palette-shortcuts" data-companion-palette-shortcuts>
        @foreach ($paletteShortcuts as $shortcut)
            <a href="{{ $shortcut['url'] }}">{{ $shortcut['label'] }}</a>
        @endforeach
        <a href="{{ route('companion.show', $thread) }}">会話全体</a>
    </div>

    @if (! empty($continuitySignals))
        <div class="canovia-companion-palette-continuity">
            @foreach (collect($continuitySignals)->take(3) as $signal)
                <div>
                    <span>{{ $signal['label'] ?? 'CONTINUITY' }}</span>
                    <p>{{ $signal['summary'] ?? $signal['title'] ?? '確認したい変化があります。' }}</p>
                    @if (($signal['action'] ?? null) === 'ask' && filled($signal['suggested_message'] ?? null))
                        <button
                            type="button"
                            data-companion-palette-suggest="{{ $signal['suggested_message'] }}"
                        >この続きを相談</button>
                    @elseif (($signal['action'] ?? null) === 'review' && filled($signal['anchor'] ?? null))
                        <a href="{{ route('companion.show', $thread) }}#{{ $signal['anchor'] }}">確認する</a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <section class="canovia-companion-palette-messages" data-companion-palette-messages>
        @forelse ($paletteMessages as $message)
            <article class="canovia-companion-palette-message is-{{ $message->role === 'user' ? 'user' : 'assistant' }}">
                <span>{{ $message->role === 'user' ? 'YOU' : 'COMPANION' }}</span>
                <p>{{ $message->content }}</p>

                @if ($message->role === 'assistant' && $message->mutationCandidates->isNotEmpty())
                    <div class="canovia-companion-palette-candidates">
                        @foreach ($message->mutationCandidates as $candidate)
                            @php
                                $preview = $candidatePreviews[$candidate->id] ?? [
                                    'changes' => [],
                                    'blocked_fields' => [],
                                    'target_label' => 'Canovia',
                                ];
                                $previewChanges = $preview['changes'] ?? [];
                                $blockedFields = $preview['blocked_fields'] ?? [];
                            @endphp

                            <div
                                class="canovia-companion-palette-candidate is-{{ $candidate->status }}"
                                data-companion-palette-candidate
                                data-companion-candidate-id="{{ $candidate->id }}"
                            >
                                <div class="canovia-companion-palette-candidate-heading">
                                    <span>{{ $candidateStatusLabels[$candidate->status] ?? $candidate->status }}</span>
                                    <small>{{ $candidateLabels[$candidate->type] ?? $candidate->type }}</small>
                                </div>
                                <strong>{{ $candidate->title }}</strong>
                                <p>{{ $candidate->summary }}</p>
                                <small>{{ $preview['target_label'] ?? 'Canovia' }}</small>

                                @if (! empty($previewChanges))
                                    <dl>
                                        @foreach ($previewChanges as $key => $value)
                                            <div>
                                                <dt>{{ $fieldLabels[$key] ?? $key }}</dt>
                                                <dd>
                                                    @if (is_array($value))
                                                        {{ json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}
                                                    @elseif (is_bool($value))
                                                        {{ $value ? 'はい' : 'いいえ' }}
                                                    @elseif ($value === null)
                                                        未設定
                                                    @else
                                                        {{ $value }}
                                                    @endif
                                                </dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif

                                @if (! empty($blockedFields))
                                    <p class="canovia-companion-palette-blocked">
                                        反映対象外:
                                        {{ collect($blockedFields)->map(fn ($field) => $fieldLabels[$field] ?? $field)->implode(' / ') }}
                                    </p>
                                @endif

                                @if ($candidate->status === 'pending')
                                    <div class="canovia-companion-palette-candidate-actions">
                                        @if ($canApplyCompanionCandidates && ! empty($previewChanges))
                                            <form
                                                method="POST"
                                                action="{{ route('companion.candidates.apply', [$thread, $candidate]) }}"
                                                data-companion-palette-async-form
                                            >
                                                @csrf
                                                <input type="hidden" name="apply_request_id" value="{{ $candidateApplyRequestIds[$candidate->id] ?? '' }}">
                                                <button type="submit">
                                                    {{ $candidate->type === 'prepare_execution_request' ? '確認して準備' : '反映する' }}
                                                </button>
                                            </form>
                                        @endif

                                        <form
                                            method="POST"
                                            action="{{ route('companion.candidates.dismiss', [$thread, $candidate]) }}"
                                            data-companion-palette-async-form
                                        >
                                            @csrf
                                            <button type="submit" class="is-secondary">見送る</button>
                                        </form>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </article>
        @empty
            <div class="canovia-companion-palette-empty">
                <strong>この文脈のまま話しかけて大丈夫です。</strong>
                <p>「今どう見える？」「次は何を優先する？」など、説明し直さず相談できます。</p>
            </div>
        @endforelse
    </section>

    @if ($canUseCompanion)
        <form
            method="POST"
            action="{{ route('companion.messages.store', $thread) }}"
            class="canovia-companion-palette-compose"
            data-companion-palette-async-form
            data-companion-palette-compose
        >
            @csrf
            <input type="hidden" name="request_id" value="{{ $messageRequestId }}">
            <input type="hidden" name="source_path" value="{{ $companionSourcePath }}">
            <textarea
                name="content"
                rows="2"
                required
                maxlength="6000"
                placeholder="Canoviaに相談する…"
            ></textarea>
            <button type="submit">送信</button>
        </form>
    @else
        <div class="canovia-companion-palette-unavailable">
            Companionの会話機能は現在利用できません。
        </div>
    @endif
</div>
