@extends(in_array(request()->header('X-Canovia-Instant-Navigation'), ['prefetch', 'navigate'], true) ? 'layouts.instant' : 'layouts.app')

@section('title', 'ホーム | Canovia')

@section('content')
    @php
        $state = $dashboard['state'];
        $baseline = $dashboard['baseline'];
        $recommendation = $dashboard['recommendation'];
        $guidanceDeck = $dashboard['guidance_deck'] ?? collect();
        $primaryGuidance = $guidanceDeck->first();
        $activeSession = $dashboard['active_work_session'];
        $todayRemaining = max(0, $dashboard['total_daily_required_minutes'] - $dashboard['today_minutes']);
        $calendarWeek = $dashboard['calendar_week'] ?? null;
        $continuity = $dashboard['continuity'] ?? null;
        $overallProgress = $dashboard['plan_tabs']->isNotEmpty()
            ? (int) round($dashboard['plan_tabs']->avg(fn ($item) => (int) ($item['progress']['weighted_progress_percent'] ?? 0)))
            : 0;
        $overallRoadmapNodes = $dashboard['plan_tabs']
            ->flatMap(fn ($item) => collect($item['roadmap']['nodes'] ?? []))
            ->values();
        $overallCompleted = $overallRoadmapNodes->filter(fn ($node) => ($node['status'] ?? null) === 'done')->count();
        $overallDoing = $overallRoadmapNodes->filter(fn ($node) => ($node['is_current'] ?? false) || ($node['status'] ?? null) === 'doing')->count();
        $overallTodo = $overallRoadmapNodes->filter(fn ($node) => ! in_array(($node['status'] ?? null), ['done', 'cancelled', 'doing'], true) && ! ($node['is_current'] ?? false))->count();
        $nextOverallMilestone = $overallRoadmapNodes->first(fn ($node) => ! in_array(($node['status'] ?? null), ['done', 'cancelled'], true) && ! ($node['is_current'] ?? false));
        $primaryPlan = data_get($primaryGuidance, 'plan') ?? $recommendation?->plan ?? data_get($dashboard['plan_tabs']->first(), 'plan');
        $roadmapUrl = $primaryPlan ? route('roadmap.index', ['plan_id' => $primaryPlan->id]) : route('roadmap.index');
        $processMessage = $dashboard['process_message'] ?? '続けることで、きっとどこかでつながってる。';
    @endphp

    <div id="behaviorDashboard" class="pk-v18-dashboard space-y-5 md:space-y-6" data-guide-target="home-now" data-event-url="{{ route('behavior_events.store') }}" data-navigation-url="{{ route('navigation.index') }}" data-work-started="{{ $activeSession ? 1 : 0 }}" data-onboarding-new-user="{{ $dashboard['plan_tabs']->isEmpty() ? '1' : '0' }}">
        <div class="pk-v22-hero-stage">
            <header class="pk-v18-hero pk-home-heading">
                <div class="pk-v18-hero-copy">
                    <h1>今日も、あなたのペースで。</h1>
                    <p class="pk-v18-hero-lead">小さな一歩が、やがて大きな未来をつくる。</p>
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
            <span class="pk-v18-guide-bubble pk-v22-guide-bubble-layer" aria-hidden="true">今日もいい一歩が<br>待ってるよ！</span>
        </div>

        @if (session('success'))
            <div class="assistant-notice assistant-notice-success">{{ session('success') }}</div>
        @endif
        @if (session('status'))
            <div class="assistant-notice assistant-notice-info">{{ session('status') }}</div>
        @endif

        @if ($activeSession)
            <section class="pk-v18-active-session plan-identity-shell" data-plan-accent="{{ $activeSession->plan?->accentKey() ?? 'sky' }}">
                <div class="min-w-0">
                    <p class="pk-v18-card-kicker">{{ $activeSession->status === 'paused' ? 'PAUSED' : 'IN FOCUS' }}</p>
                    <h2>{{ $activeSession->task?->title ?? '作業中のタスク' }}</h2>
                    <p>{{ $activeSession->plan?->displayIcon() }} {{ $activeSession->plan?->title }}</p>
                </div>
                <a href="{{ route('work_sessions.active', $activeSession) }}" class="btn-primary">作業へ戻る</a>
            </section>
        @endif

        @if ($dashboard['plan_tabs']->isEmpty())
            <section class="empty-state page-card p-8 text-center">
                <div class="text-4xl" aria-hidden="true">✦</div>
                <h2 class="mt-3 text-xl font-black text-slate-100">最初の星を決めよう</h2>
                <p class="mt-2 text-sm text-slate-400">目標がはっきりしていなくても大丈夫。Canoviaに今の気持ちや状況を話すところから始められます。</p>
                <div class="mt-5 flex flex-wrap justify-center gap-2">
                    <a href="{{ route('plans.create') }}" class="btn-primary">Canoviaと始める</a>
                </div>
            </section>
        @else
            @if (! $activeSession && $guidanceDeck->isNotEmpty())
                <section class="pk-v18-recommendation pk-v395-guidance plan-identity-shell" data-plan-accent="{{ data_get($primaryGuidance, 'plan')?->accentKey() ?? 'sky' }}">
                    <div class="pk-v18-recommendation-titlebar">
                        <div class="flex items-center gap-2">
                            <span class="pk-v18-starlight" aria-hidden="true">✦</span>
                            <div>
                                <p class="pk-v18-card-kicker">TODAY'S ROUTE</p>
                                <h2>今日やること</h2>
                            </div>
                        </div>
                        <a href="{{ route('my_plans.index') }}" class="pk-v18-ellipsis" aria-label="計画一覧を開く">•••</a>
                    </div>

                    <div class="pk-v395-guidance-track" aria-label="計画ごとの今日やること">
                        @foreach ($guidanceDeck as $guidanceIndex => $guidance)
                            @php
                                $guidancePlan = $guidance['plan'];
                                $guidanceTask = $guidance['task'];
                                $adaptive = $guidance['adaptive'];
                                $tool = $guidance['recommended_tool'];
                            @endphp
                            <article class="pk-v395-guidance-card plan-identity-shell" data-plan-accent="{{ $guidancePlan->accentKey() }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="plan-identity-chip text-[11px]"><span aria-hidden="true">{{ $guidancePlan->displayIcon() }}</span>{{ $guidancePlan->title }}</p>
                                        <h3 class="mt-2 text-base font-black leading-6 text-white">{{ $guidanceTask->title }}</h3>
                                    </div>
                                    <span class="badge {{ $guidanceIndex === 0 ? 'badge-green' : 'badge-slate' }}">{{ $guidanceIndex === 0 ? '最優先' : 'Plan '.($guidanceIndex + 1) }}</span>
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
                                            <button type="submit" class="btn-primary w-full px-3 py-2 text-xs" @if($guidanceIndex === 0) data-onboarding-target="today-start" @endif>◷ 集中タイマーで進める</button>
                                        </form>
                                    @else
                                        <button type="button" class="btn-primary flex-1 px-3 py-2 text-xs" data-open-dashboard-tab="plan-{{ $guidancePlan->id }}" @if($guidanceIndex === 0) data-onboarding-target="today-start" @endif>
                                            次のActionを見る
                                        </button>
                                    @endif
                                </div>
                                <details class="pk-action-details mt-3" data-guidance-reasons>
                                    <summary>なぜこの行動？・進め方</summary>
                                    <div class="mt-3 flex flex-wrap gap-2 text-[11px] text-slate-400">
                                        <span>
                                            Plan優先度 {{ (int) data_get($guidance, 'priority_evaluation.priority', 3) }}
                                            · {{ data_get($guidance, 'priority_evaluation.mode') === 'manual' ? '手動' : '自動' }}
                                        </span>
                                        <span>Task優先度 {{ (int) $guidanceTask->priority }}</span>
                                        <span>{{ $guidanceTask->status === 'doing' ? '進行中' : '未着手' }}</span>
                                    </div>

                                    @if (data_get($guidance, 'priority_evaluation.mode') === 'auto' && data_get($guidance, 'priority_evaluation.reasons.0'))
                                        <p class="mt-2 text-[11px] leading-4 text-slate-500">
                                            自動判定：{{ data_get($guidance, 'priority_evaluation.reasons.0') }}
                                        </p>
                                    @endif

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

                                    <p class="mt-2 text-xs text-slate-400">PlanとTaskの優先度で決め、Canoviaは進め方を提案します。</p>
                                </details>
                            </article>
                        @endforeach
                    </div>

                    @if ($guidanceDeck->count() > 1)
                        <p class="mt-2 text-center text-[10px] text-slate-500">横にスワイプすると、他のPlanの次Taskも確認できます。</p>
                    @endif
                </section>
            @endif

            <details class="pk-action-details page-card p-4" data-home-overview>
                <summary>進捗・前回の続き・ロードマップ</summary>
                <section class="pk-v18-overview-grid">
                    <article class="pk-v18-progress-card">
                        <a href="{{ route('my_plans.index') }}" class="pk-v18-card-link" aria-label="計画一覧を見る"></a>
                        <div class="pk-v18-card-heading"><h2>進捗</h2><span>›</span></div>
                        <div class="pk-v18-progress-ring" style="--pk-progress: {{ $overallProgress }}%;"><strong>{{ $overallProgress }}<small>%</small></strong></div>
                        <p class="pk-v18-progress-message">{{ $overallProgress >= 80 ? 'ゴールが見えてきた！' : ($overallProgress >= 40 ? 'コツコツ、いい感じ！' : 'ここから一歩ずつ。') }}</p>
                        <div class="pk-v18-progress-stats">
                            <span><b>{{ $overallCompleted }}</b><small>完了</small></span>
                            <span><b>{{ $overallDoing }}</b><small>進行中</small></span>
                            <span><b>{{ $overallTodo }}</b><small>やること</small></span>
                        </div>
                    </article>

                    <div class="pk-v18-overview-stack">
                        <article class="pk-v18-mini-card">
                            @if ($continuity)
                                <div class="pk-v18-mini-card-heading"><span>📖</span><h2>前回の続き</h2><span>›</span></div>
                                <p class="plan-identity-chip mt-2 text-[10px]"><span aria-hidden="true">{{ $continuity['plan_icon'] ?? '🧭' }}</span>{{ $continuity['plan_title'] }}</p>
                                <h3>{{ $continuity['task_title'] }}</h3>
                                @if ($continuity['can_resume_task'] || $continuity['is_active'])
                                    <div class="pk-v18-mini-actions">
                                        @if ($continuity['is_active'])
                                            <a href="{{ route('work_sessions.active', $continuity['session_id']) }}">作業へ戻る →</a>
                                        @else
                                            <form method="POST" action="{{ route('work_sessions.start') }}" data-work-start-form>
                                                @csrf
                                                <input type="hidden" name="task_id" value="{{ $continuity['task_id'] }}">
                                                <input type="hidden" name="source" value="dashboard">
                                                <button type="submit">続きから開始 →</button>
                                            </form>
                                        @endif
                                    </div>
                                @endif
                            @else
                                <div class="pk-v18-mini-card-heading"><span>📖</span><h2>前回の続き</h2><span>›</span></div>
                                <p class="pk-v18-empty-copy">最初の作業を終えると、ここからすぐ再開できます。</p>
                            @endif
                        </article>

                        <a href="{{ $roadmapUrl }}" class="pk-v18-mini-card pk-v18-roadmap-mini">
                            <div class="pk-v18-mini-card-heading"><span>🗺</span><h2>あなたのロードマップ</h2><span>›</span></div>
                            <div class="pk-v18-mini-orbit" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
                            <p><small>次のマイルストーン</small><strong>{{ $nextOverallMilestone['title'] ?? '未来へのルートを確認する' }}</strong></p>
                        </a>
                    </div>
                </section>

                <blockquote class="pk-v18-quote-card">
                    <span aria-hidden="true">“</span>
                    <p>{{ $processMessage }}</p>
                    <small>SAME SKY · BRIGHTER YOU</small>
                </blockquote>
            </details>
        @endif

        @if (($dashboard['pending_plan_updates'] ?? collect())->isNotEmpty())
            <details class="page-card p-4">
                <summary class="cursor-pointer font-bold text-amber-200">まだ計画に反映していない作業が{{ $dashboard['pending_plan_updates']->count() }}件あります</summary>
                <div class="mt-3 space-y-2">
                    @foreach ($dashboard['pending_plan_updates'] as $pendingSession)
                        @if ($pendingSession->plan)
                            <a href="{{ route('plans.review_assistant.show', ['plan' => $pendingSession->plan, 'work_session_id' => $pendingSession->id]) }}" class="flex items-center justify-between gap-3 rounded-xl border border-amber-300/15 bg-slate-950/20 px-4 py-3">
                                <span class="min-w-0"><span class="block truncate font-semibold text-slate-100">{{ $pendingSession->task?->title ?? $pendingSession->plan->title }}</span><span class="mt-1 block text-xs text-slate-400">{{ max(1, (int) ceil(($pendingSession->actual_seconds ?? 0) / 60)) }}分・{{ $pendingSession->ended_at?->format('m/d H:i') }}</span></span>
                                <span class="text-sm font-semibold text-amber-200">反映 →</span>
                            </a>
                        @endif
                    @endforeach
                </div>
            </details>
        @endif

        <div class="pk-v18-quick-actions" aria-label="ホームの操作">
            <a href="{{ route('plans.create') }}" class="pk-v18-action-chip is-primary" data-onboarding-target="create-plan"><span>＋</span> 新しい計画</a>
            <form method="POST" action="{{ route('chat.start', 'review') }}">
                @csrf
                <button type="submit" class="pk-v18-action-chip">計画を更新</button>
            </form>
            <a href="{{ route('calendar.index') }}" class="pk-v18-action-chip">カレンダー</a>
            <a href="{{ route('my_plans.index') }}" class="pk-v18-action-chip">計画一覧</a>
        </div>

        <details class="pk-action-details page-card p-4" data-home-collaboration>
            <summary>共同計画・参加する</summary>
            <section class="page-card overflow-hidden p-4 sm:p-5" aria-labelledby="home-collaboration-title">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="pk-v18-card-kicker">CANOVIA / TOGETHER</p>
                        <h2 id="home-collaboration-title" class="mt-1 text-base font-black text-slate-50 sm:text-lg">共同計画</h2>
                        <p class="mt-1 text-xs leading-5 text-slate-400">同じゴールを、みんなで進める。</p>
                    </div>
                    <a href="{{ route('collaboration.join.form') }}" class="btn-secondary px-3 py-2 text-xs">参加コードを入力</a>
                </div>

                @if (($collaborationPlans ?? collect())->isNotEmpty())
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        @foreach (($collaborationPlans ?? collect())->take(4) as $collaborationItem)
                            @php
                                $collaborationPlan = $collaborationItem['plan'];
                                $collaborationRole = $collaborationItem['role'];
                                $collaborationRoleLabel = match ($collaborationRole) {
                                    'owner' => 'オーナー',
                                    'editor' => '編集者',
                                    default => '閲覧者',
                                };
                            @endphp
                            <a href="{{ route('plans.collaboration.settings', $collaborationPlan) }}" class="group rounded-2xl border border-cyan-300/10 bg-slate-950/35 p-4 transition hover:border-cyan-300/30 hover:bg-cyan-300/[0.04]">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-bold text-slate-100">{{ $collaborationPlan->displayIcon() }} {{ $collaborationPlan->title }}</p>
                                        <p class="mt-1 text-[11px] text-slate-500">{{ $collaborationItem['member_count'] }}人 · {{ $collaborationRoleLabel }}</p>
                                    </div>
                                    <span class="shrink-0 text-sm text-cyan-200 transition group-hover:translate-x-0.5">→</span>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="mt-4 rounded-2xl border border-dashed border-slate-700/80 bg-slate-950/25 p-4">
                        <p class="text-sm font-bold text-slate-200">まだ共同計画はありません</p>
                        <p class="mt-1 text-xs leading-5 text-slate-500">計画のロードマップから共同計画を有効にするか、もらった参加コードを入力できます。</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <a href="{{ $roadmapUrl }}" class="btn-secondary px-3 py-2 text-xs">ロードマップから設定</a>
                            <a href="{{ route('collaboration.join.form') }}" class="btn-secondary px-3 py-2 text-xs">共同計画に参加</a>
                        </div>
                    </div>
                @endif
            </section>
        </details>

        <nav class="pk-v18-plan-tabs overflow-x-auto" aria-label="ダッシュボード表示">
            <div class="flex min-w-max gap-1" role="tablist">
                <button type="button" class="dashboard-tab nav-link nav-link-active" data-dashboard-tab="overall" role="tab" aria-selected="true">全体</button>
                @foreach ($dashboard['plan_tabs'] as $item)
                    <button type="button" class="dashboard-tab nav-link plan-identity-shell" data-plan-accent="{{ $item['plan']->accentKey() }}" data-dashboard-tab="plan-{{ $item['plan']->id }}" data-plan-id="{{ $item['plan']->id }}" role="tab" aria-selected="false"><span aria-hidden="true">{{ $item['plan']->displayIcon() }}</span> {{ $item['plan']->title }}</button>
                @endforeach
            </div>
        </nav>

        <section data-dashboard-panel="overall" class="space-y-4 md:space-y-5">
            @if ($dashboard['plan_tabs']->isNotEmpty())
                <section class="page-card pk-v18-section-card p-4 sm:p-5">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div><p class="pk-v18-card-kicker">YOUR WORLDS</p><h2 class="text-base font-black text-slate-100 sm:text-lg">進行中の計画</h2></div>
                        <p class="text-xs text-slate-400">残り目安 約{{ round($dashboard['remaining_minutes'] / 60, 1) }}時間</p>
                    </div>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($dashboard['plan_tabs'] as $item)
                            <button type="button" class="home-plan-card plan-identity-shell text-left" data-plan-accent="{{ $item['plan']->accentKey() }}" data-open-dashboard-tab="plan-{{ $item['plan']->id }}">
                                <div class="flex items-start justify-between gap-3"><span class="plan-identity-icon" aria-hidden="true">{{ $item['plan']->displayIcon() }}</span><span class="badge badge-slate">{{ $item['progress']['status'] }}</span></div>
                                <h3 class="mt-2 line-clamp-2 font-black text-slate-100">{{ $item['plan']->title }}</h3>
                                <div class="mt-2 flex items-end justify-between gap-3"><span class="text-xl font-black text-slate-50">{{ $item['progress']['weighted_progress_percent'] }}%</span><span class="text-[11px] text-slate-400">期限 {{ $item['plan']->deadline?->format('m/d') ?? '未設定' }}</span></div>
                            </button>
                        @endforeach
                    </div>
                </section>

                @if ($calendarWeek)
                    <section class="page-card pk-v18-section-card p-4 sm:p-5">
                        <div class="flex items-center justify-between gap-3"><div><p class="pk-v18-card-kicker">THIS WEEK</p><h2 class="text-base font-black text-slate-100 sm:text-lg">今週の見通し</h2></div><a href="{{ route('calendar.index') }}" class="text-xs font-bold text-sky-300">カレンダー →</a></div>
                        <div class="home-week-strip mt-3">
                            @foreach ($calendarWeek['days'] as $day)
                                <a href="{{ route('calendar.index', ['selected' => $day['date']->format('Y-m-d'), 'date' => $day['date']->format('Y-m-d')]) }}" class="home-week-day {{ $day['is_today'] ? 'is-today' : '' }}"><span>{{ $day['date']->isoFormat('ddd') }}</span><strong>{{ $day['date']->day }}</strong><small>{{ $day['actual_minutes'] }}/{{ $day['available_minutes'] }}分</small></a>
                            @endforeach
                        </div>
                    </section>
                @endif

                <section class="page-card pk-v18-section-card p-4 sm:p-5">
                    <div class="flex items-center justify-between gap-3"><div><p class="pk-v18-card-kicker">RECENT ORBITS</p><h2 class="text-base font-black text-slate-100 sm:text-lg">最近の動き</h2></div><a href="{{ route('timeline.index') }}" class="text-xs font-bold text-sky-300">すべて見る →</a></div>
                    <div class="mt-3 space-y-2">
                        @forelse ($dashboard['recent_activity'] ?? [] as $activity)
                            <div class="pk-v18-activity-row"><span class="min-w-0 truncate"><span aria-hidden="true">{{ $activity['plan']->displayIcon() }}</span> {{ $activity['log']->task?->title ?? $activity['log']->task_title_snapshot ?? $activity['plan']->title }}</span><span>{{ $activity['log']->worked_on?->format('m/d') }} · {{ $activity['log']->actual_minutes }}分</span></div>
                        @empty
                            <p class="text-xs text-slate-400">まだ作業記録はありません。</p>
                        @endforelse
                    </div>
                </section>

                <details class="page-card pk-v18-section-card p-4 sm:p-5">
                    <summary class="cursor-pointer text-sm font-bold text-slate-200">もう少し見る</summary>
                    <div class="mt-3 grid grid-cols-2 gap-2 lg:grid-cols-4">
                        <div class="metric-card"><p class="text-[11px] text-slate-500">今日の実績</p><p class="mt-1 font-black text-slate-100">{{ $dashboard['today_minutes'] }}分</p></div>
                        <div class="metric-card"><p class="text-[11px] text-slate-500">目安残り</p><p class="mt-1 font-black text-slate-100">{{ $todayRemaining }}分</p></div>
                        <div class="metric-card"><p class="text-[11px] text-slate-500">連続</p><p class="mt-1 font-black text-slate-100">{{ $dashboard['streak_days'] }}日</p></div>
                        <div class="metric-card"><p class="text-[11px] text-slate-500">作業リズム</p><p class="mt-1 font-black text-slate-100">{{ $dashboard['analysis_ready'] ? $state->state->label() : '学習中' }}</p></div>
                    </div>
                </details>
            @endif
        </section>


        @foreach ($dashboard['plan_tabs'] as $item)
            @php
                $planRecommendation = $item['recommendation'];
                $planCanEdit = (bool) ($item['can_edit'] ?? false);
                $hubCurrentTask = $item['hub_current_task'] ?? null;
                $hubTasks = collect($item['hub_tasks'] ?? []);
                $executionTools = collect($item['execution_tools'] ?? []);
                $primaryExecutionTool = $item['primary_execution_tool'] ?? null;
                $recentEvidence = collect($item['recent_evidence'] ?? []);
                $categoryProfile = $item['category_profile'];
                $surfaceModules = collect($item['surface_modules'] ?? []);
                $activeTaskCount = $item['plan']->tasks
                    ->filter(fn ($task) => ! in_array($task->status, ['done', 'cancelled'], true) && (int) $task->progress_percent < 100)
                    ->count();
            @endphp
            <section data-dashboard-panel="plan-{{ $item['plan']->id }}" class="hidden space-y-4 md:space-y-6">
                <section class="page-card pk-v18-section-card p-4 sm:p-5 plan-identity-shell" data-plan-accent="{{ $item['plan']->accentKey() }}">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="plan-identity-icon" aria-hidden="true">{{ $item['plan']->displayIcon() }}</span>
                            <div class="min-w-0">
                                <p class="text-xs font-bold text-slate-400">{{ $categoryProfile->label }}・{{ $item['progress']['status'] }}・進捗 {{ $item['progress']['weighted_progress_percent'] }}%</p>
                                <h2 class="mt-1 text-lg font-black text-slate-100 sm:text-xl">{{ $item['plan']->title }}</h2>
                                @if (filled($item['plan']->description))
                                    <details class="pk-action-details mt-2"><summary>計画の説明</summary><p class="mt-2 max-w-3xl whitespace-pre-line text-sm leading-6 text-slate-300">{{ $item['plan']->description }}</p></details>
                                @endif
                                <p class="mt-2 text-xs text-slate-400">期限 {{ $item['plan']->deadline?->format('Y/m/d') ?? '未設定' }}・残り目安 約{{ round($item['progress']['remaining_minutes'] / 60, 1) }}時間</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($planCanEdit && (int) $item['plan']->user_id === (int) auth()->id())
                                <a href="{{ route('plans.edit', $item['plan']) }}#plan-design" class="btn-secondary px-3 py-2 text-xs">🎨 デザイン</a>
                            @endif
                            <a href="{{ route('plans.show', $item['plan']) }}" class="btn-secondary px-3 py-2 text-xs">詳細</a>
                        </div>
                    </div>
                </section>

                @foreach ($surfaceModules as $surface)
                    @include('dashboard.surfaces.disclosure', ['surface' => $surface])
                @endforeach
            </section>
        @endforeach

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
        ],
        'continuity' => $dashboard['continuity'] ?? null,
    ];
@endphp
<script type="application/json" id="pacekeeper-offline-snapshot">{!! json_encode($offlineSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection
