<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\StudyRecallCandidate;
use App\Models\StudyRecallItem;
use App\Models\StudyRecallReview;
use App\Models\Task;
use Illuminate\Support\Collection;

final class StudyRecallCandidateOutcomeService
{
    private const CONFIDENCE_BANDS = [
        'high' => ['min' => 85, 'max' => 100],
        'medium' => ['min' => 60, 'max' => 84],
        'low' => ['min' => 0, 'max' => 59],
    ];

    /**
     * @return array<string,mixed>
     */
    public function project(Plan $plan, Task $task): array
    {
        $candidates = StudyRecallCandidate::query()
            ->with([
                'source:id,plan_id,task_id,original_name,source_type',
                'promotedItem.reviews' => fn ($query) =>
                    $query
                        ->orderBy('reviewed_at')
                        ->orderBy('id'),
            ])
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id)
            ->where('status', 'promoted')
            ->whereNotNull('promoted_item_id')
            ->orderBy('id')
            ->get()
            ->filter(
                fn (StudyRecallCandidate $candidate) =>
                    $candidate->promotedItem instanceof StudyRecallItem,
            )
            ->values();

        if ($candidates->isEmpty()) {
            return $this->emptyProjection();
        }

        $itemOutcomes = $candidates
            ->groupBy(fn (StudyRecallCandidate $candidate) =>
                (int) $candidate->promoted_item_id,
            )
            ->map(function (Collection $lineages) {
                /** @var StudyRecallCandidate $candidate */
                $candidate = $lineages->first();
                /** @var StudyRecallItem $item */
                $item = $candidate->promotedItem;

                return $this->itemOutcome($item);
            });

        $candidateRows = $candidates
            ->sortByDesc('id')
            ->values()
            ->map(function (StudyRecallCandidate $candidate) use ($itemOutcomes) {
                $outcome = (array) $itemOutcomes->get(
                    (int) $candidate->promoted_item_id,
                    [],
                );

                return [
                    'candidate_id' => (int) $candidate->id,
                    'source_id' => (int) $candidate->study_recall_source_id,
                    'source_name' => $candidate->source?->original_name
                        ?: $candidate->source?->sourceLabel()
                        ?: '教材',
                    'item_id' => (int) $candidate->promoted_item_id,
                    'prompt' => mb_substr(
                        (string) $candidate->prompt,
                        0,
                        120,
                    ),
                    'confidence' => (int) $candidate->confidence,
                    'confidence_band' => $this->confidenceBand(
                        (int) $candidate->confidence,
                    ),
                    ...$outcome,
                ];
            })
            ->all();

        $uniqueOutcomes = $itemOutcomes->values();
        $reviewCount = (int) $uniqueOutcomes->sum('review_count');
        $successCount = (int) $uniqueOutcomes->sum(
            'successful_recall_count',
        );

