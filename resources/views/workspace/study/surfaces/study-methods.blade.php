@php
    $plan = $plan ?? data_get($surfaceData ?? [], 'plan');
    $navigationTask = $navigation_task ?? data_get($surfaceData ?? [], 'navigation_task');
    $learningType = $learning_type ?? data_get($surfaceData ?? [], 'learning_type', []);
@endphp

<section id="study-methods" class="page-card p-5 sm:p-6" data-study-surface="study_methods" data-study-workspace-methods>
    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">OTHER STUDY METHODS</p>
    <h2 class="mt-1 text-lg font-black text-slate-50">別の方法で学習する</h2>
    <p class="mt-2 text-xs leading-5 text-slate-500">Canoviaのおすすめ以外も選べます。学習タイプに応じて、使えるSurfaceをここから広げていきます。</p>

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
</section>
