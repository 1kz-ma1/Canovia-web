<section class="page-card border-rose-300/15 p-5 sm:p-6" data-study-surface="weaknesses" data-study-workspace-weaknesses>
    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-rose-300">WEAKNESS</p>
    <h2 class="mt-2 text-lg font-black text-slate-50">最近の結果から優先して見たいところ</h2>
    <div class="mt-4 flex flex-wrap gap-2">
        @foreach (($items ?? []) as $item)
            <span class="badge badge-slate">{{ $item }}</span>
        @endforeach
    </div>
    <p class="mt-4 text-xs leading-5 text-slate-500">正解しただけの説明改善は弱点として扱わず、実際の誤答・部分正解Evidenceを優先します。</p>
</section>
