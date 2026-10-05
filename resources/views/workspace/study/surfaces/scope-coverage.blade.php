<section class="page-card p-5 sm:p-6" data-study-surface="scope_coverage" data-study-workspace-priority-scope>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-sky-300">SCOPE COVERAGE</p>
            <h2 class="mt-1 text-lg font-black text-slate-50">残り負荷が大きい範囲</h2>
        </div>
        <a href="{{ route('plans.study_scope.index', $plan) }}" class="text-xs font-bold text-sky-300 hover:text-sky-200">全範囲を見る →</a>
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @foreach (($items ?? []) as $item)
            <article class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                <p class="text-sm font-black text-slate-100">{{ $item['subject'] ?? '科目未設定' }}{{ ! empty($item['unit']) ? ' · '.$item['unit'] : '' }}</p>
                <p class="mt-2 text-xs text-slate-500">残り {{ number_format((float) ($item['remaining_unit'] ?? 0), 2) }} units</p>
                <div class="mt-3 flex flex-wrap gap-2 text-[10px] text-slate-500">
                    <span>Mastery {{ isset($item['mastery_score_percent']) ? $item['mastery_score_percent'].'%' : '未測定' }}</span>
                    <span>Retention {{ isset($item['retention_score_percent']) ? $item['retention_score_percent'].'%' : '未測定' }}</span>
                </div>
            </article>
        @endforeach
    </div>
</section>
