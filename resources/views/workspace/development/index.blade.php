@extends('layouts.app')

@section('title', '開発 Workspace | Canovia')

@section('content')
@php
    $presentation = $intelligencePresentation ?? null;
    $adaptive = $developmentAdaptiveAction ?? null;
    $state = $adaptive?->intelligence?->state;
    $readiness = $adaptive?->intelligence?->readiness;
    $metrics = $state?->metrics ?? [];
    $facts = $state?->facts ?? [];
    $focusState = data_get($facts, 'focus_task_state');
    $focusState = is_array($focusState) ? $focusState : [];
    $gates = is_array(data_get($readiness?->components, 'gates'))
        ? data_get($readiness->components, 'gates')
        : [];
    $gateLabels = [
        'implementation' => '実装',
        'ci' => 'CI / Test',
        'review' => 'Review',
        'merge' => 'Merge',
        'deploy' => 'Production Deploy',
        'verification' => '実機・本番確認',
        'spec_sync' => '仕様同期',
    ];
    $statusLabels = [
        'passed' => '確認済み',
        'failed' => '問題あり',
        'pending' => '進行中',
        'unknown' => '未確認',
    ];
    $recentActions = collect(data_get($intelligenceHistory ?? [], 'actions', []));
    $recentDecisions = collect(data_get($intelligenceHistory ?? [], 'decisions', []));
    $recentStates = collect(data_get($intelligenceHistory ?? [], 'states', []));
    $shortSha = trim((string) data_get($focusState, 'head_sha', ''));
    $shortSha = $shortSha !== '' ? mb_substr($shortSha, 0, 10) : null;
@endphp

