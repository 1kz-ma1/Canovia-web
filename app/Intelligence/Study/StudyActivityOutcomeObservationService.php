<?php

namespace App\Intelligence\Study;

use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Services\StudyActivityPolicyService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class StudyActivityOutcomeObservationService
{
    private const MAX_INTERVAL_DAYS = 14;

    private const TRACKED_TYPES = [
        'study_practice_assessed',
        'study_recall_reviewed',
        'study_language_activity_completed',
        'study_resource_study_completed',
    ];

    /**
     * @return array<string,mixed>
     */
    public function project(
        Plan $plan,
        Task $task,
        ?int $userId,
        ?string $actorToken,
    ): array {
        $evidence = $this->query(
            $plan,
            $task,
            $userId,
            $actorToken,
        )
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->values();

        $methods = $this->methodDefinitions();

        $usageCounts = collect(array_keys($methods))
            ->mapWithKeys(fn (string $key) => [$key => 0])
            ->all();

        foreach ($evidence as $item) {
            $activityKey = $this->activityKey($item);

            if (
                $activityKey !== null
                && array_key_exists(
                    $activityKey,
                    $usageCounts,
                )
            ) {
                $usageCounts[$activityKey]++;
            }

            if (
                $item->type === 'study_practice_assessed'
                && $this->score($item) !== null
            ) {
                $usageCounts[
                    StudyActivityPolicyService::QUESTION_PRACTICE
                ]++;
            }
        }

        $practicePositions = $evidence
            ->map(function (
                TaskEvidence $item,
                int $index,
            ) {
                $score = $this->score($item);

                if (
                    $item->type
                        !== 'study_practice_assessed'
                    || $score === null
                ) {
                    return null;
                }

                return [
                    'index' => $index,
                    'evidence' => $item,
                    'score' => $score,
                ];
            })
            ->filter()
            ->values();

        $observations = collect();
        $ambiguousIntervals = 0;
        $staleIntervals = 0;

        for (
            $position = 1;
            $position < $practicePositions->count();
            $position++
        ) {
            $before = $practicePositions[$position - 1];
            $after = $practicePositions[$position];

            /** @var TaskEvidence $beforeEvidence */
            $beforeEvidence = $before['evidence'];
            /** @var TaskEvidence $afterEvidence */
            $afterEvidence = $after['evidence'];

            if (
                $this->tooFarApart(
                    $beforeEvidence,
                    $afterEvidence,
                )
            ) {
                $staleIntervals++;
                continue;
            }

            $between = $evidence
                ->slice(
                    (int) $before['index'] + 1,
                    (int) $after['index']
                        - (int) $before['index']
                        - 1,
                )
                ->map(
                    fn (TaskEvidence $item) =>
                        $this->intervalActivityKey($item),
                )
                ->filter()
                ->unique()
                ->values();

            if (
                $between->count() > 1
                || $between->first() === '__unknown__'
            ) {
                $ambiguousIntervals++;
                continue;
            }

            $activityKey = $between->first()
                ?: StudyActivityPolicyService::QUESTION_PRACTICE;

            if (! array_key_exists($activityKey, $methods)) {
                continue;
            }

            $observations->push([
                'activity_key' => $activityKey,
                'before_score' => (int) $before['score'],
                'after_score' => (int) $after['score'],
                'score_delta' =>
                    (int) $after['score']
                    - (int) $before['score'],
                'before_evidence_id' =>
                    (int) $beforeEvidence->id,
                'after_evidence_id' =>
                    (int) $afterEvidence->id,
                'before_at' =>
                    $beforeEvidence->occurred_at?->toIso8601String(),
                'after_at' =>
                    $afterEvidence->occurred_at?->toIso8601String(),
            ]);
        }

        $methodRows = collect($methods)
            ->map(function (
                array $definition,
                string $key,
            ) use (
                $usageCounts,
                $observations,
            ) {
                $activityObservations = $observations
                    ->where('activity_key', $key)
                    ->values();

                $latest = $activityObservations->last();

                return [
                    ...$definition,
                    'key' => $key,
                    'usage_count' =>
                        (int) ($usageCounts[$key] ?? 0),
                    'observation_count' =>
                        $activityObservations->count(),
                    'average_before_score' =>
                        $this->average(
                            $activityObservations,
                            'before_score',
                        ),
                    'average_after_score' =>
                        $this->average(
                            $activityObservations,
                            'after_score',
                        ),
                    'average_score_delta' =>
                        $this->average(
                            $activityObservations,
                            'score_delta',
                        ),
                    'latest_before_score' =>
                        $latest['before_score'] ?? null,
                    'latest_after_score' =>
                        $latest['after_score'] ?? null,
                    'latest_score_delta' =>
                        $latest['score_delta'] ?? null,
                    'latest_observed_at' =>
                        $latest['after_at'] ?? null,
                    'measurement_status' =>
                        $activityObservations->isNotEmpty()
                            ? 'observed'
                            : 'waiting',
                ];
            })
            ->values()
            ->all();

        return [
            'version' => 'v1',
            'has_comparison' => $observations->isNotEmpty(),
            'practice_assessment_count' =>
                $practicePositions->count(),
            'valid_observation_pair_count' =>
                $observations->count(),
            'ambiguous_interval_count' =>
                $ambiguousIntervals,
            'stale_interval_count' =>
                $staleIntervals,
            'max_interval_days' =>
                self::MAX_INTERVAL_DAYS,
            'methods' => $methodRows,
            'observations' =>
                $observations->values()->all(),
        ];
    }

    private function query(
        Plan $plan,
        Task $task,
        ?int $userId,
        ?string $actorToken,
    ): Builder {
        $query = TaskEvidence::query()
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id)
            ->whereIn('type', self::TRACKED_TYPES);

        if ($userId !== null) {
            return $query->where(
                'user_id',
                $userId,
            );
        }

        return $query
            ->whereNull('user_id')
            ->where(
                'actor_token',
                (string) $actorToken,
            );
    }

    private function intervalActivityKey(
        TaskEvidence $evidence,
    ): ?string {
        if (
            $evidence->type
            === 'study_language_activity_completed'
        ) {
            return $this->activityKey($evidence)
                ?? '__unknown__';
        }

        return $this->activityKey($evidence);
    }

    private function activityKey(
        TaskEvidence $evidence,
    ): ?string {
        if ($evidence->type === 'study_recall_reviewed') {
            return StudyActivityPolicyService::RECALL;
        }

        if (
            $evidence->type
            === 'study_resource_study_completed'
        ) {
            return StudyActivityPolicyService::RESOURCE_STUDY;
        }

        if (
            $evidence->type
            === 'study_language_activity_completed'
        ) {
            $key = trim(
                (string) data_get(
                    $evidence->metadata,
                    'activity_type',
                    '',
                ),
            );

            return in_array(
                $key,
                [
                    StudyActivityPolicyService::LISTENING,
                    StudyActivityPolicyService::DICTATION,
                    StudyActivityPolicyService::SHADOWING,
                ],
                true,
            )
                ? $key
                : null;
        }

        return null;
    }

    private function score(
        TaskEvidence $evidence,
    ): ?int {
        $value = data_get(
            $evidence->metadata,
            'score_percent',
        );

        if (
            ! is_int($value)
            && ! (
                is_string($value)
                && preg_match(
                    '/^\d{1,3}$/',
                    $value,
                ) === 1
            )
        ) {
            return null;
        }

        $score = (int) $value;

        return $score >= 0 && $score <= 100
            ? $score
            : null;
    }

    private function tooFarApart(
        TaskEvidence $before,
        TaskEvidence $after,
    ): bool {
        $beforeAt = $before->occurred_at;
        $afterAt = $after->occurred_at;

        if (
            ! $beforeAt instanceof CarbonInterface
            || ! $afterAt instanceof CarbonInterface
        ) {
            return true;
        }

        return $beforeAt->diffInSeconds(
            $afterAt,
            false,
        ) > self::MAX_INTERVAL_DAYS * 86400;
    }

    private function average(
        Collection $observations,
        string $key,
    ): ?int {
        if ($observations->isEmpty()) {
            return null;
        }

        return (int) round(
            $observations->avg(
                fn (array $item) =>
                    (int) $item[$key],
            ),
        );
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function methodDefinitions(): array
    {
        return [
            StudyActivityPolicyService::QUESTION_PRACTICE => [
                'label' => 'Question Practice',
                'short_label' => '問題演習',
                'icon' => '✦',
            ],
            StudyActivityPolicyService::RECALL => [
                'label' => 'Recall',
                'short_label' => 'Recall',
                'icon' => '◉',
            ],
            StudyActivityPolicyService::RESOURCE_STUDY => [
                'label' => 'Resource Study',
                'short_label' => '教材学習',
                'icon' => '⌘',
            ],
            StudyActivityPolicyService::LISTENING => [
                'label' => 'Listening',
                'short_label' => 'Listening',
                'icon' => '◌',
            ],
            StudyActivityPolicyService::DICTATION => [
                'label' => 'Dictation',
                'short_label' => 'Dictation',
                'icon' => '✎',
            ],
            StudyActivityPolicyService::SHADOWING => [
                'label' => 'Shadowing',
                'short_label' => 'Shadowing',
                'icon' => '≈',
            ],
        ];
    }
}
