@php
    $plan = $plan ?? data_get($surfaceData ?? [], 'plan');
    $navigationTask = $navigation_task ?? data_get($surfaceData ?? [], 'navigation_task');
    $learningType = $learning_type ?? data_get($surfaceData ?? [], 'learning_type', []);
    $methodRecommendation = $method_recommendation ?? data_get($surfaceData ?? [], 'method_recommendation');
    $alternatives = collect(data_get($methodRecommendation, 'alternatives', []))
        ->filter(fn ($item) => is_array($item));
@endphp

<section id="study-methods" class="page-card p-5 sm:p-6" data-study-surface="study_methods" data-study-workspace-methods>
    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">OTHER STUDY METHODS</p>
    <h2 class="mt-1 text-lg font-black text-slate-50">別の方法で学習する</h2>
    <p class="mt-2 text-xs leading-5 text-slate-500">CanoviaはPrimary Methodを一つ選びますが、他の方法を禁止しません。必要ならここから切り替えられます。</p>

    @if ($alternatives->isNotEmpty())
        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3" data-study-method-alternatives>
            @foreach ($alternatives as $method)
                <a
                    href="{{ $method['url'] ?? '#' }}"
                    class="rounded-2xl border border-white/8 bg-slate-950/25 p-4 transition hover:border-emerald-300/30"
                    data-study-method-alternative="{{ $method['key'] ?? '' }}"
                    data-study-method-fit="{{ (int) ($method['fit_score'] ?? 0) }}"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-black text-slate-100">{{ $method['icon'] ?? '◉' }} {{ $method['short_label'] ?? $method['label'] ?? '学習方法' }}</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ $method['description'] ?? '' }}</p>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            <span class="badge badge-slate">{{ (int) ($method['fit_score'] ?? 0) }}</span>
                            @if ((int) ($method['outcome_adjustment'] ?? 0) !== 0)
                                <span
                                    class="text-[10px] font-bold text-amber-200"
                                    data-study-method-alternative-outcome-adjustment="{{ (int) $method['outcome_adjustment'] }}"
                                >
                                    実利用
                                    {{ ((int) $method['outcome_adjustment']) > 0 ? '+' : '' }}{{ (int) $method['outcome_adjustment'] }}
                                </span>
                            @endif
                        </div>
                    </div>
                    <p class="mt-3 text-xs font-bold text-emerald-200">{{ $method['action_label'] ?? 'この方法を開く' }} →</p>
                </a>
            @endforeach
        </div>
    @else
        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @if ($navigationTask)
                <a href="{{ route('plans.tasks.study_practice.show', [$plan, $navigationTask]) }}" class="rounded-2xl border border-white/8 bg-slate-950/25 p-4 transition hover:border-cyan-300/30">
                    <p class="text-sm font-black text-slate-100">問題演習</p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">診断・総合演習・弱点補強を現在Stateから組み立てます。</p>
                </a>
                <a href="{{ route('plans.tasks.study_recall.show', [$plan, $navigationTask]) }}" class="rounded-2xl border border-white/8 bg-slate-950/25 p-4 transition hover:border-violet-300/30">
                    <p class="text-sm font-black text-slate-100">Recall / 復習</p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">思い出せるかを確認して、定着Evidenceを残します。</p>
                </a>
            @endif
            <a href="{{ route('plans.study_scope.index', $plan) }}" class="rounded-2xl border border-white/8 bg-slate-950/25 p-4 transition hover:border-sky-300/30">
                <p class="text-sm font-black text-slate-100">範囲・教材を整理</p>
                <p class="mt-1 text-xs leading-5 text-slate-500">必要なPlanだけ、プリントや教材範囲をStudy Scopeとして追加します。</p>
            </a>
        </div>
    @endif
</section>
