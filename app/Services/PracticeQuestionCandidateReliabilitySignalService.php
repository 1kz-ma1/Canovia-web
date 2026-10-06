<?php

namespace App\Services;

use App\Models\PracticeQuestionCandidate;
use App\Models\StudyPracticeSession;

final class PracticeQuestionCandidateReliabilitySignalService
{
    private const MIN_REVIEWED_FOR_ADJUSTMENT = 5;

    private const REVIEW_SAMPLE_TARGET = 20;

    private const REUSE_SAMPLE_TARGET = 20;

    private const SESSION_SCAN_LIMIT = 500;

    /**
     * @param array<string,mixed> $strategy
     * @return array<string,mixed>
     */
    public function summarize(
        ?StudyPracticeSession $session,
        array $strategy = [],
    ): array {
        $examProfileKey = $this->examProfileKey(
            $session,
            $strategy,
        );

        if ($examProfileKey === null) {
            return $this->unavailable();
        }

        $candidates = PracticeQuestionCandidate::query()
            ->where('exam_profile_key', $examProfileKey)
            ->get([
                'id',
                'status',
                'generation_count',
                'promoted_question_id',
            ]);

        $pending = $candidates->where(
            'status',
            PracticeQuestionCandidate::STATUS_PENDING,
        )->values();
        $promoted = $candidates->where(
            'status',
            PracticeQuestionCandidate::STATUS_PROMOTED,
        )->values();
        $rejected = $candidates->where(
            'status',
            PracticeQuestionCandidate::STATUS_REJECTED,
        )->values();

        $reviewedCount = $promoted->count()
            + $rejected->count();

        $promotionRate = $reviewedCount > 0
            ? (int) round(
                ($promoted->count() / $reviewedCount) * 100,
            )
            : null;

        $promotedQuestionIds = $promoted
            ->pluck('promoted_question_id')
            ->filter(
                fn ($id) =>
                    is_numeric($id)
                    && (int) $id > 0,
            )
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $reuse = $this->reuseMetrics(
            $promotedQuestionIds->all(),
            $session,
        );

        $reviewWeight = min(
            1.0,
            $reviewedCount / self::REVIEW_SAMPLE_TARGET,
        );
        $reuseWeight = min(
            1.0,
            $reuse['assessed_reuse_count']
                / self::REUSE_SAMPLE_TARGET,
        );
        $evidenceStrength =
            ($reviewWeight * 0.75)
            + ($reuseWeight * 0.25);

        $rawAdjustment = $promotionRate === null
            ? 0
            : max(
                -6,
                min(
                    6,
                    (int) round(
                        ($promotionRate - 70) / 5,
                    ),
                ),
            );

        $appliedAdjustment =
            $reviewedCount
                < self::MIN_REVIEWED_FOR_ADJUSTMENT
                ? 0
                : max(
                    -6,
                    min(
                        6,
                        (int) round(
                            $rawAdjustment
                            * $evidenceStrength,
                        ),
                    ),
                );

        $status = match (true) {
            $candidates->isEmpty() => 'none',
            $reviewedCount
                < self::MIN_REVIEWED_FOR_ADJUSTMENT
                    => 'observing',
            default => 'active',
        };

        return [
            'status' => $status,
            'exam_profile_key' => $examProfileKey,
            'candidate_count' => $candidates->count(),
            'generation_occurrence_count' =>
                (int) $candidates->sum(
                    fn (PracticeQuestionCandidate $candidate) =>
                        max(
                            1,
                            (int) $candidate->generation_count,
                        ),
                ),
            'pending_count' => $pending->count(),
            'reviewed_count' => $reviewedCount,
            'promoted_count' => $promoted->count(),
            'rejected_count' => $rejected->count(),
            'promotion_rate_percent' => $promotionRate,
            'reused_question_count' =>
                $reuse['reused_question_count'],
            'selected_reuse_count' =>
                $reuse['selected_reuse_count'],
            'assessed_reuse_count' =>
                $reuse['assessed_reuse_count'],
            'current_session_promoted_candidate_count' =>
                $reuse[
                    'current_session_promoted_candidate_count'
                ],
            'evidence_strength_percent' =>
                (int) round($evidenceStrength * 100),
            'raw_adjustment' => $rawAdjustment,
            'applied_adjustment' =>
                $appliedAdjustment,
            'minimum_reviewed_for_adjustment' =>
                self::MIN_REVIEWED_FOR_ADJUSTMENT,
            'session_scan_limit' =>
                self::SESSION_SCAN_LIMIT,
            'note' => $this->note(
                $status,
                $reviewedCount,
                $promoted->count(),
                $promotionRate,
                $reuse['assessed_reuse_count'],
                $appliedAdjustment,
            ),
        ];
    }

