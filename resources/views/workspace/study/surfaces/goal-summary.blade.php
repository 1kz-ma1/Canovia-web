@php
    $plan = $plan ?? data_get($surfaceData ?? [], 'plan');
    $learningType = $learning_type ?? data_get($surfaceData ?? [], 'learning_type', []);
    $state = $state ?? data_get($surfaceData ?? [], 'state', []);
    $targetScore = data_get($learningType, 'target_score');
    $options = collect(data_get($learningType, 'options', []))
        ->filter(fn ($item) => is_array($item));
    $canEditLearningType = (bool) data_get($learningType, 'can_edit', false);
    $isExplicitLearningType = data_get($learningType, 'source') === 'explicit_override';
@endphp

<section
    class="page-card border-amber-300/15 p-5 sm:p-6"
    data-study-surface="goal_summary"
    data-study-learning-type-source="{{ data_get($learningType, 'source', 'heuristic') }}"
>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <span class="badge badge-slate">{{ data_get($learningType, 'label', '学習') }}</span>
                @if ($isExplicitLearningType)
                    <span class="badge badge-green">手動設定</span>
                @endif
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

    @if ($canEditLearningType && $plan && $options->isNotEmpty())
        <details class="pk-action-details mt-4 border-t border-white/8 pt-4" data-study-learning-type-editor>
            <summary>学習タイプを変更</summary>

            <div class="mt-4 flex flex-col gap-3 lg:flex-row lg:items-end">
                <form
                    method="POST"
                    action="{{ route('plans.study_learning_type.update', $plan) }}"
                    class="flex min-w-0 flex-1 flex-col gap-3 sm:flex-row sm:items-end"
                >
                    @csrf
                    @method('PUT')

                    <div class="min-w-0 flex-1">
                        <label for="study-learning-type-select-{{ $plan->id }}" class="text-xs font-bold text-slate-300">Learning Type</label>
                        <select
                            id="study-learning-type-select-{{ $plan->id }}"
                            name="learning_type"
                            class="input-field mt-2 w-full"
                            required
                        >
                            @foreach ($options as $key => $option)
                                <option
                                    value="{{ $key }}"
                                    @selected((string) data_get($learningType, 'key') === (string) $key)
                                >
                                    {{ $option['choice_label'] ?? $option['label'] ?? $key }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <button type="submit" class="btn-secondary shrink-0">変更を保存</button>
                </form>

                @if ($isExplicitLearningType)
                    <form method="POST" action="{{ route('plans.study_learning_type.destroy', $plan) }}" class="shrink-0">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-secondary">自動判定に戻す</button>
                    </form>
                @endif
            </div>

            <p class="mt-3 text-xs leading-5 text-slate-500">
                Workspace Modeとは別の設定です。ここでは、このPlanをどんな学習として扱うかだけを変更します。
            </p>
        </details>
    @endif
</section>
