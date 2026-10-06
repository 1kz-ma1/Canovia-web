@php
    $improvementItems = collect($developmentImprovements ?? []);
    $severityLabels = [
        'high' => '優先',
        'medium' => '確認',
        'blocked' => '利用不可',
    ];
@endphp

<div class="space-y-4" data-development-surface-panel="improvements">
    <section class="page-card p-5 sm:p-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-amber-300">IMPROVEMENTS</p>
                <h2 class="mt-1 text-xl font-black text-slate-50">開発を詰まらせているものだけを見る。</h2>
                <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-500">
                    Release Gate・未整理Activity・接続状態・保留Taskなど、Canoviaが確認できる事実だけから改善候補を出します。
                </p>
            </div>
            <span class="badge badge-slate">{{ $improvementItems->count() }}件</span>
        </div>

        <div class="mt-5 grid gap-3 lg:grid-cols-2">
            @forelse ($improvementItems as $item)
                @php
                    $severity = (string) data_get($item, 'severity', 'medium');
                    $targetSurface = (string) data_get($item, 'surface', 'work');
                @endphp
                <a
                    href="{{ route('workspace.development.index', ['plan_id' => $plan->id, 'surface' => $targetSurface]) }}"
                    class="group rounded-2xl border border-white/8 bg-slate-950/25 p-4 transition hover:border-amber-300/25 hover:bg-amber-300/[0.025]"
                    data-development-improvement="{{ data_get($item, 'key') }}"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-black text-slate-100 group-hover:text-amber-100">{{ data_get($item, 'title') }}</p>
                            <p class="mt-2 text-xs leading-5 text-slate-500">{{ data_get($item, 'detail') }}</p>
                        </div>
                        <span class="badge {{ $severity === 'high' ? 'badge-amber' : ($severity === 'blocked' ? 'badge-red' : 'badge-slate') }}">
                            {{ $severityLabels[$severity] ?? '確認' }}
                        </span>
                    </div>
                    <p class="mt-3 text-[10px] font-black text-cyan-300">該当画面へ →</p>
                </a>
            @empty
                <div class="empty-state lg:col-span-2">
                    今すぐ優先すべき明確な改善候補はありません。新しいGitHub EvidenceやRelease状態が入ると再評価されます。
                </div>
            @endforelse
        </div>
    </section>

    <details class="page-card p-4 sm:p-5" data-development-improvement-history>
        <summary class="cursor-pointer list-none">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">DECISION HISTORY</p>
                    <h3 class="mt-1 text-sm font-black text-slate-100">判断の履歴を確認</h3>
                </div>
                <span class="text-[10px] text-slate-600">必要なときだけ開く</span>
            </div>
        </summary>

        <div class="mt-4 grid gap-3 lg:grid-cols-3">
            <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                <p class="text-xs font-black text-slate-300">Decision</p>
                <div class="mt-2 space-y-2">
                    @forelse ($recentDecisions->take(3) as $trace)
                        <div class="rounded-xl border border-white/6 p-3">
                            <p class="text-xs font-bold text-slate-300">{{ $trace->decision_summary }}</p>
                            <p class="mt-1 text-[10px] text-slate-600">{{ $trace->created_at?->format('m/d H:i') }}</p>
                        </div>
                    @empty
                        <p class="text-xs text-slate-600">まだありません。</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                <p class="text-xs font-black text-slate-300">Action</p>
                <div class="mt-2 space-y-2">
                    @forelse ($recentActions->take(3) as $historyAction)
                        <div class="rounded-xl border border-white/6 p-3">
                            <p class="text-xs font-bold text-slate-300">{{ $historyAction->title }}</p>
                            <p class="mt-1 text-[10px] text-slate-600">{{ $historyAction->created_at?->format('m/d H:i') }}</p>
                        </div>
                    @empty
                        <p class="text-xs text-slate-600">まだありません。</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                <p class="text-xs font-black text-slate-300">State / Evidence</p>
                <div class="mt-2 space-y-2">
                    @forelse ($recentStates->take(3) as $snapshot)
                        <div class="rounded-xl border border-white/6 p-3">
                            <p class="text-xs font-bold text-slate-300">Evidence {{ count((array) $snapshot->evidence_references) }}件</p>
                            <p class="mt-1 text-[10px] text-slate-600">{{ $snapshot->captured_at?->format('m/d H:i') ?? $snapshot->created_at?->format('m/d H:i') }}</p>
                        </div>
                    @empty
                        <p class="text-xs text-slate-600">まだありません。</p>
                    @endforelse
                </div>
            </div>
        </div>
    </details>
</div>
