@php
    $plan = $plan ?? data_get($surfaceData ?? [], 'plan');
    $learningType = $learning_type ?? data_get($surfaceData ?? [], 'learning_type', []);
    $options = collect(data_get($learningType, 'options', []))
        ->filter(fn ($item) => is_array($item));
    $inferredKey = (string) data_get($learningType, 'inferred_key', data_get($learningType, 'key', 'general_learning'));
@endphp

<section
    class="page-card border-violet-300/20 bg-violet-300/[0.025] p-5 sm:p-6"
    data-study-surface="learning_type_confirmation"
    data-study-learning-type-confirmation
>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="max-w-3xl">
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-300">LEARNING TYPE</p>
            <h2 class="mt-2 text-xl font-black text-slate-50">この学習はどれに近い？</h2>
            <p class="mt-2 text-sm leading-6 text-slate-400">
                Plan内容だけでは学習方法が変わる可能性があります。
                一度選ぶとCanoviaはそのタイプを優先し、毎回確認しません。
            </p>
        </div>
        <span class="badge badge-slate">現在の推定: {{ data_get($learningType, 'label', '一般学習') }}</span>
    </div>

    <form method="POST" action="{{ route('plans.study_learning_type.update', $plan) }}" class="mt-5">
        @csrf
        @method('PUT')

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($options as $key => $option)
                <label class="cursor-pointer rounded-2xl border border-white/8 bg-slate-950/25 p-4 transition hover:border-violet-300/30">
                    <div class="flex items-start gap-3">
                        <input
                            type="radio"
                            name="learning_type"
                            value="{{ $key }}"
                            @checked(old('learning_type', $inferredKey) === $key)
                            class="mt-1"
                            required
                        >
                        <span>
                            <span class="block text-sm font-black text-slate-100">{{ $option['choice_label'] ?? $option['label'] ?? $key }}</span>
                            <span class="mt-1 block text-xs leading-5 text-slate-500">{{ $option['description'] ?? '' }}</span>
                        </span>
                    </div>
                </label>
            @endforeach
        </div>

        @error('learning_type')
            <p class="mt-3 text-xs text-rose-300">{{ $message }}</p>
        @enderror

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button type="submit" class="btn-primary">このタイプで進める</button>
            <p class="text-xs text-slate-500">あとでGoal Summaryから変更できます。</p>
        </div>
    </form>
</section>
