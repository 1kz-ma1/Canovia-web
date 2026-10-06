<?php

namespace App\Services;

use App\Models\StudyPracticeSession;

class StudyPracticeReliabilityService
{
    public function __construct(
        private readonly PracticeQuestionCandidateReliabilitySignalService $candidateSignals,
    ) {}
    /**
     * These values are product guidance, not measured model accuracy.
     * They communicate how much Canovia can verify from provenance, grading
     * method, coverage, and activity fit.
     *
     * @param array<string,mixed> $activity
     * @param array<string,mixed> $provider
     * @param array<string,mixed> $strategy
     * @return array<string,mixed>
     */
    public function evaluate(
        array $activity,
        array $provider,
        ?StudyPracticeSession $session,
        array $strategy = [],
    ): array {
        $providerKey = (string) ($session?->question_provider ?: ($provider['provider'] ?? 'external_ai'));
        $assessmentProvider = (string) ($session?->assessment_provider ?? '');

        $questionQualityBase = match ($providerKey) {
            'question_bank' => 95,
            'hybrid_ai' => 84,
            'native_ai' => 74,
            default => 66,
        };

        $candidateSignal = $this->candidateSignals->summarize(
            $session,
            $strategy,
        );
        $candidateAdjustment = in_array(
            $providerKey,
            ['native_ai', 'hybrid_ai'],
            true,
        )
            ? (int) (
                $candidateSignal['applied_adjustment']
                ?? 0
            )
            : 0;
        $questionQuality = max(
            0,
            min(
                100,
                $questionQualityBase
                    + $candidateAdjustment,
            ),
        );

        $gradingReliability = match ($assessmentProvider) {
            'question_bank_grader' => 98,
            'native_ai' => 78,
            'external_ai' => 68,
            default => match ($providerKey) {
                'question_bank' => 96,
                'hybrid_ai' => 84,
                'native_ai' => 76,
                default => 66,
            },
        };

        $coverage = $this->coverageScore($session, $providerKey, $strategy);
        $questionPractice = collect($activity['all'] ?? [])
            ->firstWhere('key', StudyActivityPolicyService::QUESTION_PRACTICE);
        $methodFit = (int) data_get($questionPractice, 'fit_score', 60);

        $metrics = [
            [
                'key' => 'question_quality',
                'label' => '出題内容',
                'score' => $questionQuality,
                'base_score' => $questionQualityBase,
                'candidate_adjustment' => $candidateAdjustment,
                'note' => $this->questionNote(
                    $providerKey,
                    $candidateSignal,
                    $candidateAdjustment,
                ),
            ],
            [
                'key' => 'grading_reliability',
                'label' => '採点',
                'score' => $gradingReliability,
                'note' => $this->gradingNote($assessmentProvider, $providerKey),
            ],
            [
                'key' => 'coverage',
                'label' => '範囲カバー',
                'score' => $coverage,
                'note' => '現在のTask・重点範囲をどこまで問題で確認できる見込みかの目安です。',
            ],
            [
                'key' => 'method_fit',
                'label' => '学習方法との相性',
                'score' => $methodFit,
                'note' => $methodFit >= 70
                    ? 'このTaskは問題を解く学習との相性が高いと判定しています。'
                    : 'このTaskではAI演習より別の学習Activityを優先した方がよい可能性があります。',
            ],
        ];

        $overall = (int) round(
            ($questionQuality * 0.30)
            + ($gradingReliability * 0.30)
            + ($coverage * 0.20)
            + ($methodFit * 0.20)
        );

        return [
            'version' => 'v1',
            'overall_score' => $overall,
            'overall_label' => $this->label($overall),
            'metrics' => collect($metrics)
                ->map(fn (array $metric) => array_merge($metric, [
                    'label_level' => $this->label((int) $metric['score']),
                ]))
                ->all(),
            'candidate_signal' => $candidateSignal,
            'disclaimer' => 'これは実測したAI正答率ではありません。出題元・採点方式・Question Bank Coverage・Taskとの学習方法適合度に加え、利用可能な場合だけQuestion CandidateのHuman Review / 再利用実績を小幅に反映した目安です。学習者の正答率はQuestion品質の判定に使っていません。',
            'recommended_activity' => data_get($activity, 'primary'),
        ];
    }

    private function coverageScore(?StudyPracticeSession $session, string $providerKey, array $strategy): int
    {
        $target = max(1, (int) ($strategy['target_question_count'] ?? 10));

        if ($session) {
            $selected = collect($session->selected_questions ?? [])->filter(fn ($item) => is_array($item));
            if ($selected->isNotEmpty()) {
                $selectedCount = min($target, $selected->count());
                $base = 55 + (int) round(($selectedCount / $target) * 35);

                $domains = $selected
                    ->pluck('selection_domain')
                    ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                    ->unique()
                    ->count();

                return max(45, min(95, $base + min(5, $domains)));
            }
        }

        return match ($providerKey) {
            'question_bank' => 90,
            'hybrid_ai' => 82,
            'native_ai' => 70,
            default => 64,
        };
    }

    /**
     * @param array<string,mixed> $candidateSignal
     */
    private function questionNote(
        string $providerKey,
        array $candidateSignal,
        int $candidateAdjustment,
    ): string {
        $base = match ($providerKey) {
            'question_bank' => '人が管理するQuestion Bank中心なので、問題文と正答ルールを検証しやすい構成です。',
            'hybrid_ai' => 'Question Bankを先に使い、不足分だけAI生成で補います。',
            'native_ai' => 'AI生成問題が中心です。Question Bankより不確実性があります。',
            default => '外部AI生成に依存するため、問題品質をCanoviaだけでは完全に検証できません。',
        };

        if (
            ! in_array(
                $providerKey,
                ['native_ai', 'hybrid_ai'],
                true,
            )
        ) {
            return $base;
        }

        $status = (string) (
            $candidateSignal['status']
            ?? 'unavailable'
        );

        if (
            $status === 'unavailable'
            || $status === 'none'
        ) {
            return $base
                .' Candidate運営実績による補正はまだありません。';
        }

        if ($status === 'observing') {
            return $base
                .' Candidate Human Reviewは観測中で、サンプル不足のため点数補正はしていません。';
        }

        return $base
            .' Candidate Human Review / 再利用実績を'
            .($candidateAdjustment >= 0 ? '+' : '')
            .$candidateAdjustment
            .'点だけ小幅に反映しています。';
    }

    private function gradingNote(string $assessmentProvider, string $providerKey): string
    {
        if ($assessmentProvider === 'question_bank_grader' || $providerKey === 'question_bank') {
            return 'Question Bankのgrading_ruleを使う機械採点を優先します。';
        }

        if ($assessmentProvider === 'native_ai' || $providerKey === 'native_ai' || $providerKey === 'hybrid_ai') {
            return 'AI評価を使う問題では、自由記述や思考過程の判定に不確実性が残ります。';
        }

        return '外部AIによる評価は、同じ回答でも表現差の影響を受ける可能性があります。';
    }

    private function label(int $score): string
    {
        return match (true) {
            $score >= 85 => '高',
            $score >= 70 => '中〜高',
            $score >= 55 => '中',
            default => '低',
        };
    }
}
