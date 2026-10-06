@php
    $isStudyMode = ($mode ?? '') === 'study';
    $modeLabel = $isStudyMode ? '学習' : '開発';
    $workspaceRoute = $isStudyMode
        ? 'workspace.study.index'
        : 'workspace.development.index';
@endphp

<div class="space-y-3" data-specialized-top-plan-list="{{ $mode }}">
    @forelse ($summaries as $summary)
        @php
            $plan = data_get($summary, 'plan');
            $progress = (float) data_get($summary, 'progress_percent', 0);
            $status = (string) data_get($summary, 'status', '未判定');
            $remainingDays = data_get($summary, 'remaining_days');
            $deadline = data_get($summary, 'deadline');
            $githubRepository = data_get($summary, 'github_repository');
            $githubConnection = data_get($summary, 'github_connection');
            $githubState = (string) data_get($githubConnection, 'state', '');
            $githubReady = $githubState === 'ready';
        @endphp

        <article
            class="page-card p-4 sm:p-5"
            data-specialized-top-plan="{{ (int) data_get($summary, 'plan_id') }}"
        >
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-base font-black text-slate-50">
                                {{ data_get($summary, 'title') }}
                            </p>
                            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[10px] text-slate-500">
                                <span>{{ $status }}</span>
                                @if ($deadline)
                                    <span>· 期限 {{ $deadline->format('Y/m/d') }}</span>
                                @endif
                                @if (is_numeric($remainingDays))
                                    <span>
                                        ·
                                        @if ((int) $remainingDays < 0)
                                            {{ abs((int) $remainingDays) }}日超過
                                        @else
                                            残り{{ (int) $remainingDays }}日
                                        @endif
                                    </span>
                                @endif
                            </div>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="text-xl font-black text-slate-50">
                                {{ number_format($progress, 1) }}%
                            </p>
                            <p class="text-[9px] font-black uppercase tracking-[0.12em] text-slate-600">PROGRESS</p>
                        </div>
                    </div>

                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-950/70 ring-1 ring-white/8">
                        <div
                            class="h-full rounded-full {{ $isStudyMode ? 'bg-amber-300' : 'bg-cyan-300' }}"
                            style="width: {{ max(0, min(100, $progress)) }}%"
                        ></div>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2 text-[10px]">
                        <span class="rounded-full border border-white/8 bg-slate-950/30 px-2.5 py-1 text-slate-500">
                            Active {{ (int) data_get($summary, 'active_task_count', 0) }}
                        </span>
                        <span class="rounded-full border border-white/8 bg-slate-950/30 px-2.5 py-1 text-slate-500">
                            Tasks {{ (int) data_get($summary, 'task_count', 0) }}
                        </span>

                        @if ($isStudyMode)
                            <span class="rounded-full border border-white/8 bg-slate-950/30 px-2.5 py-1 text-slate-500">
                                範囲 {{ (int) data_get($summary, 'confirmed_scope_count', 0) }}
                            </span>
                            <span class="rounded-full border border-white/8 bg-slate-950/30 px-2.5 py-1 text-slate-500">
                                教材 {{ (int) data_get($summary, 'resource_count', 0) }}
                            </span>
                        @endif
                    </div>

                    @if (! $isStudyMode)
                        <div class="mt-4 rounded-2xl border border-white/8 bg-slate-950/25 p-3">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-600">GITHUB</p>
                                    @if ($githubRepository)
                                        <p class="mt-1 truncate text-xs font-black text-slate-200">
                                            {{ $githubRepository->title ?: 'Repository' }}
                                        </p>
                                        <p class="mt-1 text-[10px] {{ $githubReady ? 'text-emerald-300' : 'text-amber-200' }}">
                                            {{ data_get($githubConnection, 'label', '接続状態を確認') }}
                                        </p>
                                    @else
                                        <p class="mt-1 text-xs font-black text-slate-300">Repository未登録</p>
                                        <p class="mt-1 text-[10px] text-slate-600">GitHub / EvidenceからRepositoryを登録できます。</p>
                                    @endif
                                </div>

                                @if ((bool) data_get($developmentGithubIntegrationStatus ?? [], 'evidence.allowed', false))
                                    <a
                                        href="{{ route('github_workflow.index', ['plan_id' => $plan->id]) }}"
                                        class="btn-secondary min-h-9 shrink-0 px-3 text-xs"
                                    >
                                        {{ $githubReady ? 'GitHub / Evidence' : '接続設定' }}
                                    </a>
                                @else
                                    <span class="text-[10px] leading-4 text-slate-600">
                                        {{ data_get($developmentGithubIntegrationStatus ?? [], 'evidence.message', 'GitHub連携は現在利用できません。') }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>

                <div class="flex shrink-0 flex-wrap gap-2 lg:w-52 lg:flex-col">
                    <a
                        href="{{ route($workspaceRoute, ['plan_id' => $plan->id]) }}"
                        class="btn-primary min-h-10 justify-center px-4 text-xs"
                    >
                        {{ $modeLabel }}を開く
                    </a>

                    @if ($isStudyMode)
                        <a href="{{ route('plans.study_scope.index', $plan) }}" class="btn-secondary min-h-9 justify-center px-3 text-xs">
                            範囲
                        </a>
                        <a href="{{ route('plans.resources.index', $plan) }}" class="btn-secondary min-h-9 justify-center px-3 text-xs">
                            教材
                        </a>
                        <a href="{{ route('plans.study_scores.index', $plan) }}" class="btn-secondary min-h-9 justify-center px-3 text-xs">
                            成績
                        </a>
                    @endif

                    <a href="{{ route('plans.show', $plan) }}" class="btn-secondary min-h-9 justify-center px-3 text-xs">
                        Plan詳細
                    </a>
                </div>
            </div>
        </article>
    @empty
        <div class="empty-state" data-specialized-top-empty="{{ $mode }}">
            {{ $modeLabel }}Planはまだありません。
        </div>
    @endforelse
</div>
