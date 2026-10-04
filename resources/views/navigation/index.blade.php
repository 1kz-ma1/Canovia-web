@extends('layouts.app')

@section('title', '実行 | Canovia')

@section('content')
    <div class="mx-auto max-w-3xl space-y-5 md:space-y-6">
        <header class="pk-v18-page-hero pk-v18-today-hero">
            <div class="relative z-10 min-w-0">
                <p class="pk-v18-eyebrow">
                    EXECUTION /
                    {{ $selectedExecutionModeDefinition['eyebrow'] ?? 'MODE SELECT' }}
                </p>
                <h1>
                    @if ($selectedExecutionModeDefinition)
                        {{ $selectedExecutionModeDefinition['label'] }}モード
                    @else
                        実行方法を選ぶ。
                    @endif
                </h1>
                <p>
                    @if ($selectedExecutionModeDefinition)
                        {{ $selectedExecutionModeDefinition['description'] }}
                    @else
                        Planの種類に合わせて、実行タイプを切り替えます。
                    @endif
                </p>
            </div>
            <div class="pk-v18-page-guide" aria-hidden="true">
                <span>いっしょに
進もう ✦</span>
                <img src="/brand/mascot-guide.webp" alt="">
            </div>
        </header>

        <section class="pk-offline-resume-card hidden" data-offline-timer-card aria-live="polite">
            <div class="min-w-0">
                <p class="pk-v18-eyebrow">OFFLINE TIMER / RESUME</p>
                <h2 class="mt-1 break-words text-base font-black text-slate-100" data-offline-timer-task>オフライン作業</h2>
                <p class="mt-1 text-xs leading-5 text-slate-400" data-offline-timer-status>端末にタイマーを保持しています。</p>
            </div>
            <p class="pk-offline-resume-time" data-offline-timer-value>00:00</p>
            <div class="grid grid-cols-2 gap-2">
                <button type="button" class="btn-secondary w-full justify-center" data-offline-timer-toggle>一時停止</button>
                <button type="button" class="btn-primary w-full justify-center" data-offline-timer-complete>記録して終了</button>
            </div>
            <p class="text-[11px] leading-5 text-slate-500" data-offline-timer-note>接続中はここから終了・記録できます。</p>
        </section>

        @if (empty($availableExecutionModes))
            <section class="execution-mode-empty page-card">
                <p class="pk-v18-eyebrow">EXECUTION</p>
                <h2>実行できるPlanがまだありません</h2>
                <p>Planを作ると、その内容に合う実行タイプがここに現れます。</p>
                <a href="{{ route('plans.create') }}" class="btn-primary">Planを作る</a>
            </section>
        @elseif (! $selectedExecutionMode)
            <section class="execution-mode-picker" data-execution-mode-picker>
                <div class="execution-mode-picker-heading">
                    <p class="pk-v18-eyebrow">CHOOSE MODE</p>
                    <h2>どの種類の作業を進めますか？</h2>
                    <p>複数の実行方法があるときだけ選びます。選択後も上部から切り替えられます。</p>
                </div>

                <div class="execution-mode-grid">
                    @foreach ($availableExecutionModes as $modeKey => $mode)
                        <a
                            href="{{ route('navigation.index', ['mode' => $modeKey]) }}"
                            class="execution-mode-card"
                            data-execution-mode="{{ $modeKey }}"
                        >
                            <span class="execution-mode-card-icon" aria-hidden="true">{{ $mode['icon'] }}</span>
                            <div>
                                <span>{{ $mode['eyebrow'] }}</span>
                                <strong>{{ $mode['label'] }}</strong>
                                <p>{{ $mode['description'] }}</p>
                            </div>
                            <small>{{ $mode['plan_count'] }} Plan</small>
                        </a>
                    @endforeach
                </div>
            </section>
        @else
            <nav class="execution-mode-switcher" data-execution-mode-switcher aria-label="Execution Modeを切り替える">
                @foreach ($availableExecutionModes as $modeKey => $mode)
                    <a
                        href="{{ route('navigation.index', ['mode' => $modeKey]) }}"
                        class="{{ $selectedExecutionMode === $modeKey ? 'is-active' : '' }}"
                        data-execution-mode="{{ $modeKey }}"
                        aria-current="{{ $selectedExecutionMode === $modeKey ? 'page' : 'false' }}"
                    >
                        <span aria-hidden="true">{{ $mode['icon'] }}</span>
                        <strong>{{ $mode['label'] }}</strong>
                        <small>{{ $mode['plan_count'] }}</small>
                    </a>
                @endforeach
            </nav>

            @if ($modePlans->count() > 1)
                <section class="execution-plan-switcher" aria-label="この実行タイプのPlan">
                    <a
                        href="{{ route('navigation.index', ['mode' => $selectedExecutionMode, 'all' => 1]) }}"
                        class="{{ $scopePlan ? '' : 'is-active' }}"
                    >すべて</a>
                    @foreach ($modePlans as $modePlan)
                        <a
                            href="{{ route('navigation.index', ['plan_id' => $modePlan->id]) }}"
                            class="{{ $scopePlan?->id === $modePlan->id ? 'is-active' : '' }}"
                        >
                            <span aria-hidden="true">{{ $modePlan->displayIcon() }}</span>
                            {{ $modePlan->title }}
                        </a>
                    @endforeach
                </section>
            @endif
        @endif

        @if ($selectedExecutionMode)
        @if ($scopePlan)
            <div class="rounded-2xl border border-sky-400/20 bg-sky-500/10 px-4 py-3 text-sm text-slate-200">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                    <p><span class="font-semibold text-sky-300">{{ $scopePlan->title }}</span> から選んでいます。</p>
                    <a href="{{ route('navigation.index', ['mode' => $selectedExecutionMode, 'all' => 1]) }}" class="whitespace-nowrap text-sm font-semibold text-sky-300 hover:text-sky-200">この実行タイプの全Planから選ぶ</a>
                </div>
            </div>
        @endif

        <main class="assistant-chat-shell pk-v18-guidance-shell">
                <div class="assistant-message-row assistant-message-left">
                    <div class="assistant-avatar pk-assistant-avatar"><img src="/brand/logo-mark.svg" alt="" width="26" height="26"></div>
                    <div class="assistant-bubble assistant-bubble-support assistant-wide-bubble">
                        @if ($recommendation)
                            @php
                                $alternativeRecommendations = collect($recommendations ?? [])
                                    ->reject(fn ($candidate) =>
                                        (int) $candidate->task->id === (int) $recommendation->task->id
                                        || (int) $candidate->plan->id === (int) $recommendation->plan->id
                                    )
                                    ->take(3)
                                    ->values();
                                $primaryUsesTimer = ($recommendationAction['action_id'] ?? 'timer') === 'timer';
                            @endphp
                            <section class="pk-v18-today-card plan-identity-shell" data-plan-accent="{{ $recommendation->plan->accentKey() }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-sky-300">おすすめ</p>
                                        <h2 class="mt-1.5 text-lg font-black text-slate-100 sm:text-xl">{{ $recommendation->task->title }}</h2>
                                        <p class="mt-1 plan-identity-chip truncate text-sm"><span aria-hidden="true">{{ $recommendation->plan->displayIcon() }}</span>{{ $recommendation->plan->title }}</p>
                                    </div>
                                    <div class="shrink-0 rounded-2xl border border-sky-400/20 bg-slate-950/40 px-3 py-2 text-right">
                                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">目安</p>
                                        <p class="mt-0.5 text-xl font-black tabular-nums text-slate-100">{{ $recommendation->recommendedMinutes }}分</p>
                                    </div>
                                </div>

                                @if ($recommendation->task->next_action_note)
                                    <p class="mt-4 rounded-xl border border-sky-400/15 bg-sky-500/10 px-4 py-3 text-sm text-slate-200">
                                        前回メモ：{{ $recommendation->task->next_action_note }}
                                    </p>
                                @endif

                                @if ($recommendation->reasons)
                                    <ul class="mt-4 space-y-1.5 text-sm text-slate-300">
                                        @foreach ($recommendation->reasons as $reason)
                                            <li>・{{ $reason }}</li>
                                        @endforeach
                                    </ul>
                                @endif

                                @if ($primaryUsesTimer)
                                    <div class="mt-5 rounded-2xl border border-emerald-400/15 bg-emerald-500/5 px-4 py-4 text-center">
                                        <p class="text-lg font-black text-slate-100">準備ができたら開始</p>
                                        <p class="mt-1 text-xs leading-5 text-slate-400">開始ボタンを押した瞬間から計測します。まず{{ $recommendation->recommendedMinutes }}分を目安に。</p>
                                    </div>
                                @else
                                    <div class="mt-5 rounded-2xl border border-cyan-400/15 bg-cyan-500/5 px-4 py-4 text-center">
                                        <p class="text-lg font-black text-slate-100">このTaskを専用フローへ引き継ぐ</p>
                                        <p class="mt-1 text-xs leading-5 text-slate-400">Task Contextを保ったまま、{{ $selectedExecutionModeDefinition['label'] ?? '専用' }}の実行フローで進めます。</p>
                                    </div>
                                @endif

                                <div class="execution-primary-actions mobile-sticky-primary mt-4" data-execution-handoff="{{ $recommendationAction['action_id'] ?? 'timer' }}">
                                    @if (! $primaryUsesTimer)
                                        <a
                                            href="{{ route($recommendationAction['route_name'], $recommendationAction['route_parameters']) }}"
                                            class="pk-v18-start-cta w-full justify-center"
                                            data-onboarding-target="today-start"
                                        >
                                            {{ $recommendationAction['label'] }}
                                        </a>
                                    @else
                                        <form method="POST" action="{{ route('work_sessions.start') }}" data-work-start-form data-execution-primary-timer>
                                            @csrf
                                            <input type="hidden" name="task_id" value="{{ $recommendation->task->id }}">
                                            <input type="hidden" name="intended_minutes" value="{{ $recommendation->recommendedMinutes }}">
                                            <input type="hidden" name="source" value="navigation">
                                            <button class="pk-v18-start-cta w-full justify-center" data-onboarding-target="today-start">{{ $recommendationAction['label'] ?? 'このまま開始' }}</button>
                                        </form>
                                    @endif
                                </div>

                                @if (! empty($recommendationAction['description']))
                                    <p class="execution-handoff-note">{{ $recommendationAction['description'] }}</p>
                                @endif
                            </section>

                            @if ($alternativeRecommendations->isNotEmpty())
                                <section
                                    class="execution-recommendation-rail"
                                    data-candidate-carousel
                                    data-candidate-always-open
                                    data-execution-recommendation-rail
                                    data-event-url="{{ route('behavior_events.store') }}"
                                    aria-label="同じ実行タイプのおすすめTask"
                                >
                                    <div class="execution-recommendation-rail-heading">
                                        <div>
                                            <p class="pk-v18-eyebrow">SAME MODE</p>
                                            <h3>他Planの同じ実行タイプ</h3>
                                            <p>同じ実行タイプでそのまま進められるTaskだけを、Planごとに1件ずつ並べています。</p>
                                        </div>
                                    </div>

                                    <div class="candidate-carousel-shell is-open" data-candidate-shell>
                                        <div class="candidate-track execution-recommendation-track" data-candidate-track data-execution-recommendation-track>
                                            @foreach ($alternativeRecommendations as $candidate)
                                                @php
                                                    $candidateAction = $recommendationActions->get((int) $candidate->task->id);
                                                    $candidateUsesTimer = ($candidateAction['action_id'] ?? 'timer') === 'timer';
                                                @endphp
                                                <article
                                                    class="candidate-card execution-recommendation-card plan-identity-shell"
                                                    data-plan-accent="{{ $candidate->plan->accentKey() }}"
                                                    data-candidate-card
                                                    data-task-id="{{ $candidate->task->id }}"
                                                    data-plan-id="{{ $candidate->plan->id }}"
                                                    data-execution-alternative-plan-id="{{ $candidate->plan->id }}"
                                                >
                                                    <div class="flex items-start justify-between gap-3">
                                                        <div class="min-w-0">
                                                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">他Plan</p>
                                                            <h3 class="mt-2 text-base font-bold text-slate-100">{{ $candidate->task->title }}</h3>
                                                            <p class="mt-1 plan-identity-chip truncate text-xs"><span aria-hidden="true">{{ $candidate->plan->displayIcon() }}</span>{{ $candidate->plan->title }}</p>
                                                        </div>
                                                        <span class="badge badge-slate shrink-0">{{ $candidate->recommendedMinutes }}分</span>
                                                    </div>

                                                    @if ($candidate->task->next_action_note)
                                                        <p class="execution-recommendation-next">次: {{ $candidate->task->next_action_note }}</p>
                                                    @elseif ($candidate->reasons)
                                                        <p class="execution-recommendation-next">{{ $candidate->reasons[0] ?? '' }}</p>
                                                    @endif

                                                    <div class="mt-4" data-execution-handoff="{{ $candidateAction['action_id'] ?? 'timer' }}">
                                                        @if (! $candidateUsesTimer)
                                                            <a
                                                                href="{{ route($candidateAction['route_name'], $candidateAction['route_parameters']) }}"
                                                                class="btn-secondary w-full justify-center"
                                                            >{{ $candidateAction['label'] }}</a>
                                                        @else
                                                            <form method="POST" action="{{ route('work_sessions.start') }}" data-work-start-form>
                                                                @csrf
                                                                <input type="hidden" name="task_id" value="{{ $candidate->task->id }}">
                                                                <input type="hidden" name="intended_minutes" value="{{ $candidate->recommendedMinutes }}">
                                                                <input type="hidden" name="source" value="navigation">
                                                                <button class="btn-secondary w-full justify-center">これを始める</button>
                                                            </form>
                                                        @endif
                                                    </div>
                                                </article>
                                            @endforeach
                                        </div>

                                        <div class="candidate-pagination" aria-hidden="true">
                                            @foreach ($alternativeRecommendations as $candidate)
                                                <button type="button" class="candidate-dot {{ $loop->first ? 'is-active' : '' }}" data-candidate-dot></button>
                                            @endforeach
                                        </div>
                                    </div>
                                </section>
                            @endif

                            <p class="mt-4 text-xs leading-5 text-slate-500">
                                どの候補を見て、どれを開始したかも次回のおすすめ改善に使われます。
                            </p>
                        @else
                            <h2 class="text-xl font-bold text-slate-100">{{ $selectedExecutionModeDefinition['label'] ?? '' }}モードで今すぐ始められるTaskが見つかりませんでした</h2>
                            <p class="mt-2 text-slate-400">Planへ実行可能なTaskを追加するか、上の実行タイプを切り替えてください。</p>
                            <div class="mt-5">
                                <form method="POST" action="{{ route('navigation.reset') }}">
                                    @csrf
                                    <button class="btn-primary w-full sm:w-auto">おすすめを戻す</button>
                                </form>
                            </div>
                        @endif
                    </div>
                </div>
        </main>
        @endif
    </div>
