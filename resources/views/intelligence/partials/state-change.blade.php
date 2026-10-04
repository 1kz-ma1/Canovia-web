@php
    $change = is_array($feedback ?? null) ? $feedback : null;
    $isPositive = (($change['readiness_delta'] ?? 0) > 0)
        || in_array(($change['kind'] ?? ''), ['action_changed', 'decision_changed', 'level_changed', 'readiness_improved'], true);
@endphp

@if ($change)
    <section
        class="page-card {{ $isPositive ? 'border-emerald-300/15 bg-emerald-300/[0.025]' : 'border-amber-300/15 bg-amber-300/[0.025]' }} p-5 sm:p-6"
        data-intelligence-state-change
        data-intelligence-state-change-kind="{{ $change['kind'] }}"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] {{ $isPositive ? 'text-emerald-300' : 'text-amber-200' }}">STATE CHANGE</p>
                <h2 class="mt-1 text-lg font-black text-slate-50">{{ $change['title'] }}</h2>
                <p class="mt-2 text-xs leading-5 text-slate-400">{{ $change['summary'] }}</p>
            </div>
            @if ($change['occurred_at'])
                <span class="text-[10px] text-slate-600">{{ $change['occurred_at']->diffForHumans() }}</span>
            @endif
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @if ($change['readiness_before'] !== null && $change['readiness_after'] !== null)
                <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4" data-state-change-readiness>
                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">READINESS</p>
                    <p class="mt-2 text-lg font-black text-slate-100">
                        {{ $change['readiness_before'] }}
                        <span class="mx-1 text-slate-600">→</span>
                        {{ $change['readiness_after'] }}
                    </p>
                    @if ($change['readiness_delta'] !== null && $change['readiness_delta'] !== 0)
                        <p class="mt-1 text-[11px] {{ $change['readiness_delta'] > 0 ? 'text-emerald-300' : 'text-amber-200' }}">
                            {{ $change['readiness_delta'] > 0 ? '+' : '' }}{{ $change['readiness_delta'] }} pt
                        </p>
                    @endif
                </div>
            @endif

            @if ($change['level_changed'])
                <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4" data-state-change-level>
                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">STATE</p>
                    <p class="mt-2 text-sm font-black text-slate-100">{{ $change['level_before_label'] }}</p>
                    <p class="mt-1 text-[11px] text-slate-600">↓</p>
                    <p class="mt-1 text-sm font-black text-slate-100">{{ $change['level_after_label'] }}</p>
                </div>
            @endif

            @if ($change['decision_changed'])
                <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4 sm:col-span-2" data-state-change-decision>
                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-amber-200">DECISION / GAP</p>
                    <p class="mt-2 text-xs leading-5 text-slate-500">{{ $change['decision_before'] }}</p>
                    <p class="my-1 text-[10px] text-slate-700">↓</p>
                    <p class="text-sm font-bold leading-5 text-slate-200">{{ $change['decision_after'] }}</p>
                </div>
            @endif

            @if ($change['action_changed'])
                <div class="rounded-2xl border border-violet-300/12 bg-violet-300/[0.025] p-4 sm:col-span-2" data-state-change-action>
                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-violet-300">CURRENT ACTION</p>
                    @if ($change['action_before'])
                        <p class="mt-2 text-xs leading-5 text-slate-500">{{ $change['action_before'] }}</p>
                        <p class="my-1 text-[10px] text-slate-700">↓</p>
                    @endif
                    <p class="text-sm font-black leading-5 text-slate-100">{{ $change['action_after'] ?? 'Actionを更新しました' }}</p>
                </div>
            @endif
        </div>

        @if (($change['added_evidence_count'] ?? 0) > 0)
            <div class="mt-4 border-t border-white/8 pt-4" data-state-change-evidence>
                <p class="text-[10px] font-black uppercase tracking-[0.12em] text-sky-300">EVIDENCE → DECISION</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    @forelse ($change['added_evidence_labels'] as $label)
                        <span class="badge badge-slate">{{ $label }}</span>
                    @empty
                        <span class="badge badge-slate">新しいEvidence {{ $change['added_evidence_count'] }}件</span>
                    @endforelse
                </div>
                <p class="mt-2 text-[11px] leading-5 text-slate-600">
                    新しく観測されたEvidenceをStateへ反映し、その結果からReadiness・Gap・Current Actionを再判定しています。
                </p>
            </div>
        @endif
    </section>
@endif
