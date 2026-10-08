@extends('layouts.app')

@section('title', 'Overview | Canovia')

@section('content')
@php
    $primaryPresentation = $primaryIntelligence ?? null;
    $guidance = is_array($primaryGuidance ?? null) ? $primaryGuidance : null;
    $guidancePlan = data_get($guidance, 'plan');
    $guidanceTask = data_get($guidance, 'task');
    $guidanceTool = data_get($guidance, 'recommended_tool');
    $guidanceAdaptive = data_get($guidance, 'adaptive');
    $modeSummaries = collect([
        $studySummary ?? [],
        $developmentSummary ?? [],
        $careerSummary ?? [],
    ]);
@endphp

<div class="mx-auto max-w-7xl space-y-5" data-overview-workspace>
    <section class="page-card overflow-hidden p-0">
        <div class="border-b border-white/8 bg-gradient-to-r from-violet-300/[0.07] via-slate-950/20 to-transparent px-5 py-5 sm:px-6">
            <div class="max-w-3xl">
                <p class="text-[10px] font-black uppercase tracking-[0.2em] text-violet-300">OVERVIEW</p>
                <h1 class="mt-2 text-2xl font-black text-slate-50 sm:text-3xl">今、Canovia全体で何を優先するか。</h1>
                <p class="mt-2 text-sm leading-6 text-slate-400">
                    専門Workspaceの詳細は持ち込まず、最優先Action・Modeの現在地・Inbox・重要な変化だけを集約します。
                </p>
            </div>
        </div>

        <nav class="flex gap-2 overflow-x-auto px-5 py-3 sm:px-6" aria-label="Overview navigation">
            <a href="#overview-current-action" class="badge badge-slate whitespace-nowrap">Current Action</a>
            <a href="#overview-modes" class="badge badge-slate whitespace-nowrap">Modes</a>
            <a href="#overview-inbox" class="badge badge-slate whitespace-nowrap">Inbox</a>
            <a href="#overview-changes" class="badge badge-slate whitespace-nowrap">Changes</a>
        </nav>
    </section>

    @if (($firstUseModeChoices ?? collect())->isNotEmpty())
        <section class="page-card border-violet-300/15 bg-violet-300/[0.025] p-5 sm:p-6" data-overview-first-use-workspaces>
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-300">CHOOSE A WORKSPACE</p>
                <h2 class="mt-1 text-xl font-black text-slate-50">最初は、目的に近い入口を選ぶ</h2>
                <p class="mt-2 text-sm leading-6 text-slate-400">
                    Workspaceは別アプリではありません。同じCanoviaの中で、目的に合ったState・Evidence・Actionの流れへ入ります。
                </p>
            </div>

            <div class="mt-5 grid gap-3 md:grid-cols-2">
                @foreach ($firstUseModeChoices as $choice)
                    <a
                        href="{{ $choice['url'] }}"
                        class="rounded-2xl border border-white/8 bg-slate-950/25 p-4 transition hover:border-white/15 hover:bg-slate-950/40"
                        data-overview-first-use-workspace="{{ $choice['key'] }}"
                    >
                        <p class="text-[10px] font-black uppercase tracking-[0.14em] {{ $choice['key'] === 'study' ? 'text-amber-300' : ($choice['key'] === 'career' ? 'text-emerald-300' : 'text-cyan-300') }}">
                            {{ strtoupper($choice['key']) }}
                        </p>
                        <h3 class="mt-2 text-base font-black text-slate-100">{{ $choice['label'] }} Workspace</h3>
                        <p class="mt-2 text-xs leading-5 text-slate-500">{{ $choice['description'] }}</p>
                        <span class="mt-4 inline-flex text-xs font-black {{ $choice['key'] === 'study' ? 'text-amber-300' : ($choice['key'] === 'career' ? 'text-emerald-300' : 'text-cyan-300') }}">このWorkspaceから始める →</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <section id="overview-current-action" class="page-card border-violet-300/15 p-5 sm:p-6" data-overview-primary-action>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-violet-300">GLOBAL CURRENT ACTION</p>
                <h2 class="mt-1 text-lg font-black text-slate-50">今いちばん優先すること</h2>
            </div>
            <span class="badge badge-green">最優先</span>
        </div>

        @if ($primaryPresentation)
            @php
                $domainLabel = match ($primaryPresentation->domain->value) {
                    'study' => '学習',
                    'development' => '開発',
                    'career' => 'Career',
                    default => 'Overview',
                };
                $workspaceUrl = match ($primaryPresentation->domain->value) {
                    'study' => route('workspace.study.index', ['plan_id' => $primaryPresentation->plan->id]),
                    'development' => route('workspace.development.index', ['plan_id' => $primaryPresentation->plan->id]),
                    'career' => route('workspace.career.index', ['plan_id' => $primaryPresentation->plan->id]),
                    default => route('workspace.overview.index'),
                };
            @endphp

            <div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1.25fr)_minmax(17rem,0.75fr)]">
                <article class="rounded-3xl border border-white/8 bg-slate-950/30 p-5">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="plan-identity-chip text-[11px]">
                            <span aria-hidden="true">{{ $primaryPresentation->plan->displayIcon() }}</span>
                            {{ $primaryPresentation->plan->title }}
                        </span>
                        <span class="badge badge-slate">{{ $domainLabel }}</span>
                    </div>
                    <h3 class="mt-3 text-xl font-black text-slate-50">{{ $primaryPresentation->action->title }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-400">{{ $primaryPresentation->action->intent }}</p>

                    <div class="mt-5 flex flex-wrap gap-2">
                        @if ($primaryPresentation->actionMethod === 'POST')
                            @if ($canEditPrimaryIntelligence)
                                <form method="POST" action="{{ $primaryPresentation->actionUrl }}">
                                    @csrf
                                    <button type="submit" class="btn-primary min-h-11 px-4">{{ $primaryPresentation->actionLabel }}</button>
                                </form>
                            @endif
                        @else
                            <a href="{{ $primaryPresentation->actionUrl }}" class="btn-primary min-h-11 px-4">{{ $primaryPresentation->actionLabel }}</a>
                        @endif
                        <a href="{{ $workspaceUrl }}" class="btn-secondary min-h-11 px-4">{{ $domainLabel }}Workspaceを開く</a>
                    </div>
                </article>

                <aside class="rounded-3xl border border-white/8 bg-slate-950/25 p-5">
                    <p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">{{ $primaryPresentation->readinessLabel }}</p>
                    <p class="mt-2 text-3xl font-black text-slate-100">{{ $primaryPresentation->readinessDisplay() }}</p>
                    @if ($primaryPresentation->hasDistinctStateDisplay())
                        <p class="mt-1 text-xs font-bold text-slate-400">{{ $primaryPresentation->stateLabel }}</p>
                    @endif
                    <div class="mt-4 border-t border-white/8 pt-4">
                        <p class="text-[10px] font-black uppercase tracking-[0.14em] text-amber-200">BIGGEST GAP</p>
                        <p class="mt-1 text-sm font-black text-slate-100">{{ $primaryPresentation->gapLabel }}</p>
                    </div>
                </aside>
            </div>
        @elseif ($guidance && $guidancePlan && $guidanceTask)
            <div class="mt-5 rounded-3xl border border-white/8 bg-slate-950/30 p-5">
                <span class="plan-identity-chip text-[11px]">
                    <span aria-hidden="true">{{ $guidancePlan->displayIcon() }}</span>
                    {{ $guidancePlan->title }}
                </span>
                <h3 class="mt-3 text-xl font-black text-slate-50">{{ $guidanceTask->title }}</h3>
                <p class="mt-2 text-xs text-slate-500">
                    {{ $guidanceTask->status === 'doing' ? '進行中' : '未着手' }}
                    @if (($guidanceTool['id'] ?? null) === 'timer')
                        · 目安 {{ $guidanceAdaptive?->recommendedMinutes ?? (int) ($guidanceTask->remaining_minutes ?? 0) }}分
                    @endif
                </p>

                <div class="mt-5 flex flex-wrap gap-2">
                    @if (($guidanceTool['id'] ?? null) === 'study_activity')
                        <a href="{{ route('plans.tasks.study_activity.show', [$guidancePlan, $guidanceTask]) }}" class="btn-primary min-h-11 px-4">{{ data_get($guidanceTool, 'activity.action_label', '学習方法で進める') }}</a>
                    @elseif (($guidanceTool['id'] ?? null) === 'ai_practice')
                        <a href="{{ route('plans.tasks.study_practice.show', [$guidancePlan, $guidanceTask]) }}" class="btn-primary min-h-11 px-4">AI演習で進める</a>
                    @elseif (($guidanceTool['id'] ?? null) === 'career_workspace')
                        <a href="{{ route('plans.career.index', $guidancePlan) }}" class="btn-primary min-h-11 px-4">Careerで進める</a>
                    @elseif (($guidanceTool['id'] ?? null) === 'artifacts')
                        <a href="{{ route('plans.artifacts.index', $guidancePlan) }}" class="btn-primary min-h-11 px-4">制作ファイルを開く</a>
                    @elseif (($guidanceTool['id'] ?? null) === 'resources')
                        <a href="{{ route('plans.resources.index', $guidancePlan) }}" class="btn-primary min-h-11 px-4">関連資料を開く</a>
                    @elseif (($guidanceTool['id'] ?? null) === 'guided_execution')
                        <a href="{{ route('plans.tasks.guided_execution.show', [$guidancePlan, $guidanceTask]) }}" class="btn-primary min-h-11 px-4">方針を決めて実行する</a>
                    @elseif (($guidanceTool['id'] ?? null) === 'timer')
                        <form method="POST" action="{{ route('work_sessions.start') }}">
                            @csrf
                            <input type="hidden" name="task_id" value="{{ $guidanceTask->id }}">
                            <input type="hidden" name="source" value="overview">
                            <button type="submit" class="btn-primary min-h-11 px-4">集中タイマーで進める</button>
                        </form>
                    @else
                        <a href="{{ route('navigation.index', ['plan_id' => $guidancePlan->id]) }}" class="btn-primary min-h-11 px-4">実行方法を選ぶ</a>
                    @endif
                    <a href="{{ route('plans.show', $guidancePlan) }}" class="btn-secondary min-h-11 px-4">Planを見る</a>
                </div>
            </div>
        @else
            <div class="mt-5 rounded-3xl border border-dashed border-white/10 p-7 text-center">
                <p class="text-sm font-black text-slate-300">まだ優先Actionはありません。</p>
                <p class="mt-2 text-xs leading-5 text-slate-500">Planを作ると、Canoviaが全体の優先順位から次の行動を選びます。</p>
                <a href="{{ route('plans.create') }}" class="btn-primary mt-4 inline-flex min-h-11 items-center px-4">Planを作る</a>
            </div>
        @endif
    </section>

    @if (($intelligenceChanges ?? collect())->isNotEmpty())
        <div class="space-y-3" data-overview-intelligence-changes>
            <div class="px-1">
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-emerald-300">INTELLIGENCE CHANGES</p>
                <h2 class="mt-1 text-lg font-black text-slate-50">Evidenceで判断がどう変わったか</h2>
            </div>
            @foreach ($intelligenceChanges as $change)
                @include('intelligence.partials.state-change', [
                    'feedback' => $change,
                ])
            @endforeach
        </div>
    @endif

    <section id="overview-modes" class="grid gap-4 lg:grid-cols-3" data-overview-mode-summaries>
        @foreach ($modeSummaries as $summary)
            @php
                $mode = (string) ($summary['mode'] ?? '');
                $isStudy = $mode === 'study';
                $isCareer = $mode === 'career';
                $plan = $summary['plan'] ?? null;
                $presentation = $summary['presentation'] ?? null;
                $setupNeeded = (bool) ($summary['setup_needed'] ?? true);
                $empty = (bool) ($summary['empty'] ?? true);
                $workspaceUrl = (string) ($summary['workspace_url'] ?? '#');
                $modeLabel = match ($mode) {
                    'study' => '学習',
                    'development' => '開発',
                    'career' => 'Career',
                    default => 'Mode',
                };
                $readinessLabel = match ($mode) {
                    'study' => 'Exam Readiness',
                    'development' => 'Release Readiness',
                    'career' => 'Process Readiness',
                    default => 'Readiness',
                };
                $accentText = $isStudy
                    ? 'text-amber-300'
                    : ($isCareer ? 'text-emerald-300' : 'text-cyan-300');
            @endphp

            <article class="page-card p-5 sm:p-6" data-overview-mode="{{ $mode }}" data-overview-mode-setup-needed="{{ $setupNeeded ? 'true' : 'false' }}">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.16em] {{ $accentText }}">{{ strtoupper($mode) }} MODE</p>
                        <h2 class="mt-1 text-lg font-black text-slate-50">{{ $modeLabel }}の現在地</h2>
                    </div>
                    <a href="{{ $workspaceUrl }}" class="text-xs font-bold {{ $accentText }}">Workspace →</a>
                </div>

                @if ($empty)
                    <div class="mt-5 rounded-2xl border border-dashed border-white/10 p-5">
                        <p class="text-sm font-black text-slate-300">{{ $modeLabel }}Planはまだありません。</p>
                        <p class="mt-2 text-xs text-slate-500">専用Workspaceから開始できます。</p>
                    </div>
                @elseif ($setupNeeded)
                    <div class="mt-5 rounded-2xl border border-white/8 bg-slate-950/25 p-5">
                        <span class="plan-identity-chip text-[11px]">
                            <span aria-hidden="true">{{ $plan->displayIcon() }}</span>
                            {{ $plan->title }}
                        </span>
                        <p class="mt-4 text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">{{ $readinessLabel }}</p>
                        @if ($isStudy && is_array($summary['official_exam_reference'] ?? null))
                            <p class="mt-1 text-xl font-black text-slate-100">理解度の確認待ち</p>
                            <p class="mt-2 text-xs leading-5 text-slate-500">
                                IPAの公式試験範囲は公開済みです。Canoviaへの個別登録がなくても試験範囲は未確定ではありません。
                                現在の理解度はまだ未測定です。
                            </p>
                            @if ($presentation?->action)
                                <p class="mt-3 text-xs font-black text-amber-200" data-overview-official-study-action>{{ $presentation->action->title }}</p>
                            @endif
                        @else
                            <p class="mt-1 text-xl font-black text-slate-100">セットアップ中</p>
                            <p class="mt-2 text-xs leading-5 text-slate-500">
                                {{ $isStudy ? 'Canoviaに個別の学習範囲はまだ登録されていません。' : ($isCareer ? 'Career判断に使える現実情報がまだありません。' : 'Release判断に使えるDevelopment Evidenceがまだありません。') }}
                            </p>
                        @endif
                    </div>
                @elseif ($presentation)
                    <div class="mt-5 grid gap-3 sm:grid-cols-[0.72fr_1.28fr]">
                        <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                            <span class="plan-identity-chip text-[11px]">
                                <span aria-hidden="true">{{ $plan->displayIcon() }}</span>
                                {{ $plan->title }}
                            </span>
                            <p class="mt-4 text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">{{ $readinessLabel }}</p>
                            <p class="mt-1 text-2xl font-black text-slate-100">{{ $presentation->readinessDisplay() }}</p>
                            <p class="mt-1 text-xs font-bold text-slate-400">{{ $presentation->stateLabel }}</p>
                        </div>
                        <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-amber-200">BIGGEST GAP</p>
                            <p class="mt-1 text-sm font-black text-slate-100">{{ $presentation->gapLabel }}</p>
                            <p class="mt-4 text-[10px] font-black uppercase tracking-[0.14em] text-violet-300">CURRENT ACTION</p>
                            <p class="mt-1 text-sm font-black text-slate-100">{{ $presentation->action->title }}</p>
                        </div>
                    </div>
                @endif
            </article>
        @endforeach
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        <section id="overview-inbox" class="page-card p-5 sm:p-6" data-overview-inbox>
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-sky-300">INBOX</p>
                    <h2 class="mt-1 text-lg font-black text-slate-50">まだ整理していないもの</h2>
                </div>
                <span class="badge {{ $pendingInboxCount > 0 ? 'badge-green' : 'badge-slate' }}">{{ $pendingInboxCount }}件</span>
            </div>

            <div class="mt-4 space-y-2">
                @forelse ($pendingInboxItems as $item)
                    <article class="rounded-xl border border-white/8 bg-slate-950/20 p-3" data-overview-inbox-item="{{ $item->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <p class="min-w-0 truncate text-sm font-bold text-slate-200">{{ $item->displayTitle() }}</p>
                            <span class="text-[10px] text-slate-600">{{ $item->sourceLabel() }}</span>
                        </div>
                        @if ($item->plan)
                            <p class="mt-1 text-[10px] text-slate-600">{{ $item->plan->displayIcon() }} {{ $item->plan->title }}</p>
                        @endif
                    </article>
                @empty
                    <p class="rounded-xl border border-dashed border-white/8 p-4 text-xs text-slate-500">未整理のInboxはありません。</p>
                @endforelse
            </div>

            <a href="{{ route('inbox.index') }}" class="btn-secondary mt-4 inline-flex min-h-10 items-center px-3 text-xs">Inboxを開く</a>
        </section>

        <section id="overview-changes" class="page-card p-5 sm:p-6" data-overview-important-changes>
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-rose-300">CHANGES</p>
                    <h2 class="mt-1 text-lg font-black text-slate-50">確認したい変化</h2>
                </div>
                <span class="badge {{ $importantSignals->isNotEmpty() ? 'badge-green' : 'badge-slate' }}">{{ $importantSignals->count() }}件</span>
            </div>

            <div class="mt-4 space-y-2">
                @forelse ($importantSignals as $signal)
                    <article class="rounded-xl border border-white/8 bg-slate-950/20 p-3" data-overview-change="{{ $signal['kind'] ?? 'change' }}">
                        <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-600">{{ $signal['eyebrow'] ?? 'CHANGE' }}</p>
                        <p class="mt-1 text-sm font-bold text-slate-200">{{ $signal['title'] }}</p>
                        <p class="mt-1 text-[11px] leading-5 text-slate-500">{{ $signal['body'] }}</p>
                        <a href="{{ $signal['action_url'] }}" class="mt-2 inline-flex text-xs font-bold text-sky-300">{{ $signal['action_label'] }} →</a>
                    </article>
                @empty
                    <p class="rounded-xl border border-dashed border-white/8 p-4 text-xs text-slate-500">今すぐ確認が必要な変化はありません。</p>
                @endforelse
            </div>
        </section>
    </div>
</div>
@endsection