    /**
     * @param array<int,int> $promotedQuestionIds
     * @return array<string,int>
     */
    private function reuseMetrics(
        array $promotedQuestionIds,
        ?StudyPracticeSession $currentSession,
    ): array {
        if ($promotedQuestionIds === []) {
            return [
                'reused_question_count' => 0,
                'selected_reuse_count' => 0,
                'assessed_reuse_count' => 0,
                'current_session_promoted_candidate_count' => 0,
            ];
        }

        $idSet = array_fill_keys(
            $promotedQuestionIds,
            true,
        );

        $sessions = StudyPracticeSession::query()
            ->withCount('attempts')
            ->whereNotNull('selected_questions')
            ->latest('id')
            ->take(self::SESSION_SCAN_LIMIT)
            ->get([
                'id',
                'selected_questions',
            ]);

        $selectedReuseCount = 0;
        $assessedReuseCount = 0;
        $reusedQuestionIds = [];
        $currentSessionCount = 0;

        foreach ($sessions as $session) {
            $selectedIds = collect(
                $session->selected_questions ?? [],
            )
                ->filter(fn ($item) => is_array($item))
                ->pluck('question_id')
                ->filter(
                    fn ($id) =>
                        is_numeric($id)
                        && isset($idSet[(int) $id]),
                )
                ->map(fn ($id) => (int) $id)
                ->values();

            if ($selectedIds->isEmpty()) {
                continue;
            }

            $selectedReuseCount += $selectedIds->count();

            if ((int) $session->attempts_count > 0) {
                $assessedReuseCount +=
                    $selectedIds->count();
            }

            foreach ($selectedIds as $questionId) {
                $reusedQuestionIds[$questionId] = true;
            }

            if (
                $currentSession
                && (int) $session->id
                    === (int) $currentSession->id
            ) {
                $currentSessionCount =
                    $selectedIds->count();
            }
        }

        return [
            'reused_question_count' =>
                count($reusedQuestionIds),
            'selected_reuse_count' =>
                $selectedReuseCount,
            'assessed_reuse_count' =>
                $assessedReuseCount,
            'current_session_promoted_candidate_count' =>
                $currentSessionCount,
        ];
    }

    /**
     * @param array<string,mixed> $strategy
     */
    private function examProfileKey(
        ?StudyPracticeSession $session,
        array $strategy,
    ): ?string {
        $key = trim(
            (string) data_get(
                $strategy,
                'exam_profile.key',
                '',
            ),
        );

        if ($key === '') {
            $key = trim(
                (string) data_get(
                    $session?->selection_context,
                    'strategy.exam_profile.key',
                    '',
                ),
            );
        }

        return $key !== ''
            ? mb_substr($key, 0, 80)
            : null;
    }

    private function note(
        string $status,
        int $reviewedCount,
        int $promotedCount,
        ?int $promotionRate,
        int $assessedReuseCount,
        int $appliedAdjustment,
    ): string {
        if ($status === 'none') {
            return 'この試験ProfileではQuestion Candidate運営実績がまだありません。';
        }

        if ($status === 'observing') {
            return 'Human Reviewは'
                .$reviewedCount
                .'件です。'
                .self::MIN_REVIEWED_FOR_ADJUSTMENT
                .'件までは観測だけ行い、Reliability点数は補正しません。';
        }

        return 'Human Review '
            .$promotedCount
            .'/'
            .$reviewedCount
            .'件を昇格'
            .($promotionRate !== null
                ? '（'.$promotionRate.'%）'
                : '')
            .'、昇格問題の採点済み再利用 '
            .$assessedReuseCount
            .'回を観測しています。出題内容への補正は'
            .($appliedAdjustment >= 0 ? '+' : '')
            .$appliedAdjustment
            .'点です。';
    }

    /**
     * @return array<string,mixed>
     */
    private function unavailable(): array
    {
        return [
            'status' => 'unavailable',
            'exam_profile_key' => null,
            'candidate_count' => 0,
            'generation_occurrence_count' => 0,
            'pending_count' => 0,
            'reviewed_count' => 0,
            'promoted_count' => 0,
            'rejected_count' => 0,
            'promotion_rate_percent' => null,
            'reused_question_count' => 0,
            'selected_reuse_count' => 0,
            'assessed_reuse_count' => 0,
            'current_session_promoted_candidate_count' => 0,
            'evidence_strength_percent' => 0,
            'raw_adjustment' => 0,
            'applied_adjustment' => 0,
            'minimum_reviewed_for_adjustment' =>
                self::MIN_REVIEWED_FOR_ADJUSTMENT,
            'session_scan_limit' =>
                self::SESSION_SCAN_LIMIT,
            'note' => 'exam profileが特定できないため、Question Candidate運営実績はReliabilityへ反映していません。',
        ];
    }
}
