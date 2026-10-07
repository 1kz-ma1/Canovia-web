@extends('layouts.app')
@section('title', 'Context更新 | Canovia')

@section('content')
@php
    $profile = is_array($livingProfile ?? null) ? $livingProfile : [];
    $pending = collect($profile['pending'] ?? []);
    $resolved = collect($profile['resolved'] ?? []);
    $studyPracticeFocused = $resolved->get('study_practice_focused');
    $studyReviewCycle = $resolved->get('study_review_cycle');
    $featureRecommendationPriority = (array) data_get($profile, 'feature_recommendation_priority', []);
    $growthExperience = (array) data_get($profile, 'growth_experience', []);
@endphp

<div class="mx-auto max-w-3xl space-y-5" data-personalization-context-updates>
    <section class="page-card p-5 sm:p-7">
        <p class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-300">LIVING PROFILE</p>
        <h1 class="mt-2 text-2xl font-black text-slate-50">Canoviaが気づいた変化</h1>
        <p class="mt-3 text-sm leading-7 text-slate-400">
            初回診断は固定プロフィールではありません。実際の利用状況から更新候補を作りますが、
            経験レベルやPlan方針に影響する変更は勝手に確定しません。
        </p>

        <div class="mt-4 rounded-2xl border border-violet-300/15 bg-violet-300/[0.035] p-4 text-xs leading-6 text-slate-400">
            <strong class="text-violet-200">Initial Diagnosis is a starting hypothesis, not a permanent profile.</strong><br>
            本人が答えた内容、Canoviaが観測した事実、そこからの推定は別々に保持します。
        </div>

        <form method="POST" action="{{ route('personalization.updates.refresh') }}" class="mt-5">
            @csrf
            <button type="submit" class="btn-secondary px-4 py-2 text-xs">現在地を再評価</button>
        </form>
    </section>

    @if (is_array($studyPracticeFocused))
        <section
            class="page-card border border-emerald-300/15 p-5 sm:p-7"
            data-growth-experience="study_practice_focused"
        >
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full border border-emerald-300/20 bg-emerald-300/[0.05] px-2.5 py-1 text-[10px] font-black text-emerald-200">
                    OBSERVED CHANGE
                </span>
                <span class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-600">
                    confidence: {{ data_get($studyPracticeFocused, 'confidence', 'high') }}
                </span>
            </div>
            <h2 class="mt-3 text-lg font-black text-slate-100">演習中心の学習段階に入っています</h2>
            <p class="mt-2 text-sm leading-7 text-slate-400">
                同じStudy Planで評価済み演習を継続していることをCanoviaが確認しました。
                これは能力レベルの再分類ではなく、実際の学習行動が演習中心になっているという観測です。
            </p>
            <p class="mt-3 text-xs leading-6 text-slate-500">
                初回に回答した学習段階、Guidance Level、Plan内容は変更していません。
            </p>
        </section>
    @endif


    @if (is_array($studyReviewCycle))
        <section
            class="page-card border border-sky-300/15 p-5 sm:p-7"
            data-growth-experience="study_review_cycle"
        >
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full border border-sky-300/20 bg-sky-300/[0.05] px-2.5 py-1 text-[10px] font-black text-sky-200">
                    OBSERVED CHANGE
                </span>
                <span class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-600">
                    confidence: {{ data_get($studyReviewCycle, 'confidence', 'high') }}
                </span>
            </div>
            <h2 class="mt-3 text-lg font-black text-slate-100">復習サイクルに入っています</h2>
            <p class="mt-2 text-sm leading-7 text-slate-400">
                同じStudy Planで、数日以上の間隔を含む複数日の評価済み演習をCanoviaが確認しました。
                これは「復習行動が継続している」という観測であり、定着や習熟を自動判定したものではありません。
            </p>
            <p class="mt-3 text-xs leading-6 text-slate-500">
                初回に回答した学習段階、Guidance Level、Plan内容、Study strategyは変更していません。
            </p>
        </section>
    @endif

    <section class="page-card p-5 sm:p-7" data-growth-experience-summary>
        <h2 class="text-lg font-black text-slate-100">利用の変化と、あなたが選んだこと</h2>
        <p class="mt-2 text-sm leading-7 text-slate-400">Canoviaが確認した行動と、あなた自身が承認した設定を分けて表示します。行動の多さを能力や経験年数の評価には使用しません。</p>
        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-white/10 p-3"><p class="text-xs text-slate-400">確認された行動</p><p class="mt-1 text-xl font-black text-slate-100">{{ count((array) ($growthExperience['observed_milestones'] ?? [])) }}</p></div>
            <div class="rounded-xl border border-white/10 p-3"><p class="text-xs text-slate-400">承認した選択</p><p class="mt-1 text-xl font-black text-slate-100">{{ count((array) ($growthExperience['confirmed_choices'] ?? [])) }}</p></div>
            <div class="rounded-xl border border-white/10 p-3"><p class="text-xs text-slate-400">確認待ち</p><p class="mt-1 text-xl font-black text-slate-100">{{ (int) ($growthExperience['pending_choices'] ?? 0) }}</p></div>
        </div>
    </section>

    @if ($featureRecommendationPriority !== [])
        <section class="page-card p-5 sm:p-7" data-feature-recommendation-priority>
            <h2 class="text-sm font-black text-slate-100">現在のおすすめ機能</h2>
            <p class="mt-2 text-xs leading-6 text-slate-400">利用モードと承認済みの選択に基づく表示です。未承認の提案は反映されません。</p>
            <ul class="mt-3 space-y-2 text-sm text-slate-200">
                @foreach ($featureRecommendationPriority as $recommendation)
                    <li>{{ match ($recommendation) {
                        'workspace.study' => '学習ワークスペース',
                        'workspace.development' => '開発ワークスペース',
                        'development.advanced_support' => '高度な開発支援',
                        'development.plan_review' => '開発Planの方向性レビュー',
                        default => $recommendation,
                    } }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($pending->isEmpty())
        <section class="page-card p-5 sm:p-7" data-context-update-empty>
            <p class="text-sm font-black text-slate-200">確認が必要な変化はありません</p>
            <p class="mt-2 text-xs leading-6 text-slate-500">
                低リスクな表示順などは必要に応じて自動調整します。定期アンケートを大量に出すことはしません。
            </p>
        </section>
    @else
        @foreach ($pending as $candidateKey => $candidate)
            <section
                class="page-card border border-cyan-300/15 p-5 sm:p-7"
                data-context-update-candidate="{{ $candidateKey }}"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full border border-amber-300/20 bg-amber-300/[0.05] px-2.5 py-1 text-[10px] font-black text-amber-200">
                        確認が必要
                    </span>
                    <span class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-600">
                        confidence: {{ data_get($candidate, 'confidence', 'unknown') }}
                    </span>
                </div>

                @if ($candidateKey === 'development_plan_direction_review')
                    <h2 class="mt-3 text-lg font-black text-slate-100">開発Planの方向性を見直しますか？</h2>
                    <p class="mt-2 text-sm leading-7 text-slate-400">GitHub上の活動から、現在のPlanの方向性を振り返るタイミングかもしれません。確認してもPlanの内容やタスクは自動変更されません。</p>
                @elseif ($candidateKey === 'development_guidance_level')
                    <h2 class="mt-3 text-lg font-black text-slate-100">案内の詳しさを標準に変更しますか？</h2>
                    <p class="mt-2 text-sm leading-7 text-slate-400">継続的な開発活動が確認できたため、画面の案内量を減らす選択肢を提案しています。経験や能力の判定ではありません。変更にはあなたの確認が必要です。</p>
                @elseif ($candidateKey === 'development_advanced_support')
                    <h2 class="mt-3 text-lg font-black text-slate-100">より高度なDevelopment支援を表示しますか？</h2>
                    @if (data_get($candidate, 'reopen_reason') === 'stronger_evidence')
                        <div
                            class="mt-3 rounded-2xl border border-cyan-300/15 bg-cyan-300/[0.035] p-3 text-xs leading-6 text-cyan-100/80"
                            data-candidate-reopened="stronger_evidence"
                        >
                            前回見送った後、Repository利用やDevelopment activityなど、
                            より強い観測事実が増えたため再確認しています。
                        </div>
                    @endif
                    <p class="mt-2 text-sm leading-7 text-slate-400">
                        GitHub連携まで使える状態になっています。最近の利用状況を見ると、
                        Repositoryを前提にした高度な開発支援も役立ちそうです。
                    </p>
                    <p class="mt-3 text-xs leading-6 text-slate-500">
                        これは「経験レベルが変わった」と断定するものではありません。
                        初回に回答した経験レベルも変更しません。
                    </p>
                @else
                    <h2 class="mt-3 text-lg font-black text-slate-100">Personalization更新候補</h2>
                    <p class="mt-2 text-sm leading-7 text-slate-400">
                        利用状況から新しい表示候補が見つかりました。
                    </p>
                @endif

                <div class="mt-5 flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('personalization.updates.confirm', ['candidateKey' => $candidateKey]) }}">
                        @csrf
                        <button type="submit" class="btn-primary px-4 py-2 text-xs">{{ $candidateKey === 'development_plan_direction_review' ? '確認済みにする' : ($candidateKey === 'development_guidance_level' ? '案内を標準に変更' : '表示する') }}</button>
                    </form>
                    <form method="POST" action="{{ route('personalization.updates.dismiss', ['candidateKey' => $candidateKey]) }}">
                        @csrf
                        <button type="submit" class="btn-secondary px-4 py-2 text-xs">今回は変更しない</button>
                    </form>
                </div>
            </section>
        @endforeach
    @endif

    <section class="page-card p-4 text-xs leading-6 text-slate-500">
        Context revision: {{ (int) data_get($profile, 'context_revision', 1) }}
        @if (data_get($profile, 'last_evaluated_at'))
            <span class="mx-1">·</span>
            最終評価: {{ data_get($profile, 'last_evaluated_at') }}
        @endif
    </section>
</div>
@endsection
