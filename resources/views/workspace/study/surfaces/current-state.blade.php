@php
    $plan = $plan ?? data_get($surfaceData ?? [], 'plan');
    $learningType = $learning_type ?? data_get($surfaceData ?? [], 'learning_type', []);
    $state = $state ?? data_get($surfaceData ?? [], 'state', []);
    $type = (string) data_get($learningType, 'key', 'general_learning');
    $isScoreExam = $type === 'score_exam';
    $externalScore = data_get($state, 'latest_external_score');
    $hasExternalScore = (bool) data_get($state, 'has_external_score_baseline', false);
    $targetScore = data_get($learningType, 'target_score');
    $latestPractice = data_get($state, 'latest_score_percent');

    $isBandScale = (string) data_get($externalScore, 'unit', '') === 'band'
        || ($plan && str_contains(mb_strtolower((string) $plan->title), 'ielts'));

    $formatNumber = static function ($value) {
        if (! is_numeric($value)) {
            return '—';
        }

        $number = (float) $value;

        return floor($number) === $number
            ? (string) (int) $number
            : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    };

    $targetDisplay = $targetScore !== null
        ? $formatNumber($targetScore).($isBandScale ? '' : '点')
        : '—';

    $gapDisplay = '—';
    if ($hasExternalScore && is_numeric(data_get($externalScore, 'score_value')) && is_numeric($targetScore)) {
        $gap = (float) $targetScore - (float) data_get($externalScore, 'score_value');

        $gapDisplay = $gap > 0
            ? 'あと'.$formatNumber($gap)
            : ($gap === 0.0 ? '達成' : '目標以上');
    }

    $stateTitle = $isScoreExam && ! $hasExternalScore
        ? '外部スコアBaseline待ち'
        : data_get($state, 'phase_label', '現在地を確認中');
@endphp

<section id="study-current-state" class="page-card border-cyan-300/15 p-5 sm:p-6" data-study-surface="current_state" data-study-workspace-current-state>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">CURRENT STATE</p>
            <h2 class="mt-2 text-xl font-black text-slate-50">{{ $stateTitle }}</h2>
            <p class="mt-2 text-sm leading-6 text-slate-400">
                @if ($isScoreExam && ! $hasExternalScore)
                    外部試験の現在スコアは未登録です。Canovia Practiceの正答率は別尺度なので、外部スコアとして推定しません。
                @elseif ($isScoreExam)
                    外部スコアとCanovia Practice正答率を別尺度のEvidenceとして保持しています。
                @else
                    {{ data_get($state, 'current_position_known') ? 'Canoviaは既存の学習結果を次の判断に使えます。' : 'まだ十分な学習Evidenceがないため、最初の現在地確認が必要です。' }}
                @endif
            </p>
        </div>
        <span class="badge badge-slate">{{ data_get($learningType, 'label', '学習') }}</span>
    </div>

    @if ($isScoreExam)
        <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4" data-study-score-state>
            <div class="rounded-2xl border border-cyan-300/15 bg-cyan-300/[0.025] p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.12em] text-cyan-300">CURRENT SCORE</p>
                <p class="mt-2 text-2xl font-black text-slate-100">{{ data_get($externalScore, 'display_value', '—') }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ data_get($externalScore, 'metric_label', '外部スコア') }}</p>
            </div>
            <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">TARGET</p>
                <p class="mt-2 text-2xl font-black text-slate-100">{{ $targetDisplay }}</p>
                <p class="mt-1 text-xs text-slate-500">Plan目標</p>
            </div>
            <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">GAP</p>
                <p class="mt-2 text-2xl font-black text-slate-100">{{ $gapDisplay }}</p>
                <p class="mt-1 text-xs text-slate-500">外部スコア尺度</p>
            </div>
            <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">PRACTICE ACC.</p>
                <p class="mt-2 text-2xl font-black text-slate-100">{{ $latestPractice !== null ? $latestPractice.'%' : '—' }}</p>
                <p class="mt-1 text-xs text-slate-500">Canovia内・別尺度</p>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-white/8 pt-4">
            <p class="text-xs leading-5 text-slate-500">
                @if ($hasExternalScore)
                    {{ data_get($externalScore, 'source_label', 'スコアEvidence') }}
                    · {{ data_get($externalScore, 'observed_at')?->format('Y/m/d') }}
                    @if (data_get($externalScore, 'source_detail'))
                        · {{ data_get($externalScore, 'source_detail') }}
                    @endif
                @else
                    公式結果・模試・自己申告など、実際のスコアを記録できます。
                @endif
            </p>
            @if ($plan)
                <a href="{{ route('plans.study_scores.index', $plan) }}" class="text-xs font-bold text-cyan-300 hover:text-cyan-200">
                    スコア履歴を管理 →
                </a>
            @endif
        </div>
    @else
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

        @if ($type === 'school_test' && $hasExternalScore && $plan)
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-white/8 pt-4">
                <p class="text-xs text-slate-400">前回/模試スコア: <strong class="text-slate-200">{{ data_get($externalScore, 'display_value') }}</strong></p>
                <a href="{{ route('plans.study_scores.index', $plan) }}" class="text-xs font-bold text-cyan-300 hover:text-cyan-200">スコア履歴を管理 →</a>
            </div>
        @endif
    @endif
</section>
