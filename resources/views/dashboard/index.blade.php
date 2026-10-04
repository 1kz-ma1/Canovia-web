@extends(($instantFragment ?? false) || in_array(request()->header('X-Canovia-Instant-Navigation'), ['prefetch', 'navigate'], true) ? 'layouts.instant' : 'layouts.app')

@section('title', 'ホーム | Canovia')

@section('content')
    @php
        $state = $dashboard['state'];
        $guidanceDeck = $dashboard['guidance_deck'] ?? collect();
        $primaryGuidance = $guidanceDeck->first();
        $intelligenceAction = $intelligenceAction ?? null;
        $intelligencePlanId = (int) data_get($intelligenceAction, 'plan.id', 0);
        $taskGuidanceDeck = $intelligenceAction
            ? $guidanceDeck
                ->reject(fn ($guidance) => (int) data_get($guidance, 'plan.id', 0) === $intelligencePlanId)
                ->values()
            : $guidanceDeck;
        $primaryDisplayPlan = data_get($intelligenceAction, 'plan')
            ?? data_get($primaryGuidance, 'plan');
        $activeSession = $dashboard['active_work_session'];
        $focusAlternatives = $activeSession
            ? $guidanceDeck
                ->reject(fn ($guidance) => (int) data_get($guidance, 'plan.id', 0) === (int) $activeSession->plan_id)
                ->take(4)
                ->values()
            : collect();
        $todayRemaining = max(0, $dashboard['total_daily_required_minutes'] - $dashboard['today_minutes']);
        $calendarWeek = $dashboard['calendar_week'] ?? null;
        $continuity = $dashboard['continuity'] ?? null;
        $signals = collect($actionHome['signals'] ?? []);
        $recentActivity = collect($dashboard['recent_activity'] ?? [])->take(3);
        $processMessage = $dashboard['process_message'] ?? '続けることで、きっとどこかでつながってる。';
    @endphp

    @include('layouts.partials.home-surface-switcher', ['activeSurface' => 'classic'])

    <div
        id="behaviorDashboard"
        class="pk-v18-dashboard canovia-action-home space-y-5 md:space-y-6"
        data-action-home
        data-action-home-schema="{{ $actionHome['schema_version'] ?? 1 }}"
        data-guide-target="home-now"
        data-event-url="{{ route('behavior_events.store') }}"
        data-navigation-url="{{ route('navigation.index') }}"
        data-work-started="{{ $activeSession ? 1 : 0 }}"
        data-onboarding-new-user="{{ $dashboard['plan_tabs']->isEmpty() ? '1' : '0' }}"
    >
        <div class="pk-v22-hero-stage">
            <header class="pk-v18-hero pk-home-heading">
                <div class="pk-v18-hero-copy">
                    <p class="pk-v18-card-kicker">ACTION HOME</p>
                    <h1>今、やることに集中しよう。</h1>
                    <p class="pk-v18-hero-lead">Canoviaが、次の一歩と見逃したくない変化だけをここへ集めます。</p>
                </div>
                <div class="pk-v18-hero-guide" aria-hidden="true">
                    <img src="/brand/mascot-guide.webp" alt="">
                </div>
                <div class="pk-v18-hero-orbit" aria-hidden="true"></div>
                <div class="pk-v18-hero-planet" aria-hidden="true"></div>
            </header>
            <div class="pk-v18-hero-brand pk-canovia-hero-brand pk-v22-hero-brand-layer" aria-label="Canovia カノーヴィア">
                <img src="/brand/canovia-wordmark.png" alt="Canovia カノーヴィア" class="pk-canovia-wordmark">
                <small>未来までの航路を、一緒に。</small>
            </div>
            <span class="pk-v18-guide-bubble pk-v22-guide-bubble-layer" aria-hidden="true">今必要なことだけ<br>見ていこう！</span>
        </div>

        @if (session('success'))
            <div class="assistant-notice assistant-notice-success">{{ session('success') }}</div>
        @endif
        @if (session('status'))
            <div class="assistant-notice assistant-notice-info">{{ session('status') }}</div>
        @endif

        @if ($activeSession)
            <section class="pk-v18-focus-deck" data-action-home-focus-deck>
                <div class="pk-v18-focus-track" aria-label="作業中と他Planの候補">
                    <article class="pk-v18-active-session pk-v18-focus-card plan-identity-shell" data-plan-accent="{{ $activeSession->plan?->accentKey() ?? 'sky' }}" data-action-home-active-session>
                        <div class="min-w-0">
                            <p class="pk-v18-card-kicker">{{ $activeSession->status === 'paused' ? 'PAUSED' : 'IN FOCUS' }}</p>
                            <h2>{{ $activeSession->task?->title ?? '作業中のタスク' }}</h2>
                            <p>{{ $activeSession->plan?->displayIcon() }} {{ $activeSession->plan?->title }}</p>
                        </div>
                        <a href="{{ route('work_sessions.active', $activeSession) }}" class="btn-primary">作業へ戻る</a>
                    </article>

                    @foreach ($focusAlternatives as $focusGuidance)
                        @php
                            $focusPlan = $focusGuidance['plan'];
                            $focusTask = $focusGuidance['task'];
                        @endphp
                        <article
                            class="pk-v18-focus-card pk-v18-focus-alternative plan-identity-shell"
                            data-plan-accent="{{ $focusPlan->accentKey() }}"
                            data-action-home-focus-alternative
                        >
                            <div class="min-w-0">
                                <p class="pk-v18-card-kicker">OTHER PLAN</p>
                                <h2>{{ $focusTask->title }}</h2>
                                <p>{{ $focusPlan->displayIcon() }} {{ $focusPlan->title }}</p>
                            </div>
                            <a href="{{ route('navigation.index', ['plan_id' => $focusPlan->id]) }}" class="btn-secondary">このPlanを見る</a>
                        </article>
                    @endforeach
                </div>

                @if ($focusAlternatives->isNotEmpty())
                    <p class="pk-v18-focus-swipe-hint">横にスワイプすると、他のPlanも確認できます。</p>
                @endif
            </section>
        @endif

        @if ($dashboard['plan_tabs']->isEmpty())
            <section class="empty-state page-card p-8 text-center" data-action-home-create-prompt>
                <div class="text-4xl" aria-hidden="true">✦</div>
                <p class="pk-v18-card-kicker mt-3">START HERE</p>
                <h2 class="mt-2 text-xl font-black text-slate-100">最初の計画を作ろう</h2>
                <p class="mt-2 text-sm leading-6 text-slate-400">計画がまだないときは、Homeから最初の一歩を作ることが最優先です。目標が曖昧でもCanoviaと整理できます。</p>
                <div class="mt-5 flex flex-wrap justify-center gap-2">
                    <a href="{{ route('plans.create') }}" class="btn-primary" data-onboarding-target="create-plan">計画を作る</a>
                </div>
            </section>
        @elseif (! $activeSession && ($guidanceDeck->isNotEmpty() || $intelligenceAction))
            <section class="pk-v18-recommendation pk-v395-guidance plan-identity-shell" data-plan-accent="{{ $primaryDisplayPlan?->accentKey() ?? 'sky' }}" data-action-home-guidance>
                <div class="pk-v18-recommendation-titlebar">
                    <div class="flex items-center gap-2">
                        <span class="pk-v18-starlight" aria-hidden="true">✦</span>
                        <div>
                            <p class="pk-v18-card-kicker">NEXT ACTION</p>
                            <h2>今やること</h2>
                        </div>
                    </div>
                    <a href="{{ route('navigation.index') }}" class="text-xs font-bold text-sky-300">実行を開く →</a>
                </div>

                <div class="pk-v395-guidance-track" aria-label="計画ごとの次Action">
                    @if ($intelligenceAction)
                        @php
                            $iaPlan = $intelligenceAction['plan'];
                            $iaAction = $intelligenceAction['action'];
                            $iaDecision = $intelligenceAction['decision'];
                            $iaReadiness = $intelligenceAction['readiness'];
                        @endphp
                        <article class="pk-v395-guidance-card plan-identity-shell" data-plan-accent="{{ $iaPlan->accentKey() }}" data-intelligence-action>
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <span class="plan-identity-chip text-[11px]"><span aria-hidden="true">{{ $iaPlan->displayIcon() }}</span>{{ $iaPlan->title }}</span>
                                    <h3 class="mt-2 text-base font-black leading-6 text-white">{{ $iaAction->title }}</h3>
                                </div>
                                <span class="badge badge-green">最優先</span>
                            </div>

                            <p class="mt-2 text-xs text-slate-400">
                                Intelligence Action
                                @if ($iaReadiness->score !== null)
                                    · 準備度 {{ $iaReadiness->score }}/100
                                @endif
                                · 信頼度 {{ $iaReadiness->confidence->percent() }}%
                            </p>

                            <form method="POST" action="{{ $intelligenceAction['execute_url'] }}" class="mt-3">
                                @csrf
                                <button type="submit" class="btn-primary w-full px-3 py-2 text-xs" data-onboarding-target="today-start">
                                    {{ $intelligenceAction['action_label'] }}
                                </button>
                            </form>

                            <details class="pk-action-details mt-3" data-guidance-reasons>
                                <summary>なぜこのAction？</summary>
                                <p class="mt-3 text-xs leading-5 text-slate-300">{{ $iaAction->intent }}</p>
                                <div class="mt-3 rounded-xl border border-emerald-300/15 bg-emerald-300/[0.04] px-3 py-2.5">
                                    <p class="text-[11px] font-bold text-emerald-200">Canoviaの判断</p>
                                    <p class="mt-1 text-[11px] leading-4 text-slate-400">{{ $iaDecision->summary }}</p>
                                    @if ($intelligenceAction['requires_task_projection'])
                                        <p class="mt-2 text-[10px] leading-4 text-slate-500">
                                            このActionに使える既存Taskがないため、実行を選んだ時だけTaskへ投影します。
                                        </p>
                                    @endif
                                </div>
                            </details>
                        </article>
                    @endif

                    @foreach ($taskGuidanceDeck as $guidanceIndex => $guidance)
                        @php
                            $guidancePlan = $guidance['plan'];
                            $guidanceTask = $guidance['task'];
                            $adaptive = $guidance['adaptive'];
                            $tool = $guidance['recommended_tool'];
                        @endphp
                        <article class="pk-v395-guidance-card plan-identity-shell" data-plan-accent="{{ $guidancePlan->accentKey() }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <span class="plan-identity-chip text-[11px]"><span aria-hidden="true">{{ $guidancePlan->displayIcon() }}</span>{{ $guidancePlan->title }}</span>
                                    <h3 class="mt-2 text-base font-black leading-6 text-white">{{ $guidanceTask->title }}</h3>
                                </div>
                                @php
                                    $guidanceDisplayIndex = $intelligenceAction ? $guidanceIndex + 2 : $guidanceIndex + 1;
                                    $guidanceIsPrimary = ! $intelligenceAction && $guidanceIndex === 0;
                                @endphp
                                <span class="badge {{ $guidanceIsPrimary ? 'badge-green' : 'badge-slate' }}">{{ $guidanceIsPrimary ? '最優先' : '候補 '.$guidanceDisplayIndex }}</span>
                            </div>

                            <p class="mt-2 text-xs text-slate-400">
                                {{ $guidanceTask->status === 'doing' ? '進行中' : '未着手' }}
                                @if (($tool['id'] ?? null) === 'timer')
                                    · 目安 {{ $adaptive?->recommendedMinutes ?? (int) ($guidanceTask->remaining_minutes ?? 0) }}分
                                @endif
                            </p>

                            <div class="mt-3 flex flex-wrap gap-2">
                                @if (($tool['id'] ?? null) === 'study_activity')
                                    <a href="{{ route('plans.tasks.study_activity.show', [$guidancePlan, $guidanceTask]) }}" class="btn-primary flex-1 px-3 py-2 text-xs">{{ data_get($tool, 'activity.action_label', '学習方法で進める') }}</a>
                                @elseif (($tool['id'] ?? null) === 'ai_practice')
                                    <a href="{{ route('plans.tasks.study_practice.show', [$guidancePlan, $guidanceTask]) }}" class="btn-primary flex-1 px-3 py-2 text-xs">AI演習で進める</a>
                                @elseif (($tool['id'] ?? null) === 'career_workspace')
                                    <a href="{{ route('plans.career.index', $guidancePlan) }}" class="btn-primary flex-1 px-3 py-2 text-xs">Careerで進める</a>
                                @elseif (($tool['id'] ?? null) === 'artifacts')
                                    <a href="{{ route('plans.artifacts.index', $guidancePlan) }}" class="btn-primary flex-1 px-3 py-2 text-xs">制作ファイルを開く</a>
                                @elseif (($tool['id'] ?? null) === 'resources')
                                    <a href="{{ route('plans.resources.index', $guidancePlan) }}" class="btn-primary flex-1 px-3 py-2 text-xs">関連資料を開く</a>
                                @elseif (($tool['id'] ?? null) === 'guided_execution')
                                    <a href="{{ route('plans.tasks.guided_execution.show', [$guidancePlan, $guidanceTask]) }}" class="btn-primary flex-1 px-3 py-2 text-xs">◎ 方針を決めて実行する</a>
                                @elseif (($tool['id'] ?? null) === 'timer')
                                    <form method="POST" action="{{ route('work_sessions.start') }}" class="flex-1" data-work-start-form>
                                        @csrf
                                        <input type="hidden" name="task_id" value="{{ $guidanceTask->id }}">
                                        <input type="hidden" name="source" value="dashboard">
                                        <button type="submit" class="btn-primary w-full px-3 py-2 text-xs" @if(! $intelligenceAction && $guidanceIndex === 0) data-onboarding-target="today-start" @endif>◷ 集中タイマーで進める</button>
                                    </form>
                                @else
                                    <a href="{{ route('navigation.index', ['plan_id' => $guidancePlan->id]) }}" class="btn-primary flex-1 px-3 py-2 text-xs" @if(! $intelligenceAction && $guidanceIndex === 0) data-onboarding-target="today-start" @endif>実行方法を選ぶ</a>
                                @endif
                            </div>

                            <details class="pk-action-details mt-3" data-guidance-reasons>
                                <summary>なぜこの行動？・進め方</summary>
                                <div class="mt-3 flex flex-wrap gap-2 text-[11px] text-slate-400">
                                    <span>Plan優先度 {{ (int) data_get($guidance, 'priority_evaluation.priority', 3) }} · {{ data_get($guidance, 'priority_evaluation.mode') === 'manual' ? '手動' : '自動' }}</span>
                                    <span>Task優先度 {{ (int) $guidanceTask->priority }}</span>
                                </div>
                                @if ($adaptive && ($tool['id'] ?? null) === 'timer')
                                    <div class="pk-v395-adaptive-note">
                                        <span class="text-cyan-200">Canoviaの提案</span>
                                        <strong>◷ {{ $adaptive->recommendedMinutes }}分</strong>
                                        @if (! empty($adaptive->reasons[0]))
                                            <small>{{ $adaptive->reasons[0] }}</small>
                                        @endif
                                    </div>
                                @endif
                                @if ($tool)
                                    <div class="mt-3 rounded-xl border border-cyan-300/15 bg-cyan-300/[0.04] px-3 py-2.5">
                                        <p class="text-[11px] font-bold text-cyan-200">✦ {{ $tool['name'] }}がおすすめ</p>
                                        <p class="mt-1 text-[11px] leading-4 text-slate-400">{{ $tool['description'] }}</p>
                                    </div>
                                @endif
                            </details>
                        </article>
                    @endforeach
                </div>

                @if ($taskGuidanceDeck->count() + ($intelligenceAction ? 1 : 0) > 1)
                    <p class="mt-2 text-center text-[10px] text-slate-500">横にスワイプすると、ほかのPlanの候補も確認できます。</p>
                @endif
            </section>
        @endif

        @if ($signals->isNotEmpty())
            <section class="page-card canovia-action-home-signals p-3 sm:p-4" aria-labelledby="action-home-signals-title" data-action-home-signals>
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="pk-v18-card-kicker">CHECK</p>
                        <h2 id="action-home-signals-title" class="mt-0.5 text-sm font-black text-slate-200 sm:text-base">確認したい変化</h2>
                    </div>
                    <span class="canovia-action-home-signal-count" data-action-home-signal-count>{{ $signals->count() }}件</span>
                </div>
                <div class="canovia-action-home-signals-track">
                    @foreach ($signals as $signal)
                        <article
                            class="canovia-action-signal is-{{ $signal['severity'] ?? 'info' }}"
                            data-action-home-signal="{{ $signal['kind'] }}"
                            data-action-home-signal-card
                        >
                            @if (! empty($signal['dismiss_url']))
                                <form method="POST" action="{{ $signal['dismiss_url'] }}" class="canovia-action-signal-dismiss-form" data-action-home-dismiss-form>
                                    @csrf
                                    <button
                                        type="submit"
                                        class="canovia-action-signal-dismiss"
                                        aria-label="{{ $signal['dismiss_label'] ?? 'この通知を閉じる' }}"
                                        title="この通知だけ閉じる（実績は残します）"
                                    >×</button>
                                </form>
                            @endif

                            <div class="min-w-0">
                                <p class="canovia-action-signal-kicker">{{ $signal['eyebrow'] }}</p>
                                <h3>{{ $signal['title'] }}</h3>
                                <p>{{ $signal['body'] }}</p>
                                @if ($signal['occurred_at'])
                                    <small>{{ $signal['occurred_at']->diffForHumans() }}</small>
                                @endif
                            </div>
                            <a href="{{ $signal['action_url'] }}" class="canovia-action-signal-link">{{ $signal['action_label'] }} →</a>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($continuity && ! $activeSession)
            <section class="page-card canovia-action-home-continuity p-4 sm:p-5" data-action-home-continuity>
                <div class="min-w-0">
                    <p class="pk-v18-card-kicker">CONTINUE</p>
                    <h2 class="mt-1 text-base font-black text-slate-100">前回の続き</h2>
                    <p class="mt-2 text-xs text-slate-400">{{ $continuity['plan_icon'] ?? '🧭' }} {{ $continuity['plan_title'] }}</p>
                    <p class="mt-1 truncate text-sm font-bold text-slate-200">{{ $continuity['task_title'] }}</p>
                </div>
                @if ($continuity['can_resume_task'] || $continuity['is_active'])
                    <div>
                        @if ($continuity['is_active'])
                            <a href="{{ route('work_sessions.active', $continuity['session_id']) }}" class="btn-secondary px-3 py-2 text-xs">作業へ戻る</a>
                        @else
                            <form method="POST" action="{{ route('work_sessions.start') }}" data-work-start-form>
                                @csrf
                                <input type="hidden" name="task_id" value="{{ $continuity['task_id'] }}">
                                <input type="hidden" name="source" value="dashboard">
                                <button type="submit" class="btn-secondary px-3 py-2 text-xs">続きから開始</button>
                            </form>
                        @endif
                    </div>
                @endif
            </section>
        @endif

        <div class="pk-v18-quick-actions" aria-label="ホームの操作" data-action-home-utilities>
            <a href="{{ route('plans.create') }}" class="pk-v18-action-chip is-primary" data-onboarding-target="create-plan"><span>＋</span> 新しい計画</a>
            <form method="POST" action="{{ route('chat.start', 'review') }}">
                @csrf
                <button type="submit" class="pk-v18-action-chip">計画を更新</button>
            </form>
            <a href="{{ route('calendar.index') }}" class="pk-v18-action-chip">カレンダー</a>
            <a href="{{ route('roadmap.index') }}" class="pk-v18-action-chip">星座で全体を見る</a>
        </div>

        <details class="pk-action-details page-card p-4" data-home-collaboration>
            <summary>共同計画の操作</summary>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p class="max-w-2xl text-xs leading-5 text-slate-400">共同メンバーによる重要な更新は上の「確認したい変化」に表示します。ここでは参加などの管理操作だけ行えます。</p>
                <a href="{{ route('collaboration.join.form') }}" class="btn-secondary px-3 py-2 text-xs">共同計画に参加</a>
            </div>
        </details>

        @if ($dashboard['plan_tabs']->isNotEmpty())
            <section class="page-card pk-v18-section-card p-4 sm:p-5" data-action-home-recent>
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="pk-v18-card-kicker">RECENT</p>
                        <h2 class="text-base font-black text-slate-100 sm:text-lg">最近の動き</h2>
                    </div>
                    <a href="{{ route('timeline.index') }}" class="text-xs font-bold text-sky-300">タイムライン →</a>
                </div>
                <div class="mt-3 space-y-2">
                    @forelse ($recentActivity as $activity)
                        <div class="pk-v18-activity-row">
                            <span class="min-w-0 truncate"><span aria-hidden="true">{{ $activity['plan']->displayIcon() }}</span> {{ $activity['log']->task?->title ?? $activity['log']->task_title_snapshot ?? $activity['plan']->title }}</span>
                            <span>{{ $activity['log']->worked_on?->format('m/d') }} · {{ $activity['log']->actual_minutes }}分</span>
                        </div>
                    @empty
                        <p class="text-xs text-slate-400">まだ作業記録はありません。</p>
                    @endforelse
                </div>
            </section>

            <details class="pk-action-details page-card p-4" data-home-overview>
                <summary>今日の状況</summary>
                <div class="mt-3 grid grid-cols-2 gap-2 lg:grid-cols-4">
                    <div class="metric-card"><p class="text-[11px] text-slate-500">今日の実績</p><p class="mt-1 font-black text-slate-100">{{ $dashboard['today_minutes'] }}分</p></div>
                    <div class="metric-card"><p class="text-[11px] text-slate-500">目安残り</p><p class="mt-1 font-black text-slate-100">{{ $todayRemaining }}分</p></div>
                    <div class="metric-card"><p class="text-[11px] text-slate-500">連続</p><p class="mt-1 font-black text-slate-100">{{ $dashboard['streak_days'] }}日</p></div>
                    <div class="metric-card"><p class="text-[11px] text-slate-500">作業リズム</p><p class="mt-1 font-black text-slate-100">{{ $dashboard['analysis_ready'] ? $state->state->label() : '学習中' }}</p></div>
                </div>
                @if ($calendarWeek)
                    <div class="home-week-strip mt-3">
                        @foreach ($calendarWeek['days'] as $day)
                            <a href="{{ route('calendar.index', ['selected' => $day['date']->format('Y-m-d'), 'date' => $day['date']->format('Y-m-d')]) }}" class="home-week-day {{ $day['is_today'] ? 'is-today' : '' }}"><span>{{ $day['date']->isoFormat('ddd') }}</span><strong>{{ $day['date']->day }}</strong><small>{{ $day['actual_minutes'] }}/{{ $day['available_minutes'] }}分</small></a>
                        @endforeach
                    </div>
                @endif
                <blockquote class="pk-v18-quote-card mt-3">
                    <span aria-hidden="true">“</span>
                    <p>{{ $processMessage }}</p>
                    <small>SAME SKY · BRIGHTER YOU</small>
                </blockquote>
            </details>
        @endif

        @if (app()->isLocal() || config('app.debug'))
            <div class="text-right"><a href="{{ route('dashboard.tools') }}" class="text-sm text-slate-500 hover:text-slate-300">開発ツール</a></div>
        @endif
    </div>
