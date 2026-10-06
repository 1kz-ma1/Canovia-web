@php
    $isStudyMode = ($mode ?? '') === 'study';
    $modeLabel = $isStudyMode ? '学習' : '開発';
    $workspaceRoute = $isStudyMode
        ? 'workspace.study.index'
        : 'workspace.development.index';
@endphp

<div class="divide-y divide-white/8 overflow-hidden rounded-2xl border border-white/8 bg-slate-950/20" data-specialized-top-plan-list="{{ $mode }}">
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
            class="px-4 py-3.5 sm:px-5"
            data-specialized-top-plan="{{ (int) data_get($summary, 'plan_id') }}"
            data-specialized-top-plan-row
        >
            <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                <div class="min-w-0">
                    <div class="flex items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                <h3 class="min-w-0 truncate text-sm font-black text-slate-100 sm:text-[15px]">
                                    {{ data_get($summary, 'title') }}
                                </h3>
                                <span class="shrink-0 text-sm font-black {{ $isStudyMode ? 'text-amber-200' : 'text-cyan-200' }}">
                                    {{ number_format($progress, 1) }}%
                                </span>
                            </div>

                            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[10px] leading-4 text-slate-500">
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
                                <span>· Active {{ (int) data_get($summary, 'active_task_count', 0) }}</span>
                                <span>/ Tasks {{ (int) data_get($summary, 'task_count', 0) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-950/70 ring-1 ring-white/8">
                        <div
                            class="h-full rounded-full {{ $isStudyMode ? 'bg-amber-300' : 'bg-cyan-300' }}"
                            style="width: {{ max(0, min(100, $progress)) }}%"
                        ></div>
                    </div>

                    @if ($isStudyMode)
                        <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[10px] text-slate-500">
                            <span>範囲 {{ (int) data_get($summary, 'confirmed_scope_count', 0) }}</span>
                            <span>教材 {{ (int) data_get($summary, 'resource_count', 0) }}</span>
                        </div>
                    @else
                        <div class="mt-2 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-[10px]" data-development-top-repository-inline>
                            <span class="font-black uppercase tracking-[0.1em] text-slate-600">GitHub</span>
                            @if ($githubRepository)
                                <span class="max-w-full truncate font-bold text-slate-300">
                                    {{ $githubRepository->title ?: 'Repository' }}
                                </span>
                                <span class="{{ $githubReady ? 'text-emerald-300' : 'text-amber-200' }}">
                                    · {{ data_get($githubConnection, 'label', '接続状態を確認') }}
                                </span>
                            @else
                                <span class="font-bold text-slate-400">Repository未登録</span>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                    <a
                        href="{{ route($workspaceRoute, ['plan_id' => $plan->id]) }}"
                        class="btn-primary min-h-9 justify-center px-3.5 text-xs"
                    >
                        {{ $modeLabel }}を開く
                    </a>

                    @if (! $isStudyMode && (bool) data_get($developmentGithubIntegrationStatus ?? [], 'evidence.allowed', false))
                        <a
                            href="{{ route('github_workflow.index', ['plan_id' => $plan->id]) }}"
                            class="btn-secondary min-h-9 justify-center px-3 text-xs"
                        >
                            {{ $githubReady ? 'GitHub' : '接続設定' }}
                        </a>
                    @endif

                    <details class="relative" data-specialized-top-plan-tools>
                        <summary class="btn-secondary min-h-9 cursor-pointer list-none justify-center px-3 text-xs [&::-webkit-details-marker]:hidden">
                            {{ $isStudyMode ? '準備・詳細' : '詳細' }}
                        </summary>
                        <div class="absolute right-0 z-20 mt-2 min-w-44 rounded-2xl border border-white/10 bg-slate-950/95 p-2 shadow-2xl shadow-black/30 backdrop-blur-xl">
                            @if ($isStudyMode)
                                <a href="{{ route('plans.study_scope.index', $plan) }}" class="block rounded-xl px-3 py-2 text-xs font-bold text-slate-300 hover:bg-white/5 hover:text-white">
                                    範囲
                                </a>
                                <a href="{{ route('plans.resources.index', $plan) }}" class="block rounded-xl px-3 py-2 text-xs font-bold text-slate-300 hover:bg-white/5 hover:text-white">
                                    教材
                                </a>
                                <a href="{{ route('plans.study_scores.index', $plan) }}" class="block rounded-xl px-3 py-2 text-xs font-bold text-slate-300 hover:bg-white/5 hover:text-white">
                                    成績
                                </a>
                            @elseif (! (bool) data_get($developmentGithubIntegrationStatus ?? [], 'evidence.allowed', false))
                                <p class="px-3 py-2 text-[10px] leading-4 text-slate-500">
                                    {{ data_get($developmentGithubIntegrationStatus ?? [], 'evidence.message', 'GitHub連携は現在利用できません。') }}
                                </p>
                            @endif

                            <a href="{{ route('plans.show', $plan) }}" class="block rounded-xl px-3 py-2 text-xs font-bold text-slate-300 hover:bg-white/5 hover:text-white">
                                Plan詳細
                            </a>
                        </div>
                    </details>
                </div>
            </div>
        </article>
    @empty
        <div class="empty-state border-0 bg-transparent" data-specialized-top-empty="{{ $mode }}">
            {{ $modeLabel }}Planはまだありません。
        </div>
    @endforelse
</div>
