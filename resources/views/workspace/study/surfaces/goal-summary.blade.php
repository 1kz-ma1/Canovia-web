@php
    $plan = $plan ?? data_get($surfaceData ?? [], 'plan');
    $learningType = $learning_type ?? data_get($surfaceData ?? [], 'learning_type', []);
    $state = $state ?? data_get($surfaceData ?? [], 'state', []);
    $targetScore = data_get($learningType, 'target_score');
@endphp

<section class="page-card border-amber-300/15 p-5 sm:p-6" data-study-surface="goal_summary">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <span class="badge badge-slate">{{ data_get($learningType, 'label', '学習') }}</span>
                @if ($targetScore !== null)
                    <span class="badge badge-slate">目標 {{ $targetScore }}</span>
                @endif
            </div>
            <h2 class="mt-3 text-2xl font-black text-slate-50">{{ $plan?->title }}</h2>
            @if ($plan?->description && $plan->description !== $plan->title)
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">{{ $plan->description }}</p>
            @endif
        </div>

        <div class="shrink-0 rounded-2xl border border-white/8 bg-slate-950/30 px-4 py-3 text-right">
            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">DEADLINE</p>
            <p class="mt-1 text-sm font-black text-slate-100">{{ $plan?->deadline?->format('Y/m/d') ?? '未設定' }}</p>
            @if (data_get($state, 'days_until_exam') !== null)
                <p class="mt-1 text-xs text-slate-500">あと {{ (int) data_get($state, 'days_until_exam') }}日</p>
            @endif
        </div>
    </div>
</section>