        return [
            'has_lineage' => true,
            'aggregate' => [
                'promoted_candidate_count' => $candidates->count(),
                'promoted_item_count' => $uniqueOutcomes->count(),
                'observed_candidate_count' => $candidates
                    ->filter(function (StudyRecallCandidate $candidate) use ($itemOutcomes) {
                        return (int) data_get(
                            $itemOutcomes->get(
                                (int) $candidate->promoted_item_id,
                                [],
                            ),
                            'review_count',
                            0,
                        ) > 0;
                    })
                    ->count(),
                'observed_item_count' => $uniqueOutcomes
                    ->where('review_count', '>', 0)
                    ->count(),
                'retained_item_count' => $uniqueOutcomes
                    ->where('outcome_state', 'retained')
                    ->count(),
                'reinforcement_item_count' => $uniqueOutcomes
                    ->where('outcome_state', 'needs_reinforcement')
                    ->count(),
                'review_count' => $reviewCount,
                'successful_recall_count' => $successCount,
                'self_rated_recall_success_percent' =>
                    $reviewCount > 0
                        ? (int) round(
                            ($successCount / $reviewCount) * 100,
                        )
                        : null,
                'average_candidate_confidence' =>
                    (int) round($candidates->avg('confidence')),
            ],
            'confidence_bands' => $this->confidenceBands(
                $candidates,
                $itemOutcomes,
            ),
            'recent_candidates' => array_slice(
                $candidateRows,
                0,
                8,
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function itemOutcome(StudyRecallItem $item): array
    {
        $reviews = $item->relationLoaded('reviews')
            ? $item->reviews
            : $item->reviews()
                ->orderBy('reviewed_at')
                ->orderBy('id')
                ->get();

        $ratingCounts = [
            'again' => 0,
            'hard' => 0,
            'good' => 0,
            'easy' => 0,
        ];

        foreach ($reviews as $review) {
            if (
                $review instanceof StudyRecallReview
                && array_key_exists($review->rating, $ratingCounts)
            ) {
                $ratingCounts[$review->rating]++;
            }
        }

        $reviewCount = array_sum($ratingCounts);
        $successful = $ratingCounts['hard']
            + $ratingCounts['good']
            + $ratingCounts['easy'];

        return [
            'review_count' => $reviewCount,
            'rating_counts' => $ratingCounts,
            'successful_recall_count' => $successful,
            'self_rated_recall_success_percent' =>
                $reviewCount > 0
                    ? (int) round(
                        ($successful / $reviewCount) * 100,
                    )
                    : null,
            'latest_rating' => $reviews->last()?->rating,
            'lapse_count' => (int) $item->lapse_count,
            'mastered' => $item->isMastered(),
            'outcome_state' => $this->outcomeState(
                $item,
                $reviewCount,
                $ratingCounts['again'],
            ),
        ];
    }

    private function outcomeState(
        StudyRecallItem $item,
        int $reviewCount,
        int $againCount,
    ): string {
        if ($reviewCount === 0) {
            return 'unobserved';
        }

        if ($item->isMastered()) {
            return 'retained';
        }

        if (
            $reviewCount >= 2
            && ($againCount * 2) >= $reviewCount
        ) {
            return 'needs_reinforcement';
        }

        return 'developing';
    }

    private function confidenceBand(int $confidence): string
    {
        if ($confidence >= 85) {
            return 'high';
        }

        if ($confidence >= 60) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param Collection<int,StudyRecallCandidate> $candidates
     * @param Collection<int,array<string,mixed>> $itemOutcomes
     * @return array<string,array<string,int>>
     */
    private function confidenceBands(
        Collection $candidates,
        Collection $itemOutcomes,
    ): array {
        $result = [];

        foreach (self::CONFIDENCE_BANDS as $key => $bounds) {
            $bandCandidates = $candidates
                ->filter(function (StudyRecallCandidate $candidate) use ($bounds) {
                    $confidence = (int) $candidate->confidence;

                    return $confidence >= $bounds['min']
                        && $confidence <= $bounds['max'];
                })
                ->values();

            $itemIds = $bandCandidates
                ->pluck('promoted_item_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            $bandOutcomes = $itemIds
                ->map(fn (int $itemId) =>
                    (array) $itemOutcomes->get($itemId, []),
                )
                ->filter();

            $result[$key] = [
                'candidate_count' => $bandCandidates->count(),
                'item_count' => $itemIds->count(),
                'observed_candidate_count' => $bandCandidates
                    ->filter(function (StudyRecallCandidate $candidate) use ($itemOutcomes) {
                        return (int) data_get(
                            $itemOutcomes->get(
                                (int) $candidate->promoted_item_id,
                                [],
                            ),
                            'review_count',
                            0,
                        ) > 0;
                    })
                    ->count(),
                'retained_candidate_count' => $bandCandidates
                    ->filter(function (StudyRecallCandidate $candidate) use ($itemOutcomes) {
                        return data_get(
                            $itemOutcomes->get(
                                (int) $candidate->promoted_item_id,
                                [],
                            ),
                            'outcome_state',
                        ) === 'retained';
                    })
                    ->count(),
                'review_count' => (int) $bandOutcomes
                    ->sum('review_count'),
                'again_count' => (int) $bandOutcomes
                    ->sum(
                        fn (array $outcome) =>
                            (int) data_get(
                                $outcome,
                                'rating_counts.again',
                                0,
                            ),
                    ),
            ];
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyProjection(): array
    {
        return [
            'has_lineage' => false,
            'aggregate' => [
                'promoted_candidate_count' => 0,
                'promoted_item_count' => 0,
                'observed_candidate_count' => 0,
                'observed_item_count' => 0,
                'retained_item_count' => 0,
                'reinforcement_item_count' => 0,
                'review_count' => 0,
                'successful_recall_count' => 0,
                'self_rated_recall_success_percent' => null,
                'average_candidate_confidence' => null,
            ],
            'confidence_bands' => [
                'high' => [],
                'medium' => [],
                'low' => [],
            ],
            'recent_candidates' => [],
        ];
    }
}
