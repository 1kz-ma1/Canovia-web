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
    @if ($plan && session('success'))
        <div class="assistant-notice assistant-notice-success" role="status" data-development-import-confirmation>
            {{ session('success') }}
        </div>
    @endif

    @include('workspace.development.partials.capability-activation', [
        'activation' => $githubCapabilityActivation ?? null,
        'plan' => $plan ?? null,
    ])

    @php
        $surfaceItems = collect($developmentSurfaceTabs ?? []);
        $selectedSurfaceItem = $surfaceItems->firstWhere(
            'key',
            $developmentSurface ?? 'work',
        );
        $surfaceGroups = $surfaceItems
            ->sortBy([
                ['category_order', 'asc'],
                ['surface_order', 'asc'],
            ])
            ->groupBy('category_key');
    @endphp

    <section class="page-card overflow-hidden p-0" data-development-surface-shell>
        <div class="flex flex-col gap-3 px-4 py-3 sm:px-5 xl:flex-row xl:items-end xl:justify-between">
            <div class="min-w-0">
                <a
                    href="{{ route('workspace.development.top') }}"
                    class="specialized-workspace-top-link"
                    data-development-top-link
                >
                    <span aria-hidden="true">←</span>
                    <span>開発トップへ</span>
                </a>

                <div class="mt-3 flex min-w-0 items-center gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-cyan-300/18 bg-cyan-300/[0.06] text-cyan-300">
                        <svg viewBox="0 0 24 24" class="h-4 w-4 fill-none stroke-current" aria-hidden="true">
                            <path d="m8.5 7-5 5 5 5M15.5 7l5 5-5 5M14 4l-4 16" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">DEVELOPER</p>
                            @if ($selectedSurfaceItem)
                                <span
                                    class="rounded-full border border-white/8 bg-slate-950/35 px-2 py-0.5 text-[9px] font-black text-slate-500"
                                    data-development-current-category="{{ data_get($selectedSurfaceItem, 'category_key') }}"
                                >
                                    {{ data_get($selectedSurfaceItem, 'category_label') }}
                                </span>
                            @endif
                        </div>
                        <p class="truncate text-sm font-black text-slate-100">
                            {{ $plan?->title ?? '開発Workspace' }}
                        </p>
                        @if ($selectedSurfaceItem)
                            <p class="mt-0.5 truncate text-[10px] text-slate-600">
                                {{ data_get($selectedSurfaceItem, 'label') }} · {{ data_get($selectedSurfaceItem, 'description') }}
                            </p>
                        @endif
                    </div>
                </div>
            </div>

            @if ($plan)
                <form
                    method="GET"
                    action="{{ route('workspace.development.index') }}"
                    class="development-workspace-navigation is-view-only"
                    data-development-navigation-form
                >
                    <input type="hidden" name="plan_id" value="{{ $plan->id }}">

                    <label class="development-workspace-control">
                        <span>VIEW</span>
                        <select
                            id="development-workspace-surface"
                            name="surface"
                            data-development-surface-select
                        >
                            @foreach ($surfaceGroups as $surfaceGroup)
                                @php
                                    $firstSurface = $surfaceGroup->first();
                                @endphp
                                <optgroup label="{{ data_get($firstSurface, 'category_label') }}">
                                    @foreach ($surfaceGroup as $surfaceItem)
                                        <option
                                            value="{{ data_get($surfaceItem, 'key') }}"
                                            @selected(data_get($surfaceItem, 'key') === ($developmentSurface ?? 'work'))
                                        >
                                            {{ data_get($surfaceItem, 'label') }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </label>

                    <noscript>
                        <button type="submit" class="btn-secondary min-h-9 px-3 text-xs">表示</button>
                    </noscript>
                </form>
            @endif
        </div>
    </section>

    @if (! $plan)
        @if (filled(data_get($firstUseContext ?? [], 'goal')))
            <section class="page-card p-4 sm:p-5" data-development-first-use-context>
                <p class="text-xs font-semibold text-slate-400">診断で登録した開発目標</p>
                <p class="mt-1 break-words text-sm font-semibold text-slate-100">{{ data_get($firstUseContext, 'goal') }}</p>
                <p class="mt-2 text-xs text-slate-400">この目標からPlanを作成できます。診断の回答をもう一度入力する必要はありません。</p>
                <a href="{{ route('personalization.result') }}" class="mt-3 inline-flex text-xs font-semibold text-cyan-300 underline underline-offset-4">診断からPlan候補を見る</a>
            </section>
        @endif
        <div data-development-workspace-no-plan>
            @include('workspace.partials.mode-onboarding', [
                'modeOnboarding' => $modeOnboarding ?? null,
            ])
        </div>
    @else
        @if (
            filled(data_get($firstPlanContext ?? [], 'goal'))
            && trim((string) data_get($firstPlanContext, 'goal')) === trim((string) $plan->title)
            && (string) data_get($firstPlanContext, 'experience') === 'beginner'
            && ($developmentSurface ?? 'work') === 'work'
            && ($developmentRecentActivity ?? collect())->isEmpty()
            && ! ($developmentGithubRepository ?? null)
        )
            <aside class="page-card p-4 sm:p-5" data-development-first-plan-guidance>
                <p class="text-xs font-bold text-cyan-300">診断をもとにした初回ガイド</p>
                <p class="mt-2 text-sm text-slate-200">最初に作りたいものを小さく分け、最初の実装タスクを1件決めましょう。</p>
                <p class="mt-1 text-xs text-slate-400">タスクや実績が登録されると、実際の進捗に基づく案内を優先します。</p>
                @if ($canManage ?? false)
                    <div class="mt-4 flex flex-wrap gap-2" data-development-first-plan-actions>
                        <a href="{{ route('plans.ai_task_assistant.show', ['plan' => $plan, 'return_to_workspace' => 1]) }}" class="btn-primary min-h-11 px-4">初期タスクを作る</a>
                    </div>
                @endif
            </aside>
        @endif
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

<script>
    (() => {
        const form = document.querySelector('[data-development-navigation-form]');
        if (!form) return;

        const selects = form.querySelectorAll(
            '[data-development-surface-select]',
        );

        selects.forEach((select) => {
            select.addEventListener('change', () => {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                    return;
                }

                form.submit();
            });
        });
    })();
</script>

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
