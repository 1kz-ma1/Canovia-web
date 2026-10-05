<section class="page-card p-5 sm:p-6" data-study-surface="recent_results" data-study-workspace-recent-results>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-emerald-300">RECENT RESULTS</p>
            <h2 class="mt-1 text-lg font-black text-slate-50">最近の演習</h2>
        </div>
        @if (($average_score_percent ?? null) !== null)
            <span class="badge badge-slate">平均 {{ $average_score_percent }}%</span>
        @endif
    </div>
    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @foreach (($items ?? []) as $item)
            <article class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-sm font-black text-slate-100">{{ $item['title'] ?? 'AI演習' }}</p>
                    <strong class="text-lg font-black text-emerald-300">{{ (int) ($item['score_percent'] ?? 0) }}%</strong>
                </div>
                @if (($item['created_at'] ?? null) instanceof DateTimeInterface)
                    <p class="mt-2 text-[10px] text-slate-600">{{ $item['created_at']->format('m/d H:i') }}</p>
                @endif
            </article>
        @endforeach
    </div>
</section>
