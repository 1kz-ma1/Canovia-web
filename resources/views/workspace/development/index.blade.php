@extends('layouts.app')

@section('title', '開発 Workspace | Canovia')

@section('content')
@php
    $presentation = $intelligencePresentation ?? null;
    $adaptive = $developmentAdaptiveAction ?? null;
    $state = $adaptive?->intelligence?->state;
    $readiness = $adaptive?->intelligence?->readiness;
    $facts = $state?->facts ?? [];
    $focusState = data_get($facts, 'focus_task_state');
    $focusState = is_array($focusState) ? $focusState : [];
    $gates = is_array(data_get($readiness?->components, 'gates'))
        ? data_get($readiness->components, 'gates')
        : [];
    $passedGateCount = (int) data_get($readiness?->components, 'passed_gate_count', 0);
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
    $taskStatusLabels = [
        'doing' => '進行中',
        'todo' => '未着手',
        'paused' => '保留',
    ];
    $recentActions = collect(data_get($intelligenceHistory ?? [], 'actions', []));
    $recentDecisions = collect(data_get($intelligenceHistory ?? [], 'decisions', []));
    $recentStates = collect(data_get($intelligenceHistory ?? [], 'states', []));
    $shortSha = trim((string) data_get($focusState, 'head_sha', ''));
    $shortSha = $shortSha !== '' ? mb_substr($shortSha, 0, 10) : null;
    $recentActivity = collect($developmentRecentActivity ?? []);
    $unresolvedActivity = collect($developmentUnresolvedActivity ?? []);
    $unresolvedActivityIds = $unresolvedActivity->pluck('id')->map(fn ($id) => (int) $id);
    $associationTasks = collect($developmentAssociationTasks ?? []);
    $activeTasks = collect($developmentActiveTasks ?? []);
    $githubRepository = $developmentGithubRepository ?? null;
    $githubConnection = is_array($developmentGithubConnection ?? null)
        ? $developmentGithubConnection
        : [];
    $githubIntegration = is_array($developmentGithubIntegrationStatus ?? null)
        ? $developmentGithubIntegrationStatus
        : [];
    $githubConnectionState = (string) data_get($githubConnection, 'state', 'repository_install');
    $githubEvidenceAllowed = (bool) data_get($githubIntegration, 'evidence.allowed', false);
    $githubConnectAvailable = (bool) data_get($githubIntegration, 'runtime.interactive_connect_configured', false);
    $githubAppConnectionStatus = (string) data_get(
        $githubRepository?->metadata,
        'github_app_connection.status',
        'not_connected',
    );
    $executionContext = is_array($developmentExecutionContext ?? null)
        ? $developmentExecutionContext
        : null;
    $executionHandoff = is_array(data_get($executionContext, 'handoff'))
        ? data_get($executionContext, 'handoff')
        : [];
    $executionRecentEvidence = collect(data_get($executionContext, 'recent_evidence', []));
    $ciLabels = [
        'success' => '成功',
        'failure' => '失敗',
        'pending' => '実行中',
        'unknown' => '未確認',
    ];
    $reviewLabels = [
        'APPROVED' => '承認',
        'CHANGES_REQUESTED' => '修正依頼',
        'COMMENTED' => 'コメント',
        'DISMISSED' => '取消',
        'UNKNOWN' => '未確認',
    ];
    $observationKindLabels = [
        'pull_request' => 'Pull Request',
        'issue' => 'Issue',
        'branch' => 'Branch',
        'commit' => 'Commit',
        'deployment' => 'Deployment',
    ];
@endphp

