@php
    $presentation = $presentation ?? data_get($surfaceData ?? [], 'presentation');
@endphp

<section class="page-card border-amber-300/15 p-5 sm:p-6" data-study-surface="biggest_gap" data-study-workspace-gap>
    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-amber-200">BIGGEST GAP</p>
    <h2 class="mt-2 text-xl font-black text-slate-50">{{ $presentation?->gapLabel ?? '現在のGapを確認中' }}</h2>
    <p class="mt-3 text-sm leading-6 text-slate-400">{{ $presentation?->gapDetail }}</p>
</section>
