@extends('layouts.app')

@section('title', '実行 | Canovia')

@section('content')
    <div class="mx-auto max-w-3xl space-y-5 md:space-y-6">
        <header class="pk-v18-page-hero pk-v18-today-hero">
            <div class="relative z-10 min-w-0">
                <p class="pk-v18-eyebrow">EXECUTION / TAKE ACTION</p>
                <h1>今、実行する一歩を決めよう。</h1>
                <p>おすすめTaskから、そのまま実行へ入れます。必要なときだけ条件を変えて選び直せます。</p>
            </div>
            <div class="pk-v18-page-guide" aria-hidden="true">
                <span>いっしょに
進もう ✦</span>
                <img src="/brand/mascot-guide.webp" alt="">
            </div>
            @if (($draft['step'] ?? 'recommendation') !== 'recommendation')
                <form method="POST" action="{{ route('navigation.reset') }}" class="relative z-20 shrink-0">
                    @csrf
                    <button class="pk-v18-action-chip">戻す</button>
                </form>
            @endif
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

        @if ($scopePlan)
            <div class="rounded-2xl border border-sky-400/20 bg-sky-500/10 px-4 py-3 text-sm text-slate-200">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                    <p><span class="font-semibold text-sky-300">{{ $scopePlan->title }}</span> から選んでいます。</p>
                    <a href="{{ route('navigation.index', ['all' => 1]) }}" class="whitespace-nowrap text-sm font-semibold text-sky-300 hover:text-sky-200">すべての計画から選ぶ</a>
                </div>
            </div>
        @endif

        <main class="assistant-chat-shell pk-v18-guidance-shell">
            @if (($draft['step'] ?? 'recommendation') === 'intent')
                <div class="assistant-message-row assistant-message-left">
                    <div class="assistant-avatar pk-assistant-avatar"><img src="/brand/logo-mark.svg" alt="" width="26" height="26"></div>
                    <div class="assistant-bubble assistant-bubble-support assistant-wide-bubble">
                        <p class="text-sm font-semibold text-sky-300">条件を変える</p>
                        <h2 class="mt-2 text-xl font-bold text-slate-100">今日はどう進めたい？</h2>
                        <p class="mt-2 text-sm text-slate-400">必要なときだけ条件を指定してください。</p>
                        <div class="chat-action-grid mt-5">
                            @foreach ($intentOptions as $value => $label)
                                <form method="POST" action="{{ route('navigation.intent') }}">
                                    @csrf
                                    <input type="hidden" name="intent" value="{{ $value }}">
                                    <button class="chat-action-card w-full text-left"><span class="font-bold text-slate-100">{{ $label }}</span></button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                </div>
            @elseif (($draft['step'] ?? null) === 'time')
                <div class="assistant-message-row assistant-message-left">
                    <div class="assistant-avatar pk-assistant-avatar"><img src="/brand/logo-mark.svg" alt="" width="26" height="26"></div>
                    <div class="assistant-bubble assistant-bubble-support assistant-wide-bubble">
                        <h2 class="text-xl font-bold text-slate-100">どれくらい時間がありますか？</h2>
                        <form method="POST" action="{{ route('navigation.time') }}" class="mt-5 space-y-5">
                            @csrf
                            <div class="grid grid-cols-2 gap-3">
                                @foreach ($timeOptions as $value => $label)
                                    <label class="chat-choice-card">
                                        <input type="radio" name="minutes" value="{{ $value }}" required>
                                        <span class="font-bold text-slate-100">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @if (($draft['intent'] ?? null) === 'preferred')
                                <label>
                                    <span class="form-label">進めたい計画</span>
                                    <select name="preferred_plan_id" class="form-control" required>
                                        <option value="">選択</option>
                                        @foreach ($plans as $plan)
                                            <option value="{{ $plan->id }}">{{ $plan->title }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endif
                            <button class="btn-primary w-full sm:w-auto">この条件で見る</button>
                        </form>
                    </div>
                </div>
            @else
                <div class="assistant-message-row assistant-message-left">
                    <div class="assistant-avatar pk-assistant-avatar"><img src="/brand/logo-mark.svg" alt="" width="26" height="26"></div>
                    <div class="assistant-bubble assistant-bubble-support assistant-wide-bubble">
                        @if ($recommendation)
                            @php
                                $alternativeRecommendations = collect($recommendations ?? [])
                                    ->reject(fn ($candidate) => (int) $candidate->task->id === (int) $recommendation->task->id)
                                    ->take(2)
                                    ->values();
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

                                <div class="mt-5 rounded-2xl border border-emerald-400/15 bg-emerald-500/5 px-4 py-4 text-center">
                                    <p class="text-lg font-black text-slate-100">準備ができたら開始</p>
                                    <p class="mt-1 text-xs leading-5 text-slate-400">開始ボタンを押した瞬間から計測します。まず{{ $recommendation->recommendedMinutes }}分を目安に。</p>
                                </div>

                                <form method="POST" action="{{ route('work_sessions.start') }}" data-work-start-form class="mobile-sticky-primary mt-4">
                                    @csrf
                                    <input type="hidden" name="task_id" value="{{ $recommendation->task->id }}">
                                    <input type="hidden" name="intended_minutes" value="{{ $recommendation->recommendedMinutes }}">
                                    <input type="hidden" name="source" value="navigation">
                                    <button class="pk-v18-start-cta w-full justify-center" data-onboarding-target="today-start">このまま開始</button>
                                </form>
                            </section>

                            <div class="mt-4" data-candidate-carousel data-event-url="{{ route('behavior_events.store') }}">
                                @if ($alternativeRecommendations->isNotEmpty())
                                    <button type="button" class="btn-secondary w-full justify-center sm:w-auto" data-candidate-toggle aria-expanded="false">
                                        別候補を見る
                                    </button>

                                    <section class="candidate-carousel-shell" data-candidate-shell aria-label="別のTask候補">
                                        <div class="mb-2 flex items-center justify-between gap-3">
                                            <div>
                                                <p class="text-sm font-bold text-slate-100">横にスワイプして選ぶ</p>
                                                <p class="mt-0.5 text-xs text-slate-500">おすすめ以外の候補だけを表示します。</p>
                                            </div>
                                            <a href="{{ route('navigation.index', ['configure' => 1]) }}" class="whitespace-nowrap text-xs font-semibold text-sky-300">条件変更</a>
                                        </div>

                                        <div class="candidate-track" data-candidate-track>
                                            @foreach ($alternativeRecommendations as $candidate)
                                                <article class="candidate-card plan-identity-shell"
                                                         data-plan-accent="{{ $candidate->plan->accentKey() }}"
                                                         data-candidate-card
                                                         data-task-id="{{ $candidate->task->id }}"
                                                         data-plan-id="{{ $candidate->plan->id }}">
                                                    <div class="flex items-start justify-between gap-3">
                                                        <div class="min-w-0">
                                                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">候補 {{ $loop->iteration }}</p>
                                                            <h3 class="mt-2 text-base font-bold text-slate-100">{{ $candidate->task->title }}</h3>
                                                            <p class="mt-1 plan-identity-chip truncate text-xs"><span aria-hidden="true">{{ $candidate->plan->displayIcon() }}</span>{{ $candidate->plan->title }}</p>
                                                        </div>
                                                        <span class="badge badge-slate shrink-0">{{ $candidate->recommendedMinutes }}分</span>
                                                    </div>

                                                    @if ($candidate->reasons)
                                                        <ul class="mt-4 space-y-1 text-xs leading-5 text-slate-400">
                                                            @foreach ($candidate->reasons as $reason)
                                                                <li>・{{ $reason }}</li>
                                                            @endforeach
                                                        </ul>
                                                    @endif

                                                    <form method="POST" action="{{ route('work_sessions.start') }}" data-work-start-form class="mt-5">
                                                        @csrf
                                                        <input type="hidden" name="task_id" value="{{ $candidate->task->id }}">
                                                        <input type="hidden" name="intended_minutes" value="{{ $candidate->recommendedMinutes }}">
                                                        <input type="hidden" name="source" value="navigation">
                                                        <button class="btn-secondary w-full justify-center">これを始める</button>
                                                    </form>
                                                </article>
                                            @endforeach
                                        </div>
                                        <div class="candidate-pagination" aria-hidden="true">
                                            @foreach ($alternativeRecommendations as $candidate)
                                                <button type="button" class="candidate-dot {{ $loop->first ? 'is-active' : '' }}" data-candidate-dot></button>
                                            @endforeach
                                        </div>
                                    </section>
                                @else
                                    <a href="{{ route('navigation.index', ['configure' => 1]) }}" class="text-sm font-semibold text-sky-300 hover:text-sky-200">条件を変えて選ぶ</a>
                                @endif
                            </div>

                            <p class="mt-4 text-xs leading-5 text-slate-500">
                                どの候補を見て、どれを開始したかも次回のおすすめ改善に使われます。
                            </p>
                        @else
                            <h2 class="text-xl font-bold text-slate-100">今すぐ始められるタスクが見つかりませんでした</h2>
                            <p class="mt-2 text-slate-400">条件を変えるか、タスクを追加してからもう一度試してください。</p>
                            <div class="mt-5 grid gap-3 sm:flex sm:flex-wrap">
                                <form method="POST" action="{{ route('navigation.reset') }}">
                                    @csrf
                                    <button class="btn-primary w-full sm:w-auto">おすすめを戻す</button>
                                </form>
                                <a href="{{ route('navigation.index', ['configure' => 1]) }}" class="btn-secondary w-full sm:w-auto">条件を変える</a>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </main>
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