@endsection

@section('offline_snapshot')
@if ($recommendation)
@php
    $offlineCurrent = [
        'task_id' => $recommendation->task->id,
        'title' => $recommendation->task->title,
        'status' => $recommendation->task->status,
        'status_label' => $recommendation->task->status === 'doing' ? '進行中' : '未着手',
        'progress_percent' => $recommendation->task->progress_percent,
        'remaining_minutes' => $recommendation->task->remaining_minutes,
        'next_action_note' => $recommendation->task->next_action_note,
        'is_current' => true,
    ];
    $offlineSnapshot = [
        'type' => 'today',
        'captured_at' => now()->toIso8601String(),
        'csrf_token' => csrf_token(),
        'plan' => ['id' => $recommendation->plan->id, 'title' => $recommendation->plan->title],
        'current' => $offlineCurrent,
        'roadmap' => collect($recommendations ?? [])
            ->map(fn ($candidate) => [
                'task_id' => $candidate->task->id,
                'title' => $candidate->task->title,
                'status' => $candidate->task->status,
                'status_label' => $candidate->task->status === 'doing' ? '進行中' : '候補',
                'progress_percent' => $candidate->task->progress_percent,
                'remaining_minutes' => $candidate->task->remaining_minutes,
                'next_action_note' => $candidate->task->next_action_note,
                'is_current' => $candidate->task->id === $recommendation->task->id,
            ])
            ->values()
            ->all(),
    ];
@endphp
<script type="application/json" id="pacekeeper-offline-snapshot">{!! json_encode($offlineSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endif
@endsection