<div class="mx-auto max-w-7xl space-y-5" data-development-workspace data-development-home-v1>
    <section class="page-card overflow-hidden p-0">
        <div class="border-b border-white/8 bg-gradient-to-r from-cyan-300/[0.08] via-slate-950/20 to-transparent px-5 py-5 sm:px-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-cyan-300">DEVELOPER HOME</p>
                    <h1 class="mt-2 text-2xl font-black text-slate-50 sm:text-3xl">次にやることを、開発の現実から決める。</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-400">
                        Current Actionを先頭に、GitHubの動き・進行中Task・Release Readinessを必要な順で確認します。
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
            <a href="#development-current-action" class="badge badge-slate whitespace-nowrap">Next Action</a>
            @if ($plan)
                <a href="#development-github-activity" class="badge badge-slate whitespace-nowrap">
                    GitHub Activity{{ $unresolvedActivity->isNotEmpty() ? ' '.$unresolvedActivity->count() : '' }}
                </a>
                <a href="#development-active-work" class="badge badge-slate whitespace-nowrap">Active Development</a>
                <a href="#development-readiness" class="badge badge-slate whitespace-nowrap">Readiness</a>
                <a href="{{ route('github_workflow.index', ['plan_id' => $plan->id]) }}" class="badge badge-slate whitespace-nowrap">GitHub / Evidence</a>
            @else
                <span class="badge badge-slate whitespace-nowrap opacity-45" aria-disabled="true">GitHub Activity</span>
                <span class="badge badge-slate whitespace-nowrap opacity-45" aria-disabled="true">Active Development</span>
                <span class="badge badge-slate whitespace-nowrap opacity-45" aria-disabled="true">Readiness</span>
            @endif
        </nav>
    </section>

    @if (! $plan)
        <div data-development-workspace-no-plan>
            @include('workspace.partials.mode-onboarding', [
                'modeOnboarding' => $modeOnboarding ?? null,
            ])
        </div>
    @else
        @if ($modeOnboarding ?? null)
            @include('workspace.partials.mode-onboarding', [
                'modeOnboarding' => $modeOnboarding,
            ])
        @endif

        <section
            id="development-current-action"
            class="page-card overflow-hidden border-violet-300/20 bg-[radial-gradient(circle_at_88%_12%,rgba(167,139,250,.12),transparent_28%),rgba(15,23,42,.32)] p-5 sm:p-6"
            data-development-workspace-action
            data-development-home-next-action
        >
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1.25fr)_minmax(15rem,.75fr)] lg:items-start">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-violet-300">NEXT ACTION</p>
                    <h2 class="mt-2 text-2xl font-black text-slate-50">
                        {{ $presentation?->action?->title ?? '開発状態を確認する' }}
                    </h2>
                    <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-400">
                        {{ $presentation?->action?->intent ?? 'TaskとGitHubの現在状態から、次に進める入口を確認します。' }}
                    </p>

                    <div class="mt-5 flex flex-wrap gap-2">
                        @if ($presentation)
                            <a href="{{ $presentation->actionUrl }}" class="btn-primary min-h-11 px-4">
                                {{ $presentation->actionLabel }}
                            </a>
                        @endif
                        <a href="{{ route('github_workflow.index', ['plan_id' => $plan->id]) }}" class="btn-secondary min-h-11 px-4">
                            GitHub / Evidence
                        </a>
                    </div>

                    @if ($presentation?->decision)
                        <details class="pk-action-details mt-5">
                            <summary>なぜ今これ？</summary>
                            <p class="mt-3 text-xs leading-5 text-slate-400">{{ $presentation->decision->summary }}</p>
                        </details>
                    @endif
                </div>

                <div class="rounded-2xl border border-white/8 bg-slate-950/35 p-4">
                    <p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">CURRENT FOCUS</p>
                    @if ($developmentFocusTask)
                        <p class="mt-2 text-base font-black text-slate-100">{{ $developmentFocusTask->title }}</p>
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
                        </div>
                    @elseif ($activeTasks->isNotEmpty())
                        <p class="mt-2 text-base font-black text-slate-100">{{ $activeTasks->first()->title }}</p>
                        <p class="mt-2 text-xs leading-5 text-slate-500">Release Evidenceがまだなくても、Planの実行はここから続けられます。</p>
                    @else
                        <p class="mt-2 text-sm font-bold text-slate-300">まだ進行中Taskがありません。</p>
                        <p class="mt-2 text-xs leading-5 text-slate-500">PlanのTaskを作るか、GitHubを接続して開発状態を取り込みます。</p>
                    @endif
                </div>
            </div>

            @if ($executionContext)
                <div class="mt-5 border-t border-white/8 pt-5" data-development-execution-context>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">ACTION CONTEXT</p>
                            <h3 class="mt-1 text-base font-black text-slate-100">
                                {{ data_get($executionContext, 'task.title', '対象Task') }}
                            </h3>
                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                このTaskに明示リンクされたGitHub Evidenceだけから、現在の実行対象をまとめています。
                            </p>
                        </div>
                        @if (data_get($executionContext, 'latest_evidence_at'))
                            <span class="text-[10px] text-slate-600">
                                Evidence {{ data_get($executionContext, 'latest_evidence_at')?->format('m/d H:i') }}
                            </span>
                        @endif
                    </div>

                    <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-xl border border-white/8 bg-slate-950/30 p-3">
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-slate-600">Repository</p>
                            <p class="mt-1 break-all text-xs font-bold text-slate-200">
                                {{ data_get($executionContext, 'repository') ?: '未確認' }}
                            </p>
                        </div>
                        <div class="rounded-xl border border-white/8 bg-slate-950/30 p-3">
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-slate-600">Branch / Commit</p>
                            <p class="mt-1 break-all text-xs font-bold text-slate-200">
                                {{ data_get($executionContext, 'branch.name') ?: '未確認' }}
                            </p>
                            @if (data_get($executionContext, 'commit.sha'))
                                <p class="mt-1 text-[10px] text-slate-600">
                                    {{ mb_substr((string) data_get($executionContext, 'commit.sha'), 0, 10) }}
                                    @if (data_get($executionContext, 'commit.verified'))
                                        · verified
                                    @endif
                                </p>
                            @endif
                        </div>
                        <div class="rounded-xl border border-white/8 bg-slate-950/30 p-3">
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-slate-600">Pull Request</p>
                            @if (data_get($executionContext, 'pull_request.number'))
                                @if (data_get($executionContext, 'pull_request.url'))
                                    <a href="{{ data_get($executionContext, 'pull_request.url') }}" target="_blank" rel="noopener noreferrer" class="mt-1 block text-xs font-black text-cyan-200 hover:text-cyan-100">
                                        #{{ (int) data_get($executionContext, 'pull_request.number') }} · {{ data_get($executionContext, 'pull_request.state', 'unknown') }}
                                    </a>
                                @else
                                    <p class="mt-1 text-xs font-black text-slate-200">
                                        #{{ (int) data_get($executionContext, 'pull_request.number') }} · {{ data_get($executionContext, 'pull_request.state', 'unknown') }}
                                    </p>
                                @endif
                                @if (data_get($executionContext, 'pull_request.draft'))
                                    <p class="mt-1 text-[10px] text-amber-200">Draft</p>
                                @endif
                            @else
                                <p class="mt-1 text-xs font-bold text-slate-500">未確認</p>
                            @endif
                        </div>
                        <div class="rounded-xl border border-white/8 bg-slate-950/30 p-3">
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-slate-600">CI / Review</p>
                            <p class="mt-1 text-xs font-black {{ data_get($executionContext, 'ci.state') === 'failure' ? 'text-rose-300' : (data_get($executionContext, 'ci.state') === 'success' ? 'text-emerald-300' : 'text-slate-300') }}">
                                CI {{ $ciLabels[data_get($executionContext, 'ci.state', 'unknown')] ?? data_get($executionContext, 'ci.state', '未確認') }}
                            </p>
                            <p class="mt-1 text-[10px] text-slate-500">
                                Review {{ $reviewLabels[data_get($executionContext, 'review.state', 'UNKNOWN')] ?? data_get($executionContext, 'review.state', '未確認') }}
                                @if (data_get($executionContext, 'review.reviewer'))
                                    · {{ data_get($executionContext, 'review.reviewer') }}
                                @endif
                            </p>
                        </div>
                    </div>

                    @if (data_get($executionContext, 'issue.number') || data_get($executionContext, 'deployment.status'))
                        <div class="mt-2 flex flex-wrap gap-2 text-[10px]">
                            @if (data_get($executionContext, 'issue.number'))
                                <span class="rounded-full border border-white/8 bg-slate-950/30 px-2.5 py-1 text-slate-500">
                                    Issue #{{ (int) data_get($executionContext, 'issue.number') }} · {{ data_get($executionContext, 'issue.state', 'unknown') }}
                                </span>
                            @endif
                            @if (data_get($executionContext, 'deployment.status'))
                                <span class="rounded-full border border-white/8 bg-slate-950/30 px-2.5 py-1 text-slate-500">
                                    Deploy {{ data_get($executionContext, 'deployment.environment') ?: 'environment' }}
                                    · {{ data_get($executionContext, 'deployment.status') }}
                                    @if (data_get($executionContext, 'deployment.production'))
                                        · production
                                    @endif
                                </span>
                            @endif
                        </div>
                    @endif

                    <div class="mt-4 grid gap-3 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,.85fr)]">
                        <div class="rounded-2xl border border-violet-300/15 bg-violet-300/[0.025] p-4">
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-violet-300">HANDOFF</p>
                            <p class="mt-2 text-sm font-black text-slate-100">{{ data_get($executionHandoff, 'title', '次の開発Action') }}</p>
                            <p class="mt-2 text-xs leading-5 text-slate-400">{{ data_get($executionHandoff, 'evidence_hint') }}</p>

                            @if (count((array) data_get($executionHandoff, 'done_when', [])) > 0)
                                <div class="mt-3 border-t border-white/8 pt-3">
                                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-600">DONE WHEN</p>
                                    <ul class="mt-2 space-y-1.5 text-xs text-slate-400">
                                        @foreach ((array) data_get($executionHandoff, 'done_when', []) as $signal)
                                            <li class="flex gap-2">
                                                <span class="text-cyan-300">✓</span>
                                                <span>{{ $signal }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>

                        <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">RECENT EVIDENCE</p>
                                <span class="text-[10px] text-slate-700">{{ $executionRecentEvidence->count() }}件</span>
                            </div>
                            @if ($executionRecentEvidence->isEmpty())
                                <p class="mt-2 text-xs leading-5 text-slate-600">このTaskのGitHub Evidenceはまだありません。</p>
                            @else
                                <div class="mt-2 space-y-2">
                                    @foreach ($executionRecentEvidence->take(3) as $contextEvidence)
                                        <div class="rounded-xl border border-white/6 bg-slate-950/30 p-2.5">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="text-[10px] font-black text-slate-500">{{ $contextEvidence['label'] }}</span>
                                                @if ($contextEvidence['occurred_at'])
                                                    <span class="text-[9px] text-slate-700">{{ $contextEvidence['occurred_at']->format('m/d H:i') }}</span>
                                                @endif
                                            </div>
                                            <p class="mt-1 text-[11px] leading-4 text-slate-400">{{ $contextEvidence['summary'] }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </section>

        <section
            id="development-github-activity"
            class="page-card border-cyan-300/15 p-5 sm:p-6"
            data-development-home-recent-activity
        >
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">RECENT GITHUB REALITY</p>
                    <h2 class="mt-1 text-lg font-black text-slate-50">最近GitHubで何が起きたか</h2>
                    <p class="mt-2 text-xs leading-5 text-slate-500">
                        観測した事実を新しい順に表示します。Task候補は自動確定せず、必要なものだけ確認して関連付けます。
                    </p>
                </div>
                @if ($unresolvedActivity->isNotEmpty())
                    <span class="badge badge-slate">{{ $unresolvedActivity->count() }}件 要整理</span>
                @elseif ($recentActivity->isNotEmpty())
                    <span class="badge badge-slate">整理済み</span>
                @endif
            </div>

            @if ($recentActivity->isEmpty())
                <div class="mt-4 rounded-2xl border border-dashed border-slate-700 bg-slate-950/20 p-5" data-development-github-connection>
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="max-w-3xl">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-black text-slate-200">GitHub Activityはまだありません。</p>
                                @if ($githubAppConnectionStatus === 'connected')
                                    <span class="badge badge-slate">Connected</span>
                                @elseif ($githubRepository)
                                    <span class="badge badge-slate">Not connected</span>
                                @endif
                            </div>
                            <p class="mt-2 text-xs leading-5 text-slate-500">
                                @if ($githubAppConnectionStatus === 'connected')
                                    RepositoryはGitHub Appに接続済みです。次のWebhook / Bootstrap同期でPR・Issue・Commitなどの現実Stateがここに入ります。
                                @elseif ($githubRepository)
                                    Private RepositoryをPublicへ変更する必要はありません。GitHub AppにこのRepositoryを許可すると同じDeveloper Homeへ同期できます。
                                @else
                                    GitHub未接続でもDeveloper Homeは使えます。Repositoryを登録してGitHub Appを接続すると、PR・Issue・Commitなどを自動で取り込めます。
                                @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap gap-2">
                            @if (
                                $canEdit
                                && $githubRepository
                                && $githubAppConnectionStatus !== 'connected'
                                && $githubEvidenceAllowed
                                && $githubConnectAvailable
                            )
                                <form method="POST" action="{{ route('github_workflow.app.connect', $githubRepository) }}">
                                    @csrf
                                    <button type="submit" class="btn-primary min-h-10 px-3 text-xs">GitHubを接続</button>
                                </form>
                            @endif

                            <a href="{{ route('github_workflow.index', ['plan_id' => $plan->id]) }}" class="btn-secondary min-h-10 px-3 text-xs">
                                {{ $githubRepository ? '接続設定' : 'Repositoryを追加' }}
                            </a>
                        </div>
                    </div>

                    @if ($githubRepository && $githubAppConnectionStatus !== 'connected' && filled(data_get($githubConnection, 'detail')))
                        <p class="mt-3 text-[11px] leading-5 text-slate-600">{{ data_get($githubConnection, 'detail') }}</p>
                    @endif
                </div>
            @else
                <div class="mt-4 grid gap-3 xl:grid-cols-2">
                    @foreach ($recentActivity as $observation)
                        @php
                            $suggestedTask = $observation->suggestedTask;
                            $resolvedArtifact = $observation->resolvedArtifact;
                            $linkedTask = $resolvedArtifact ? $resolvedArtifact->tasks->first() : null;
                            $confidence = $observation->suggestion_confidence !== null
                                ? (int) round(((float) $observation->suggestion_confidence) * 100)
                                : null;
                            $label = $observationKindLabels[$observation->kind] ?? $observation->kind;
                            $displayTitle = trim((string) $observation->title);
                            if ($displayTitle === '') {
                                $displayTitle = match ($observation->kind) {
                                    'pull_request' => 'PR #'.(int) $observation->provider_number,
                                    'issue' => 'Issue #'.(int) $observation->provider_number,
                                    'branch' => (string) $observation->ref,
                                    'commit' => 'Commit '.mb_substr((string) $observation->sha, 0, 10),
                                    'deployment' => 'Deployment #'.(int) $observation->provider_number,
                                    default => 'GitHub activity',
                                };
                            }
                            $canAssociateObservation = $unresolvedActivityIds->contains((int) $observation->id);
                        @endphp

                        <article class="rounded-2xl border border-white/8 bg-slate-950/25 p-4" data-development-observation="{{ $observation->id }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="badge badge-slate">{{ $label }}</span>
                                @if ($observation->state)
                                    <span class="text-[10px] font-bold text-slate-500">{{ $observation->state }}</span>
                                @endif
                                @if ($linkedTask)
                                    <span class="text-[10px] font-black text-emerald-300">Task連携済み</span>
                                @elseif ($canAssociateObservation)
                                    <span class="text-[10px] font-black text-amber-200">要整理</span>
                                @else
                                    <span class="text-[10px] font-black text-slate-500">観測済み</span>
                                @endif
                                @if ($observation->last_observed_at)
                                    <span class="ml-auto text-[10px] text-slate-600">{{ $observation->last_observed_at->format('m/d H:i') }}</span>
                                @endif
                            </div>

                            <div class="mt-2 min-w-0">
                                @if ($observation->url)
                                    <a href="{{ $observation->url }}" target="_blank" rel="noopener noreferrer" class="break-words text-sm font-black text-slate-100 hover:text-cyan-200">
                                        {{ $displayTitle }}
                                    </a>
                                @else
                                    <p class="break-words text-sm font-black text-slate-100">{{ $displayTitle }}</p>
                                @endif

                                @if ($observation->ref)
                                    <p class="mt-1 break-all text-[11px] text-slate-600">{{ $observation->ref }}</p>
                                @endif

                                @if ($linkedTask)
                                    <p class="mt-3 text-xs text-emerald-200">Task: {{ $linkedTask->title }}</p>
                                @elseif ($suggestedTask && $canAssociateObservation)
                                    <p class="mt-3 text-xs text-cyan-200" data-development-observation-suggestion>
                                        候補: {{ $suggestedTask->title }}
                                        @if ($confidence !== null)
                                            <span class="text-slate-500">· {{ $confidence }}%</span>
                                        @endif
                                    </p>
                                @elseif ($canAssociateObservation)
                                    <p class="mt-3 text-xs text-slate-600">候補を一意に決められませんでした。Taskを選んでください。</p>
                                @endif
                            </div>

                            @if ($canEdit && $canAssociateObservation)
                                <form method="POST" action="{{ route('plans.development_observations.link', [$plan, $observation]) }}" class="mt-4 flex flex-col gap-2 sm:flex-row">
                                    @csrf
                                    <select name="task_id" required class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-950/70 px-3 py-2 text-xs font-bold text-slate-100">
                                        <option value="">Taskを選択</option>
                                        @foreach ($associationTasks as $associationTask)
                                            <option value="{{ $associationTask->id }}" @selected($suggestedTask && (int) $suggestedTask->id === (int) $associationTask->id)>
                                                {{ $associationTask->title }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn-primary min-h-10 px-3 text-xs">関連付ける</button>
                                </form>
                                <form method="POST" action="{{ route('plans.development_observations.ignore', [$plan, $observation]) }}" class="mt-2 text-right">
                                    @csrf
                                    <button type="submit" class="text-[11px] font-bold text-slate-600 hover:text-slate-400">今回は無視</button>
                                </form>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section
            id="development-active-work"
            class="page-card p-5 sm:p-6"
            data-development-home-active
        >
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-sky-300">ACTIVE DEVELOPMENT</p>
                    <h2 class="mt-1 text-lg font-black text-slate-50">今動いているTask</h2>
                    <p class="mt-2 text-xs leading-5 text-slate-500">Focus Taskを先頭に、進行中・未着手の開発Taskを確認します。</p>
                </div>
                <a href="{{ route('plans.show', $plan) }}" class="text-xs font-bold text-cyan-300 hover:text-cyan-200">Plan全体を見る →</a>
            </div>

            @if ($activeTasks->isEmpty())
                <div class="mt-4 rounded-2xl border border-dashed border-slate-700 bg-slate-950/20 p-5">
                    <p class="text-sm font-black text-slate-200">進行中のTaskはありません。</p>
                    <p class="mt-2 text-xs leading-5 text-slate-500">Plan詳細からTaskを追加・整理すると、ここに現在の開発対象が並びます。</p>
                </div>
            @else
                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($activeTasks as $task)
                        @php
                            $githubArtifacts = $task->artifacts;
                            $isFocusTask = $developmentFocusTask && (int) $developmentFocusTask->id === (int) $task->id;
                            $progress = max(0, min(100, (int) $task->progress_percent));
                        @endphp
                        <article class="rounded-2xl border {{ $isFocusTask ? 'border-cyan-300/25 bg-cyan-300/[0.035]' : 'border-white/8 bg-slate-950/25' }} p-4" data-development-active-task="{{ $task->id }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if ($isFocusTask)
                                            <span class="text-[10px] font-black text-cyan-300">FOCUS</span>
                                        @endif
                                        <span class="text-[10px] font-bold text-slate-500">{{ $taskStatusLabels[$task->status] ?? $task->status }}</span>
                                    </div>
                                    <h3 class="mt-2 text-sm font-black leading-5 text-slate-100">{{ $task->title }}</h3>
                                </div>
                                <span class="shrink-0 text-xs font-black text-slate-400">{{ $progress }}%</span>
                            </div>

                            <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-slate-800">
                                <div class="h-full rounded-full bg-cyan-300/70" style="width: {{ $progress }}%"></div>
                            </div>

                            <div class="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-[10px] text-slate-600">
                                @if ($task->remaining_minutes !== null)
                                    <span>残り {{ (int) $task->remaining_minutes }}分</span>
                                @endif
                                <span>GitHub {{ $githubArtifacts->count() }}件</span>
                            </div>

                            @if ($canEdit)
                                <a href="{{ route('plans.tasks.execution_orchestration.show', [$plan, $task]) }}" class="mt-4 inline-flex text-xs font-bold text-cyan-300 hover:text-cyan-200">
                                    このTaskを進める →
                                </a>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section
            id="development-readiness"
            class="page-card border-cyan-300/15 p-5 sm:p-6"
            data-development-workspace-readiness
            data-development-home-readiness
        >
            <div class="grid gap-4 lg:grid-cols-[minmax(0,.7fr)_minmax(0,1.3fr)]">
                <div class="rounded-2xl border border-cyan-300/15 bg-cyan-300/[0.035] p-5">
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">RELEASE READINESS</p>
                    <div class="mt-2 flex items-end gap-3">
                        <strong class="text-4xl font-black tracking-tight text-slate-50">{{ $presentation?->readinessDisplay() ?? '未判定' }}</strong>
                        <span class="pb-1 text-xs font-bold text-slate-400">{{ $presentation?->stateLabel ?? '判定準備中' }}</span>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2 text-[10px] text-slate-500">
                        <span class="rounded-full border border-white/8 px-2 py-1">Gate {{ $passedGateCount }}/7</span>
                        <span class="rounded-full border border-white/8 px-2 py-1">Evidence {{ count($state?->evidenceReferences ?? []) }}件</span>
                    </div>
                </div>

                <article class="rounded-2xl border border-amber-300/15 bg-amber-300/[0.025] p-5" data-development-workspace-gap>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-amber-200">BIGGEST RELEASE GAP</p>
                    <h2 class="mt-2 text-lg font-black text-slate-50">{{ $presentation?->gapLabel ?? '現在のRelease Gapを確認中' }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-400">{{ $presentation?->gapDetail ?? 'GitHub Evidenceや明示確認が増えると、Release状態をより具体的に判断できます。' }}</p>
                </article>
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

            <details id="development-quality-gates" class="mt-4 rounded-2xl border border-white/8 bg-slate-950/20 p-4" data-development-workspace-quality-gates>
                <summary class="cursor-pointer list-none text-sm font-black text-slate-200">
                    7 Quality Gatesを確認
                    <span class="ml-2 text-xs font-normal text-slate-500">{{ $passedGateCount }}/7 確認済み</span>
                </summary>

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
                        </article>
                    @endforeach
                </div>

                <div class="mt-4 text-right">
                    <a href="{{ route('github_workflow.index', ['plan_id' => $plan->id]) }}#development-quality-gates" class="text-xs font-bold text-cyan-300 hover:text-cyan-200">
                        Gateを操作する →
                    </a>
                </div>
            </details>
        </section>

        @include('intelligence.partials.state-change', [
            'feedback' => $intelligenceStateChange ?? null,
        ])

        <details id="development-history" class="page-card p-5 sm:p-6" data-development-workspace-history>
            <summary class="cursor-pointer list-none">
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">HISTORY</p>
                <div class="mt-1 flex items-center justify-between gap-3">
                    <h2 class="text-lg font-black text-slate-50">Release判断とStateの変化</h2>
                    <span class="text-xs font-bold text-slate-600">必要なときだけ開く</span>
                </div>
            </summary>

            @if ($recentDecisions->isEmpty() && $recentActions->isEmpty() && $recentStates->isEmpty())
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
        </details>
    @endif
</div>
@endsection
