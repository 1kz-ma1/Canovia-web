<section
    id="study-current-action"
    class="page-card border-cyan-300/20 bg-cyan-300/[0.025] p-5 sm:p-6"
    data-study-surface="study_recommendation"
    data-study-workspace-recommendation
    data-study-recommendation-source="{{ $source ?? 'strategy' }}"
    data-study-recommendation-strategy="{{ $strategy_key ?? '' }}"
    data-study-recommendation-question-count="{{ (int) ($question_count ?? 0) }}"
>
    <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
        <div class="min-w-0">
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">
                {{ ($resume ?? false) ? 'CONTINUE PRACTICE' : "TODAY'S RECOMMENDATION" }}
            </p>

            <div class="mt-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 class="text-2xl font-black text-slate-50">{{ $title ?? '次の演習' }}</h2>
                @if (($question_count ?? 0) > 0)
                    <span class="text-sm font-black text-cyan-200">{{ (int) $question_count }}問</span>
                @endif
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
                @if (! empty($phase_label))
                    <span class="badge badge-slate">現在: {{ $phase_label }}</span>
                @endif
                @if (! empty($strategy_label) && $strategy_label !== ($title ?? null))
                    <span class="badge badge-slate">{{ $strategy_label }}</span>
                @endif
                @if (($resume ?? false) && ! empty($session_id))
                    <span class="badge badge-slate">Session #{{ $session_id }}</span>
                @endif
            </div>

            @if (($resume ?? false) && is_array($resume_progress ?? null))
                <p class="mt-3 text-sm font-bold text-slate-300">
                    {{ (int) data_get($resume_progress, 'answered', 0) }}/{{ (int) data_get($resume_progress, 'total', 0) }}問 回答済み
                </p>
            @elseif (! empty($reason))
                <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-400">{{ $reason }}</p>
            @endif
        </div>

        <div class="shrink-0">
            <a href="{{ $url }}" class="btn-primary min-h-11 px-5">
                {{ $action_label ?? '演習を始める' }}
            </a>
        </div>
    </div>

    @if (! empty($mix))
        <div class="mt-5 grid gap-3 sm:grid-cols-3" data-study-recommendation-mix>
            @foreach ($mix as $item)
                <div
                    class="rounded-2xl border border-white/8 bg-slate-950/30 p-4"
                    data-study-recommendation-mix-key="{{ $item['key'] ?? '' }}"
                    data-study-recommendation-mix-count="{{ (int) ($item['count'] ?? 0) }}"
                >
                    <p class="text-2xl font-black text-slate-100">{{ (int) ($item['count'] ?? 0) }}問</p>
                    <p class="mt-1 text-xs font-bold text-slate-400">{{ $item['label'] ?? '' }}</p>
                </div>
            @endforeach
        </div>
    @endif

    @php
        $contextRows = collect([
            [
                'label' => '再確認',
                'items' => $primary_topics ?? [],
            ],
            [
                'label' => '定着確認',
                'items' => $retention_due_topics ?? $secondary_topics ?? [],
            ],
            [
                'label' => '集中補強を休止',
                'items' => $cooldown_topics ?? [],
            ],
            [
                'label' => '直近で十分確認',
                'items' => $suppressed_parent_topics ?? [],
            ],
            [
                'label' => '低露出なので優先',
                'items' => $preferred_parent_topics ?? [],
            ],
        ])->filter(fn ($row) => ! empty($row['items']));
    @endphp

    @if ($contextRows->isNotEmpty())
        <div class="mt-5 space-y-3 border-t border-white/8 pt-4" data-study-recommendation-context>
            @foreach ($contextRows as $row)
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">{{ $row['label'] }}</span>
                    @foreach (array_slice(array_values($row['items']), 0, 4) as $item)
                        <span class="badge badge-slate">{{ $item }}</span>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif

    @if (($resume ?? false) && ! empty($reason))
        <details class="pk-action-details mt-5">
            <summary>この演習の方針</summary>
            <p class="mt-3 text-xs leading-5 text-slate-400">{{ $reason }}</p>
        </details>
    @endif
</section>
