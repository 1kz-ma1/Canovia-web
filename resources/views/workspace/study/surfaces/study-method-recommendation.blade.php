@php
    $primary = (array) ($primary ?? data_get($surfaceData ?? [], 'primary', []));
    $signals = (array) ($signals ?? data_get($surfaceData ?? [], 'signals', []));
    $key = (string) ($primary['key'] ?? '');
    $isPractice = $key === AppServicesStudyActivityPolicyService::QUESTION_PRACTICE;
@endphp

<section
    id="study-method-recommendation"
    class="page-card border-emerald-300/20 bg-emerald-300/[0.025] p-5 sm:p-6"
    data-study-surface="study_method_recommendation"
    data-study-method-recommendation
    data-study-method-key="{{ $key }}"
    data-study-method-rule="{{ $primary['rule'] ?? '' }}"
    data-study-method-variant="{{ $primary['variant'] ?? '' }}"
>
    <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
        <div class="min-w-0">
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-emerald-300">RECOMMENDED METHOD</p>

            <div class="mt-2 flex flex-wrap items-center gap-3">
                <h2 class="text-2xl font-black text-slate-50">
                    {{ $primary['icon'] ?? '◉' }} {{ $primary['short_label'] ?? $primary['label'] ?? '学習方法' }}
                </h2>
                @if (! empty($primary['label']))
                    <span class="badge badge-slate">{{ $primary['label'] }}</span>
                @endif
                @if (($primary['fit_score'] ?? null) !== null)
                    <span class="badge badge-green">適合度 {{ (int) $primary['fit_score'] }}</span>
                @endif
            </div>

            <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-300">{{ $primary['reason'] ?? '' }}</p>

            @if (! empty($primary['variant']))
                <div class="mt-3">
                    <span class="badge badge-slate">
                        @switch($primary['variant'])
                            @case('resume') 途中の演習を継続 @break
                            @case('diagnosis') 現在地診断 @break
                            @case('focused_remediation') 弱点補強 @break
                            @case('exam_mode') Exam Mode @break
                            @default 横断演習
                        @endswitch
                    </span>
                </div>
            @endif

            @if (($primary['rule'] ?? null) === 'repeated_knowledge_gap')
                <p class="mt-3 text-xs leading-5 text-amber-200">
                    knowledge / concept gap が直近3回中 {{ (int) data_get($signals, 'knowledge_gap_attempt_count', 0) }}回。
                    同じ形式の問題を増やす前に理解を作り直します。
                </p>
            @elseif (($primary['rule'] ?? null) === 'retention_due')
                <p class="mt-3 text-xs leading-5 text-violet-200">
                    定着確認が必要な項目: {{ implode(' / ', (array) data_get($signals, 'retention_due_topics', [])) }}
                </p>
            @elseif (($primary['rule'] ?? null) === 'task_semantic_fit')
                <p class="mt-3 text-xs leading-5 text-slate-500">Task内容と現在Stateの両方から学習方法を選んでいます。</p>
            @endif
        </div>

        @unless ($isPractice)
            <div class="shrink-0">
                <a href="{{ $primary['url'] ?? '#' }}" class="btn-primary min-h-11 px-5">
                    {{ $primary['action_label'] ?? 'この方法で進める' }}
                </a>
            </div>
        @endunless
    </div>

    @if ($isPractice)
        <div class="mt-5 rounded-2xl border border-cyan-300/10 bg-cyan-300/[0.025] px-4 py-3 text-xs leading-5 text-slate-400">
            問題演習が今のPrimary Methodです。下のRecommendationに、実際に使う問題数・配分・弱点/定着/未探索の内訳を表示します。
        </div>
    @endif
</section>
