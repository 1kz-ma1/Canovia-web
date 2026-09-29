@php
    $coordination = is_array($coordinationProjection ?? null) ? $coordinationProjection : null;
    $readyDependents = collect(data_get($coordination, 'ready_dependents', []));
    $blockedDependents = collect(data_get($coordination, 'blocked_dependents', []));
    $staleIndividualInstructions = collect(data_get($coordination, 'stale_individual_instructions', []));
    $staleDistributionTargets = collect(data_get($coordination, 'stale_distribution_targets', []));
    $recommendedIds = collect(data_get($coordination, 'recommended_distribution_task_ids', []))
        ->map(fn ($id) => (int) $id)
        ->filter()
        ->values();
@endphp

@if ($coordination)
    <section class="page-card border-cyan-300/15 p-5 sm:p-6" data-execution-coordination>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-3xl">
                <p class="text-[10px] font-black uppercase tracking-[.16em] text-cyan-300">EXECUTION COORDINATION</p>
                <h2 class="mt-1 text-xl font-black text-slate-100">完了後の実行可能範囲を再評価しました</h2>
                <p class="mt-2 text-xs leading-6 text-slate-500">
                    {{ data_get($coordination, 'source_task.title', $task->title) }} の完了を前提に、直接の後続Taskと既存の実行指示を現在Contextから見直しています。
                    Canoviaは後続Taskを自動開始せず、Packetや担当分配も勝手に再生成しません。
                </p>
            </div>

            <span class="badge badge-green">source complete</span>
        </div>

        <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-white/8 bg-white/[0.02] p-3">
                <p class="text-[10px] text-slate-600">READY NEXT</p>
                <p class="mt-1 text-lg font-black text-emerald-200">{{ $readyDependents->count() }}</p>
                <p class="mt-1 text-[10px] text-slate-600">今実行できる直接後続</p>
            </div>
            <div class="rounded-xl border border-white/8 bg-white/[0.02] p-3">
                <p class="text-[10px] text-slate-600">STILL BLOCKED</p>
                <p class="mt-1 text-lg font-black text-slate-300">{{ $blockedDependents->count() }}</p>
                <p class="mt-1 text-[10px] text-slate-600">他の前提を待つ後続</p>
            </div>
            <div class="rounded-xl border border-white/8 bg-white/[0.02] p-3">
                <p class="text-[10px] text-slate-600">INDIVIDUAL STALE</p>
                <p class="mt-1 text-lg font-black text-amber-200">{{ $staleIndividualInstructions->count() }}</p>
                <p class="mt-1 text-[10px] text-slate-600">古くなった個別指示</p>
            </div>
            <div class="rounded-xl border border-white/8 bg-white/[0.02] p-3">
                <p class="text-[10px] text-slate-600">DISTRIBUTION STALE</p>
                <p class="mt-1 text-lg font-black text-amber-200">{{ $staleDistributionTargets->count() }}</p>
                <p class="mt-1 text-[10px] text-slate-600">再評価が必要な担当</p>
            </div>
        </div>

        @if ($readyDependents->isNotEmpty())
            <div class="mt-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[.14em] text-emerald-300">READY DEPENDENTS</p>
                        <h3 class="mt-1 text-sm font-black text-slate-100">次に実行できるTask</h3>
                    </div>
                    <span class="text-[10px] text-slate-600">Dependencyは現在すべて満たされています</span>
                </div>

                <div class="mt-3 grid gap-3 lg:grid-cols-2">
                    @foreach ($readyDependents as $dependent)
                        <article class="rounded-2xl border border-emerald-300/12 bg-emerald-300/[0.025] p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-black text-slate-100">{{ data_get($dependent, 'task_title') }}</p>
                                    <p class="mt-1 text-[10px] text-slate-500">
                                        {{ data_get($dependent, 'status') }}
                                        · 進捗 {{ (int) data_get($dependent, 'progress_percent', 0) }}%
                                        · ready
                                    </p>
                                </div>
                                <a
                                    href="{{ route('plans.tasks.execution_orchestration.show', [$plan, (int) data_get($dependent, 'task_id')]) }}"
                                    class="btn-secondary px-3 py-2 text-xs"
                                >個別Orchestration</a>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($blockedDependents->isNotEmpty())
            <div class="mt-5">
                <p class="text-[10px] font-black uppercase tracking-[.14em] text-slate-500">STILL BLOCKED</p>
                <h3 class="mt-1 text-sm font-black text-slate-200">まだ待つ必要がある後続Task</h3>

                <div class="mt-3 space-y-3">
                    @foreach ($blockedDependents as $dependent)
                        <article class="rounded-xl border border-white/8 bg-white/[0.02] p-4">
                            <p class="text-sm font-bold text-slate-200">{{ data_get($dependent, 'task_title') }}</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ((array) data_get($dependent, 'blockers', []) as $blocker)
                                    <span class="badge badge-slate">
                                        前提: {{ data_get($blocker, 'title') }} · {{ (int) data_get($blocker, 'progress_percent', 0) }}%
                                    </span>
                                @endforeach
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($staleIndividualInstructions->isNotEmpty() || $staleDistributionTargets->isNotEmpty())
            <div class="mt-5 rounded-2xl border border-amber-300/12 bg-amber-300/[0.025] p-4">
                <p class="text-[10px] font-black uppercase tracking-[.14em] text-amber-300">STALE INSTRUCTIONS</p>
                <h3 class="mt-1 text-sm font-black text-amber-100">以前の指示をそのまま実行しないでください</h3>
                <p class="mt-2 text-[11px] leading-5 text-slate-500">
                    source Taskの完了でDependency・Evidence・current Tasksが変わったため、生成済みのPacket / Promptの一部は現在Contextと一致しなくなっています。
                </p>

                @if ($staleIndividualInstructions->isNotEmpty())
                    <div class="mt-3 space-y-2">
                        @foreach ($staleIndividualInstructions as $item)
                            <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-white/8 bg-slate-950/20 p-3">
                                <div>
                                    <p class="text-xs font-bold text-slate-200">{{ data_get($item, 'task_title') }}</p>
                                    <p class="mt-1 text-[10px] text-slate-600">{{ data_get($item, 'reason') }}</p>
                                </div>
                                <a
                                    href="{{ route('plans.tasks.execution_orchestration.show', [$plan, (int) data_get($item, 'task_id')]) }}"
                                    class="btn-secondary px-3 py-2 text-xs"
                                >現在Contextで確認</a>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($staleDistributionTargets->isNotEmpty())
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($staleDistributionTargets as $item)
                            <span class="badge badge-slate">
                                {{ data_get($item, 'actor_label') ?: data_get($item, 'task_title') }}
                                · {{ data_get($item, 'active') ? data_get($item, 'dependency_state', 'ready') : '完了/対象外' }}
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        <div class="mt-5 flex flex-wrap gap-2">
            @if ($recommendedIds->isNotEmpty())
                <a
                    href="{{ route('plans.execution_distribution.show', [
                        'plan' => $plan,
                        'suggested_task_ids' => $recommendedIds->implode(','),
                    ]) }}"
                    class="btn-primary"
                >次の担当候補を分配画面で確認</a>
            @else
                <a href="{{ route('plans.execution_distribution.show', $plan) }}" class="btn-secondary">分配全体を確認</a>
            @endif

            <a href="{{ route('plans.show', $plan) }}" class="btn-secondary">Plan全体を見る</a>
        </div>

        <p class="mt-3 text-[10px] leading-5 text-slate-600">
            Coordinationは現在のDependency graphとsession上の実行指示を読むProjectionです。後続Taskの開始、担当決定、Packet再生成、Dependency変更は自動では行いません。
        </p>
    </section>
@endif
