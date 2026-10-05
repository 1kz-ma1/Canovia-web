@php
    $learningType = $learning_type ?? data_get($surfaceData ?? [], 'learning_type', []);
    $state = $state ?? data_get($surfaceData ?? [], 'state', []);
@endphp

<section id="study-current-state" class="page-card border-cyan-300/15 p-5 sm:p-6" data-study-surface="current_state" data-study-workspace-current-state>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">CURRENT STATE</p>
            <h2 class="mt-2 text-xl font-black text-slate-50">{{ data_get($state, 'phase_label', '現在地を確認中') }}</h2>
            <p class="mt-2 text-sm leading-6 text-slate-400">
                {{ data_get($state, 'current_position_known') ? 'Canoviaは既存の学習結果を次の判断に使えます。' : 'まだ十分な学習Evidenceがないため、最初の現在地確認が必要です。' }}
            </p>
        </div>
        <span class="badge badge-slate">{{ data_get($learningType, 'label', '学習') }}</span>
    </div>

    <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
            <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">LATEST</p>
            <p class="mt-2 text-2xl font-black text-slate-100">{{ data_get($state, 'latest_score_percent') !== null ? data_get($state, 'latest_score_percent').'%' : '—' }}</p>
            <p class="mt-1 text-xs text-slate-500">直近演習</p>
        </div>
        <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
            <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">RECENT AVG</p>
            <p class="mt-2 text-2xl font-black text-slate-100">{{ data_get($state, 'recent_average_score_percent') !== null ? data_get($state, 'recent_average_score_percent').'%' : '—' }}</p>
            <p class="mt-1 text-xs text-slate-500">直近平均</p>
        </div>
        <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
            <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">PRACTICE</p>
            <p class="mt-2 text-2xl font-black text-slate-100">{{ (int) data_get($state, 'practice_attempt_count', 0) }}</p>
            <p class="mt-1 text-xs text-slate-500">演習履歴</p>
        </div>
        <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
            <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">RECALL</p>
            <p class="mt-2 text-2xl font-black text-slate-100">{{ (int) data_get($state, 'recall_evidence_count', 0) }}</p>
            <p class="mt-1 text-xs text-slate-500">定着Evidence</p>
        </div>
    </div>
</section>