<div class="mx-auto max-w-7xl space-y-5" data-development-workspace>
    <section class="page-card overflow-hidden p-0">
        <div class="border-b border-white/8 bg-gradient-to-r from-cyan-300/[0.08] via-slate-950/20 to-transparent px-5 py-5 sm:px-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-cyan-300">DEVELOPMENT WORKSPACE</p>
                    <h1 class="mt-2 text-2xl font-black text-slate-50 sm:text-3xl">Releaseできる状態かを、Evidenceから判断する。</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-400">
                        GitHub Evidenceと明示確認から、Release Readiness・最大Gate・Current Actionを一つの流れで見ます。
                    </p>
                </div>

                @if ($developmentPlans->isNotEmpty())
                    <form method="GET" action="{{ route('workspace.development.index') }}" class="min-w-0 rounded-2xl border border-white/8 bg-slate-950/35 p-3 lg:w-[22rem]">
                        <label for="development-workspace-plan" class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">DEVELOPMENT PLAN</label>
                        <div class="mt-2 flex gap-2">
                            <select id="development-workspace-plan" name="plan_id" class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-950/70 px-3 py-2 text-sm font-bold text-slate-100">
                                @foreach ($developmentPlans as $developmentPlan)
                                    <option value="{{ $developmentPlan->id }}" @selected($plan && (int) $plan->id === (int) $developmentPlan->id)>
                                        {{ $developmentPlan->title }}
                                    </option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn-secondary min-h-10 px-3 text-xs">表示</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        <nav class="flex gap-2 overflow-x-auto px-5 py-3 sm:px-6" aria-label="開発Workspace navigation">
            <a href="#development-current-action" class="badge badge-slate whitespace-nowrap">Current Action</a>
            <a href="#development-readiness" class="badge badge-slate whitespace-nowrap">Release Readiness</a>
            <a href="#development-quality-gates" class="badge badge-slate whitespace-nowrap">Quality Gates</a>
            @if ($plan)
                <a href="{{ route('github_workflow.index', ['plan_id' => $plan->id]) }}#github-workflow-board" class="badge badge-slate whitespace-nowrap">GitHub</a>
                <a href="{{ route('github_workflow.index', ['plan_id' => $plan->id]) }}#development-intelligence" class="badge badge-slate whitespace-nowrap">Evidence</a>
            @else
                <span class="badge badge-slate whitespace-nowrap opacity-45" aria-disabled="true">GitHub</span>
                <span class="badge badge-slate whitespace-nowrap opacity-45" aria-disabled="true">Evidence</span>
            @endif
            <a href="#development-history" class="badge badge-slate whitespace-nowrap">History</a>
        </nav>
    </section>

    @if (! $plan)
        <section class="page-card border-dashed border-cyan-300/25 p-7 sm:p-9" data-development-workspace-no-plan>
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-xs font-black uppercase tracking-[0.16em] text-cyan-300">START DEVELOPMENT WORKSPACE</p>
                <h2 class="mt-2 text-2xl font-black text-slate-50">まず、開発Planを一つ作る</h2>
                <p class="mt-3 text-sm leading-6 text-slate-400">
                    Development WorkspaceはPlanごとのGitHub EvidenceとRelease Quality Gateを扱います。
                    開発Planを作れば、Repository / PR / Issueを現実の状態としてつなげられます。
                </p>
                <a href="{{ route('plans.create') }}" class="btn-primary mt-5 inline-flex min-h-11 items-center px-4">開発Planを作る</a>
            </div>
        </section>
    @elseif (! $hasReleaseEvidence)
        <section class="page-card border-cyan-300/20 bg-cyan-300/[0.025] p-6 sm:p-8" data-development-workspace-github-first>
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(18rem,0.8fr)] lg:items-center">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-cyan-300">FIRST EVIDENCE</p>
                    <h2 class="mt-2 text-2xl font-black text-slate-50">GitHub EvidenceをTaskへつなぐ</h2>
                    <p class="mt-3 text-sm leading-6 text-slate-400">
                        Release Readinessを意味のある状態にするには、Repository / PR / Issue / Branchなどを対象Taskへ結び、
                        authoritativeなGitHub状態をEvidenceとして観測する必要があります。
                    </p>
                    <div class="mt-5 flex flex-wrap gap-2">
                        <a href="{{ route('github_workflow.index', ['plan_id' => $plan->id]) }}" class="btn-primary min-h-11 px-4">GitHubを開く</a>
                        <a href="{{ route('plans.show', $plan) }}" class="btn-secondary min-h-11 px-4">Planを見る</a>
                    </div>
                </div>
                <div class="rounded-2xl border border-white/8 bg-slate-950/35 p-5">
                    <p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">RELEASE LOOP</p>
                    <p class="mt-2 text-lg font-black text-slate-100">{{ $plan->displayIcon() }} {{ $plan->title }}</p>
                    <div class="mt-4 space-y-3 text-xs text-slate-500">
                        <p>1. GitHub項目をTaskへリンク</p>
                        <p>2. CI / Review / Merge / DeployをEvidence化</p>
                        <p>3. Verification / Spec Syncを明示確認</p>
                        <p>4. Release ReadinessとActionが更新</p>
                    </div>
                </div>
            </div>
        </section>
    @else
        <section id="development-readiness" class="page-card border-cyan-300/15 p-5 sm:p-6" data-development-workspace-readiness>
            <div class="grid gap-5 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                <div class="rounded-3xl border border-cyan-300/15 bg-gradient-to-br from-cyan-300/[0.08] to-transparent p-5 sm:p-6">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-cyan-300">RELEASE READINESS</p>
                    <div class="mt-3 flex items-end gap-3">
                        <strong class="text-5xl font-black tracking-tight text-slate-50">{{ $presentation?->readinessDisplay() ?? '未判定' }}</strong>
                        <span class="pb-1 text-sm font-bold text-slate-400">{{ $presentation?->stateLabel }}</span>
                    </div>
                    <p class="mt-3 text-xs leading-5 text-slate-500">
                        Confidence {{ $presentation?->confidenceDisplay() ?? '—' }}
                        · Evidence {{ count($state?->evidenceReferences ?? []) }}件
                    </p>

                    @if ($developmentFocusTask)
                        <div class="mt-5 rounded-2xl border border-white/8 bg-slate-950/30 p-4">
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">RELEASE CANDIDATE</p>
                            <p class="mt-1 text-base font-black text-slate-100">{{ $developmentFocusTask->title }}</p>
                            <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[10px] text-slate-600">
                                @if (data_get($focusState, 'pull_request_number'))
                                    <span>PR #{{ (int) data_get($focusState, 'pull_request_number') }}</span>
                                @endif
                                @if (data_get($focusState, 'branch'))
                                    <span>{{ data_get($focusState, 'branch') }}</span>
                                @endif
                                @if ($shortSha)
                                    <span>{{ $shortSha }}</span>
                                @endif
                                @if (data_get($focusState, 'deployment_environment'))
                                    <span>Deploy: {{ data_get($focusState, 'deployment_environment') }}</span>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>

                <div class="grid grid-cols-2 gap-3">
                    @foreach ($presentation?->metrics ?? [] as $metric)
                        <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                            <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">{{ $metric['detail'] ?? $metric['label'] }}</p>
                            <p class="mt-2 text-2xl font-black text-slate-100">{{ $metric['value'] }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ $metric['label'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            @if ((bool) data_get($focusState, 'verification_stale') || (bool) data_get($focusState, 'spec_sync_stale'))
                <div class="mt-4 rounded-2xl border border-amber-300/15 bg-amber-300/[0.04] p-4 text-xs leading-5 text-amber-100" data-development-workspace-stale-warning>
                    @if ((bool) data_get($focusState, 'verification_stale'))
                        <p>Production Deployが変わったため、実機・本番確認を現在Releaseに対してやり直す必要があります。</p>
                    @endif
                    @if ((bool) data_get($focusState, 'spec_sync_stale'))
                        <p>実装SHAが変わったため、現在実装に対する仕様同期を再確認する必要があります。</p>
                    @endif
                </div>
            @endif
        </section>

        @include('intelligence.partials.state-change', [
            'feedback' => $intelligenceStateChange ?? null,
        ])

        <section class="grid gap-4 lg:grid-cols-[minmax(0,0.78fr)_minmax(0,1.22fr)]">
            <article class="page-card border-amber-300/15 p-5 sm:p-6" data-development-workspace-gap>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-amber-200">BIGGEST RELEASE GAP</p>
                <h2 class="mt-2 text-xl font-black text-slate-50">{{ $presentation?->gapLabel ?? '現在のRelease Gapを確認中' }}</h2>
                <p class="mt-3 text-sm leading-6 text-slate-400">{{ $presentation?->gapDetail }}</p>
                <p class="mt-4 border-t border-white/8 pt-4 text-[11px] leading-5 text-slate-600">
                    Task進捗ではなく、同じTaskへ結びついたGitHub Evidenceと明示確認から判断しています。
                </p>
            </article>

            <article id="development-current-action" class="page-card border-violet-300/15 bg-violet-300/[0.025] p-5 sm:p-6" data-development-workspace-action>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-300">CURRENT ACTION</p>
                <h2 class="mt-2 text-xl font-black text-slate-50">{{ $presentation?->action?->title ?? '次のActionを確認中' }}</h2>
                <p class="mt-3 text-sm leading-6 text-slate-400">{{ $presentation?->action?->intent }}</p>
                <div class="mt-5 flex flex-wrap gap-2">
                    @if ($presentation)
                        <a href="{{ $presentation->actionUrl }}" class="btn-primary min-h-11 px-4">{{ $presentation->actionLabel }}</a>
                    @endif
                    <a href="{{ route('github_workflow.index', ['plan_id' => $plan->id]) }}" class="btn-secondary min-h-11 px-4">GitHub / Evidenceを見る</a>
                </div>

                @if ($presentation?->decision)
                    <details class="pk-action-details mt-5">
                        <summary>なぜ今これ？</summary>
                        <p class="mt-3 text-xs leading-5 text-slate-400">{{ $presentation->decision->summary }}</p>
                    </details>
                @endif
            </article>
        </section>

        <section id="development-quality-gates" class="page-card p-5 sm:p-6" data-development-workspace-quality-gates>
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">RELEASE QUALITY GATES</p>
                    <h2 class="mt-1 text-lg font-black text-slate-50">7 Gateの現在状態</h2>
                    <p class="mt-2 text-xs leading-5 text-slate-500">ここでは状態だけを俯瞰します。確認・接続操作は既存GitHub Workflowで行います。</p>
                </div>
                <a href="{{ route('github_workflow.index', ['plan_id' => $plan->id]) }}#development-quality-gates" class="text-xs font-bold text-cyan-300 hover:text-cyan-200">Gateを操作する →</a>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($gateLabels as $gate => $label)
                    @php
                        $gateState = is_array($gates[$gate] ?? null) ? $gates[$gate] : [];
                        $status = (string) ($gateState['status'] ?? 'unknown');
                        $tone = match ($status) {
                            'passed' => 'border-emerald-300/20 bg-emerald-300/[0.035]',
                            'failed' => 'border-rose-300/20 bg-rose-300/[0.035]',
                            'pending' => 'border-amber-300/20 bg-amber-300/[0.035]',
                            default => 'border-white/8 bg-slate-950/25',
                        };
                        $textTone = match ($status) {
                            'passed' => 'text-emerald-300',
                            'failed' => 'text-rose-300',
                            'pending' => 'text-amber-200',
                            default => 'text-slate-500',
                        };
                    @endphp
                    <article class="rounded-2xl border p-4 {{ $tone }}" data-development-workspace-gate="{{ $gate }}" data-development-workspace-gate-status="{{ $status }}">
                        <div class="flex items-start justify-between gap-3">
                            <p class="text-sm font-black text-slate-100">{{ $label }}</p>
                            <span class="text-[10px] font-black {{ $textTone }}">{{ $statusLabels[$status] ?? $status }}</span>
                        </div>
                        <p class="mt-2 text-[10px] text-slate-600">weight {{ (int) ($gateState['weight'] ?? 0) }}</p>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <section id="development-history" class="page-card p-5 sm:p-6" data-development-workspace-history>
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">HISTORY</p>
            <h2 class="mt-1 text-lg font-black text-slate-50">Release判断とStateの変化</h2>
            <p class="mt-2 text-xs leading-5 text-slate-500">保存済みのDecision / Action / Stateを、直近の変化だけ確認できます。</p>
        </div>

        @if (! $plan)
            <p class="mt-4 text-sm text-slate-600">開発Planを作ると、ここに履歴が蓄積されます。</p>
        @elseif ($recentDecisions->isEmpty() && $recentActions->isEmpty() && $recentStates->isEmpty())
            <p class="mt-4 text-sm text-slate-600">保存済みのDevelopment Intelligence履歴はまだありません。</p>
        @else
            <div class="mt-4 grid gap-4 lg:grid-cols-3">
                <div>
                    <p class="text-xs font-black text-slate-300">Decision</p>
                    <div class="mt-2 space-y-2">
                        @forelse ($recentDecisions->take(3) as $trace)
                            <div class="rounded-xl border border-white/8 bg-slate-950/20 p-3">
                                <p class="text-xs font-bold text-slate-200">{{ $trace->decision_summary }}</p>
                                <p class="mt-1 text-[10px] text-slate-600">{{ $trace->created_at?->format('m/d H:i') }}</p>
                            </div>
                        @empty
                            <p class="text-[11px] text-slate-600">まだありません。</p>
                        @endforelse
                    </div>
                </div>
                <div>
                    <p class="text-xs font-black text-slate-300">Action</p>
                    <div class="mt-2 space-y-2">
                        @forelse ($recentActions->take(3) as $historyAction)
                            <div class="rounded-xl border border-white/8 bg-slate-950/20 p-3">
                                <p class="text-xs font-bold text-slate-200">{{ $historyAction->title }}</p>
                                <p class="mt-1 text-[10px] text-slate-600">{{ $historyAction->created_at?->format('m/d H:i') }}</p>
                            </div>
                        @empty
                            <p class="text-[11px] text-slate-600">まだありません。</p>
                        @endforelse
                    </div>
                </div>
                <div>
                    <p class="text-xs font-black text-slate-300">State / Evidence</p>
                    <div class="mt-2 space-y-2">
                        @forelse ($recentStates->take(3) as $snapshot)
                            <div class="rounded-xl border border-white/8 bg-slate-950/20 p-3">
                                <p class="text-xs font-bold text-slate-200">Evidence {{ count((array) $snapshot->evidence_references) }}件</p>
                                <p class="mt-1 text-[10px] text-slate-600">{{ $snapshot->captured_at?->format('m/d H:i') ?? $snapshot->created_at?->format('m/d H:i') }}</p>
                            </div>
                        @empty
                            <p class="text-[11px] text-slate-600">まだありません。</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </section>
</div>
@endsection