@endsection

@section('offline_snapshot')
@php
    $offlineGuidance = collect($dashboard['guidance_deck'] ?? [])->first();
    $offlineGuidanceTask = data_get($offlineGuidance, 'task');
    $offlineGuidancePlan = data_get($offlineGuidance, 'plan');
    $offlineCurrent = $offlineGuidanceTask ? [
        'task_id' => $offlineGuidanceTask->id,
        'title' => $offlineGuidanceTask->title,
        'status' => $offlineGuidanceTask->status,
        'status_label' => $offlineGuidanceTask->status === 'doing' ? '進行中' : '未着手',
        'progress_percent' => $offlineGuidanceTask->progress_percent,
        'remaining_minutes' => $offlineGuidanceTask->remaining_minutes,
        'next_action_note' => $offlineGuidanceTask->next_action_note,
        'is_current' => true,
    ] : null;
    $offlinePlanTab = $offlineGuidancePlan
        ? collect($dashboard['plan_tabs'])->first(fn ($item) => $item['plan']->id === $offlineGuidancePlan->id)
        : null;
    $offlineSnapshot = [
        'type' => 'dashboard',
        'captured_at' => now()->toIso8601String(),
        'csrf_token' => csrf_token(),
        'plan' => $offlineGuidancePlan ? ['id' => $offlineGuidancePlan->id, 'title' => $offlineGuidancePlan->title] : null,
        'current' => $offlineCurrent,
        'roadmap' => collect(data_get($offlinePlanTab, 'roadmap.nodes', []))
            ->map(fn ($node) => collect($node)->only(['task_id', 'title', 'status', 'status_label', 'progress_percent', 'remaining_minutes', 'next_action_note', 'is_current'])->all())
            ->values()
            ->all(),
        'plans' => collect($dashboard['plan_tabs'])->map(fn ($item) => [
            'id' => $item['plan']->id,
            'title' => $item['plan']->title,
            'status' => $item['progress']['status'],
            'progress_percent' => $item['progress']['weighted_progress_percent'],
            'today_minutes' => $item['today_minutes'],
            'daily_required_minutes' => $item['progress']['daily_required_minutes'],
            'deadline' => $item['plan']->deadline?->format('Y-m-d'),
        ])->values()->all(),
        'home' => [
            'today_minutes' => $dashboard['today_minutes'],
            'daily_required_minutes' => $dashboard['total_daily_required_minutes'],
            'remaining_minutes' => $dashboard['remaining_minutes'],
            'streak_days' => $dashboard['streak_days'],
            'signal_count' => $signals->count(),
            'intelligence_action' => $intelligenceAction ? [
                'kind' => $intelligenceAction['action']->kind,
                'title' => $intelligenceAction['action']->title,
                'intent' => $intelligenceAction['action']->intent,
                'readiness_score' => $intelligenceAction['readiness']->score,
            ] : null,
        ],
        'continuity' => $dashboard['continuity'] ?? null,
    ];
@endphp
<script type="application/json" id="pacekeeper-offline-snapshot">{!! json_encode($offlineSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection
