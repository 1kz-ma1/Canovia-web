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
    $implementationBrief = is_array($developmentImplementationBrief ?? null)
        ? $developmentImplementationBrief
        : null;
    $implementationBriefSteps = collect(data_get($implementationBrief, 'steps', []));
    $implementationBriefFacts = collect(data_get($implementationBrief, 'known_facts', []));
    $implementationBriefValidation = collect(data_get($implementationBrief, 'validation', []));
    $implementationBriefGuardrails = collect(data_get($implementationBrief, 'guardrails', []));
    $implementationBriefMode = (string) data_get($implementationBrief, 'mode', 'continuation');
    $implementationBriefTaskId = (int) data_get($implementationBrief, 'target.task_id', 0);
    $implementationBriefPullRequest = (int) data_get($implementationBrief, 'target.pull_request_number', 0);
    $providerTriageCiAvailable = $implementationBriefPullRequest > 0
        && (
            $implementationBriefMode === 'ci_triage'
            || data_get($executionContext, 'ci.state') === 'failure'
        );
    $providerTriageReviewAvailable = $implementationBriefPullRequest > 0
        && (
            $implementationBriefMode === 'review'
            || in_array(
                data_get($executionContext, 'review.state'),
                ['CHANGES_REQUESTED', 'COMMENTED'],
                true,
            )
        );
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

<div
    class="mx-auto max-w-7xl space-y-4"
    data-development-workspace
    data-development-home-v1
    data-development-surface="{{ $developmentSurface ?? 'work' }}"
>
    <section class="page-card overflow-hidden p-0" data-development-surface-shell>
        <div class="flex flex-col gap-3 border-b border-white/8 px-4 py-3 sm:px-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 items-center gap-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-cyan-300/18 bg-cyan-300/[0.06] text-cyan-300">
                    <svg viewBox="0 0 24 24" class="h-4 w-4 fill-none stroke-current" aria-hidden="true">
                        <path d="m8.5 7-5 5 5 5M15.5 7l5 5-5 5M14 4l-4 16" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">DEVELOPER</p>
                    <p class="truncate text-sm font-black text-slate-100">
                        {{ $plan?->title ?? '開発Workspace' }}
                    </p>
                </div>
            </div>

            @if ($developmentPlans->isNotEmpty())
                <form method="GET" action="{{ route('workspace.development.index') }}" class="flex min-w-0 items-center gap-2 lg:w-[23rem]">
                    <input type="hidden" name="surface" value="{{ $developmentSurface ?? 'work' }}">
                    <select id="development-workspace-plan" name="plan_id" class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-950/70 px-3 py-2 text-xs font-bold text-slate-100">
                        @foreach ($developmentPlans as $developmentPlan)
                            <option value="{{ $developmentPlan->id }}" @selected($plan && (int) $plan->id === (int) $developmentPlan->id)>
                                {{ $developmentPlan->title }}
                            </option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn-secondary min-h-9 px-3 text-xs">表示</button>
                </form>
            @endif
        </div>

        <nav class="development-surface-tabs" aria-label="Developer Workspace">
            @foreach (($developmentSurfaceTabs ?? []) as $surfaceTab)
                @php
                    $surfaceKey = (string) data_get($surfaceTab, 'key');
                    $isSurfaceActive = $surfaceKey === ($developmentSurface ?? 'work');
                @endphp
                <a
                    href="{{ route('workspace.development.index', array_filter([
                        'plan_id' => $plan?->id,
                        'surface' => $surfaceKey,
                    ])) }}"
                    class="development-surface-tab {{ $isSurfaceActive ? 'is-active' : '' }}"
                    data-development-surface-tab="{{ $surfaceKey }}"
                    @if ($isSurfaceActive) aria-current="page" @endif
                >
                    <strong>{{ data_get($surfaceTab, 'label') }}</strong>
                    <span>{{ data_get($surfaceTab, 'description') }}</span>
                </a>
            @endforeach
        </nav>
    </section>

    @if (! $plan)
        <div data-development-workspace-no-plan>
            @include('workspace.partials.mode-onboarding', [
                'modeOnboarding' => $modeOnboarding ?? null,
            ])
        </div>
    @else
        @if (($developmentSurface ?? 'work') === 'work' && ($modeOnboarding ?? null))
            @include('workspace.partials.mode-onboarding', [
                'modeOnboarding' => $modeOnboarding,
            ])
        @endif

        @switch($developmentSurface ?? 'work')
            @case('repository')
                @include('workspace.development.surfaces.repository')
                @break

            @case('team')
                @include('workspace.development.surfaces.team')
                @break

            @case('improvements')
                @include('workspace.development.surfaces.improvements')
                @break

            @case('preview')
                @include('workspace.development.surfaces.preview')
                @break

            @default
                @include('workspace.development.surfaces.work')
        @endswitch
    @endif
</div>

@if (($developmentSurface ?? 'work') === 'work' && $implementationBrief)
    <script>
        (() => {
            const button = document.querySelector('[data-development-brief-copy-button]');
            if (!button) return;

            button.addEventListener('click', async () => {
                const targetId = button.dataset.copyTarget;
                const target = targetId ? document.getElementById(targetId) : null;
                if (!target) return;

                const value = target.value || '';
                let copied = false;

                if (navigator.clipboard && window.isSecureContext) {
                    try {
                        await navigator.clipboard.writeText(value);
                        copied = true;
                    } catch (_) {
                        copied = false;
                    }
                }

                if (!copied) {
                    target.focus();
                    target.select();
                    copied = document.execCommand('copy');
                }

                if (copied) {
                    const original = button.textContent;
                    button.textContent = 'コピー済み';
                    window.setTimeout(() => {
                        button.textContent = original;
                    }, 1400);
                }
            });
        })();
    </script>
@endif
@endsection
