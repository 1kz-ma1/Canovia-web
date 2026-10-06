<div class="space-y-5" data-study-view-panel="analysis">
    <section class="page-card p-5 sm:p-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">ANALYSIS</p>
                <h2 class="mt-1 text-lg font-black text-slate-50">現在地・弱点・Readinessを確認する。</h2>
                <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-500">
                    保存済みの学習EvidenceからCanoviaが把握している状態だけを表示します。
                </p>
            </div>
            <a href="{{ route('workspace.study.index', ['plan_id' => $plan->id, 'surface' => 'work']) }}" class="btn-secondary min-h-9 px-3 text-xs">
                今やることへ
            </a>
        </div>
    </section>

    <div
        class="space-y-5"
        data-study-workspace-composed
        data-study-learning-type="{{ data_get($studyLearningType ?? [], 'key', 'general_learning') }}"
    >
        @forelse (data_get($composition, 'surfaces', []) as $surface)
            @include($surface['partial'], $surface['data'] ?? [])
        @empty
            <section class="page-card p-5 sm:p-6">
                <div class="empty-state">
                    まだ分析できる学習Evidenceがありません。今やることから最初の学習結果を作ると、ここへ反映されます。
                </div>
            </section>
        @endforelse
    </div>
</div>
