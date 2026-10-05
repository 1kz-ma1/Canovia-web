@php
    $presentation = $presentation ?? data_get($surfaceData ?? [], 'presentation');
    $state = $state ?? data_get($surfaceData ?? [], 'state', []);
    $pressure = (string) data_get($state, 'deadline_pressure', 'unknown');
    $pressureLabel = match ($pressure) {
        'low' => '余裕あり',
        'medium' => 'やや詰まり気味',
        'high' => '負荷高め',
        'overdue' => '期限超過',
        default => '未判定',
    };
@endphp

<section id="study-readiness" class="page-card border-amber-300/15 p-5 sm:p-6" data-study-surface="readiness" data-study-workspace-readiness>
    <div class="grid gap-5 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
        <div class="rounded-3xl border border-amber-300/15 bg-gradient-to-br from-amber-300/[0.08] to-transparent p-5 sm:p-6">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-amber-300">EXAM READINESS</p>
            <div class="mt-3 flex items-end gap-3">
                <strong class="text-5xl font-black tracking-tight text-slate-50">{{ $presentation?->readinessDisplay() ?? '未判定' }}</strong>
                <span class="pb-1 text-sm font-bold text-slate-400">{{ $presentation?->stateLabel }}</span>
            </div>
            <p class="mt-3 text-xs leading-5 text-slate-500">Confidence {{ $presentation?->confidenceDisplay() ?? '—' }}</p>
            <div class="mt-5 rounded-2xl border border-white/8 bg-slate-950/30 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">DEADLINE PRESSURE</p>
                <p class="mt-1 text-lg font-black text-slate-100">{{ $pressureLabel }}</p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
            @foreach ($presentation?->metrics ?? [] as $metric)
                <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">{{ $metric['detail'] ?? $metric['label'] }}</p>
                    <p class="mt-2 text-2xl font-black text-slate-100">{{ $metric['value'] }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $metric['label'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>
