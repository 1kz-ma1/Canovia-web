<?php

namespace App\Intelligence\Study;

use App\Models\Plan;
use App\Models\Task;
use App\Services\StudyActivityPolicyService;
use Illuminate\Support\Collection;

final class StudyMethodOutcomeCalibrationService
{
    private const MIN_OBSERVATIONS = 3;

    private const MAX_SAMPLE = 5;

    private const MEANINGFUL_DELTA = 5;

    private const MAX_ADJUSTMENT = 5;

    private const MIN_DIRECTION_RATIO = 2 / 3;

    private const ELIGIBLE_METHODS = [
        StudyActivityPolicyService::RECALL,
        StudyActivityPolicyService::RESOURCE_STUDY,
        StudyActivityPolicyService::LISTENING,
        StudyActivityPolicyService::DICTATION,
        StudyActivityPolicyService::SHADOWING,
    ];

    public function __construct(
        private readonly StudyActivityOutcomeObservationService $outcomes,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function project(
        Plan $plan,
        Task $task,
        ?int $userId,
        ?string $actorToken,
    ): array {
        $projection = $this->outcomes->project(
            $plan,
            $task,
            $userId,
            $actorToken,
        );

        $observations = collect(
            $projection['observations'] ?? [],
        )
            ->filter(fn ($item) => is_array($item))
            ->values();

        $methods = collect(self::ELIGIBLE_METHODS)
            ->mapWithKeys(function (string $key) use (
                $observations,
            ) {
                return [
                    $key => $this->signalFor(
                        $key,
                        $observations,
                    ),
                ];
            })
            ->all();

        return [
            'version' => 'v1',
            'minimum_observations' =>
                self::MIN_OBSERVATIONS,
            'maximum_sample' => self::MAX_SAMPLE,
            'meaningful_delta_points' =>
                self::MEANINGFUL_DELTA,
            'maximum_adjustment' =>
                self::MAX_ADJUSTMENT,
            'primary_switch_allowed' => false,
            'question_practice_eligible' => false,
            'valid_observation_pair_count' =>
                (int) (
                    $projection[
                        'valid_observation_pair_count'
                    ]
                    ?? 0
                ),
            'ambiguous_interval_count' =>
                (int) (
                    $projection[
                        'ambiguous_interval_count'
                    ]
                    ?? 0
                ),
            'stale_interval_count' =>
                (int) (
                    $projection[
                        'stale_interval_count'
                    ]
                    ?? 0
                ),
            'methods' => $methods,
            'note' =>
                '実利用の前後差を小幅なfit補正へ使いますが、因果効果とはみなさずPrimary Methodは自動変更しません。',
        ];
    }

    /**
     * @param Collection<int,array<string,mixed>> $observations
     * @return array<string,mixed>
     */
    private function signalFor(
        string $methodKey,
        Collection $observations,
    ): array {
        $methodObservations = $observations
            ->filter(
                fn (array $item) =>
                    ($item['activity_key'] ?? null)
                    === $methodKey,
            )
            ->values();

        $observationCount =
            $methodObservations->count();

        $sample = $methodObservations
            ->slice(
                max(
                    0,
                    $observationCount
                        - self::MAX_SAMPLE,
                ),
            )
            ->values();

        $deltas = $sample
            ->pluck('score_delta')
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->values();

        $sampleCount = $deltas->count();
        $median = $this->median($deltas);

        $positiveCount = $deltas
            ->filter(
                fn (int $delta) =>
                    $delta
                    >= self::MEANINGFUL_DELTA,
            )
            ->count();
        $negativeCount = $deltas
            ->filter(
                fn (int $delta) =>
                    $delta
                    <= -self::MEANINGFUL_DELTA,
            )
            ->count();
        $neutralCount =
            $sampleCount
            - $positiveCount
            - $negativeCount;

        $dominantCount = max(
            $positiveCount,
            $negativeCount,
        );
        $directionRatio = $sampleCount > 0
            ? $dominantCount / $sampleCount
            : 0.0;

        $rawAdjustment = $median === null
            ? 0
            : max(
                -self::MAX_ADJUSTMENT,
                min(
                    self::MAX_ADJUSTMENT,
                    (int) round(
                        $median
                        / self::MEANINGFUL_DELTA,
                    ),
                ),
            );

        $sampleStrength = min(
            1.0,
            $observationCount
                / self::MAX_SAMPLE,
        );

        $status = 'observing';
        $appliedAdjustment = 0;

        if (
            $observationCount
            >= self::MIN_OBSERVATIONS
        ) {
            $hasOpposingMeaningfulDirections =
                $positiveCount > 0
                && $negativeCount > 0;

            if (
                $hasOpposingMeaningfulDirections
                && $directionRatio
                    < self::MIN_DIRECTION_RATIO
            ) {
                $status = 'mixed';
            } elseif (
                $median === null
                || abs($median)
                    < self::MEANINGFUL_DELTA
            ) {
                $status = 'neutral';
            } elseif (
                $directionRatio
                < self::MIN_DIRECTION_RATIO
            ) {
                $status = 'mixed';
            } else {
                $status = 'active';
                $appliedAdjustment = max(
                    -self::MAX_ADJUSTMENT,
                    min(
                        self::MAX_ADJUSTMENT,
                        (int) round(
                            $rawAdjustment
                            * $sampleStrength,
                        ),
                    ),
                );
            }
        }

        return [
            'method_key' => $methodKey,
            'status' => $status,
            'observation_count' =>
                $observationCount,
            'sample_count' => $sampleCount,
            'median_delta' => $median,
            'positive_count' => $positiveCount,
            'negative_count' => $negativeCount,
            'neutral_count' => $neutralCount,
            'direction_ratio_percent' =>
                (int) round(
                    $directionRatio * 100,
                ),
            'raw_adjustment' =>
                $rawAdjustment,
            'sample_strength_percent' =>
                (int) round(
                    $sampleStrength * 100,
                ),
            'applied_adjustment' =>
                $appliedAdjustment,
            'note' => $this->note(
                $status,
                $observationCount,
                $sampleCount,
                $median,
                $directionRatio,
                $appliedAdjustment,
            ),
        ];
    }

    /**
     * @param Collection<int,int> $values
     */
    private function median(
        Collection $values,
    ): ?int {
        if ($values->isEmpty()) {
            return null;
        }

        $sorted = $values
            ->sort()
            ->values();
        $count = $sorted->count();
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (int) $sorted[$middle];
        }

        return (int) round(
            (
                (int) $sorted[$middle - 1]
                + (int) $sorted[$middle]
            ) / 2,
        );
    }

    private function note(
        string $status,
        int $observationCount,
        int $sampleCount,
        ?int $median,
        float $directionRatio,
        int $adjustment,
    ): string {
        return match ($status) {
            'active' =>
                '直近'
                .$sampleCount
                .'比較の中央値 '
                .($median !== null && $median > 0
                    ? '+'
                    : '')
                .($median ?? 0)
                .'pt、方向一致 '
                .(int) round(
                    $directionRatio * 100,
                )
                .'%を観測。fitへ'
                .($adjustment >= 0 ? '+' : '')
                .$adjustment
                .'ptだけ反映します。',
            'mixed' =>
                '比較は'
                .$observationCount
                .'件ありますが、前後差の方向が安定していないためfit補正しません。',
            'neutral' =>
                '比較は'
                .$observationCount
                .'件ありますが、中央値の前後差が±'
                .(self::MEANINGFUL_DELTA - 1)
                .'pt以内のためfit補正しません。',
            default =>
                '比較'
                .$observationCount
                .'件。'
                .self::MIN_OBSERVATIONS
                .'件までは観測だけ行いfit補正しません。',
        };
    }
}
