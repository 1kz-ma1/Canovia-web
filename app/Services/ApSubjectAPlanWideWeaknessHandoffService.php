<?php

namespace App\Services;

use App\Intelligence\Study\ApSubjectACoverageDashboardService;
use App\Models\Plan;
use App\Models\Task;

final class ApSubjectAPlanWideWeaknessHandoffService
{
    private const MIN_PARENT_EXPOSURES = 3;
    private const MIN_UNIQUE_QUESTIONS = 2;
    private const MAX_CORRECT_RATE = 59;
    private const MAX_CANDIDATES = 5;

    public function __construct(
        private readonly ApSubjectACoverageDashboardService $coverage,
        private readonly StudyPracticeCumulativeCheckpointService $checkpoints,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function project(
        Plan $plan,
        Task $task,
        ?int $userId,
        ?string $actorToken,
        ?array $checkpointProjection = null,
    ): array {
        $checkpoint = $checkpointProjection
            ?? $this->checkpoints->project(
                $plan,
                $task,
                $userId,
                $actorToken,
            );

        if (! (bool) ($checkpoint['complete'] ?? false)) {
            return $this->unavailable(
                'checkpoint_not_reached',
                $checkpoint,
            );
        }

        $coverage = $this->coverage->project(
            $plan,
            $userId,
            $actorToken,
        );

        if (! (bool) ($coverage['available'] ?? false)) {
            return $this->unavailable(
                'not_ap_subject_a',
                $checkpoint,
            );
        }

        $candidates = collect(
            $coverage['domains'] ?? [],
        )
            ->filter(fn ($domain) => is_array($domain))
            ->flatMap(function (array $domain) {
                $domainLabel = trim(
                    (string) (
                        $domain['label']
                        ?? ''
                    ),
                );

                return collect(
                    $domain['parent_topics']
                        ?? [],
                )
                    ->filter(
                        fn ($item) =>
                            is_array($item),
                    )
                    ->map(function (
                        array $item,
                    ) use ($domainLabel) {
                        $topic = trim(
                            (string) (
                                $item['label']
                                ?? ''
                            ),
                        );
                        $exposures = (int) (
                            $item[
                                'assessed_exposure_count'
                            ]
                            ?? 0
                        );
                        $uniqueQuestions = (int) (
                            $item[
                                'unique_question_count'
                            ]
                            ?? 0
                        );
                        $rate = $item[
                            'observed_correct_rate_percent'
                        ] ?? null;

                        if (
                            $topic === ''
                            || $exposures
                                < self::MIN_PARENT_EXPOSURES
                            || $uniqueQuestions
                                < self::MIN_UNIQUE_QUESTIONS
                            || ! is_numeric($rate)
                            || (int) $rate
                                > self::MAX_CORRECT_RATE
                        ) {
                            return null;
                        }

                        return [
                            'topic' => $topic,
                            'domain' => $domainLabel,
                            'assessed_exposure_count' =>
                                $exposures,
                            'unique_question_count' =>
                                $uniqueQuestions,
                            'observed_correct_rate_percent' =>
                                (int) $rate,
                            'source' =>
                                'ap_subject_a_plan_wide_coverage',
                        ];
                    })
                    ->filter();
            })
            ->unique(
                fn (array $item) =>
                    mb_strtolower(
                        trim(
                            (string) $item['topic'],
                        ),
                    ),
            )
            ->sort(function (
                array $left,
                array $right,
            ) {
                return [
                    (int) $left[
                        'observed_correct_rate_percent'
                    ],
                    -1 * (int) $left[
                        'assessed_exposure_count'
                    ],
                    (string) $left['topic'],
                ] <=> [
                    (int) $right[
                        'observed_correct_rate_percent'
                    ],
                    -1 * (int) $right[
                        'assessed_exposure_count'
                    ],
                    (string) $right['topic'],
                ];
            })
            ->take(self::MAX_CANDIDATES)
            ->values()
            ->all();

        return [
            'version' => 'v1',
            'available' => true,
            'eligible' => $candidates !== [],
            'reason' =>
                $candidates !== []
                    ? 'eligible'
                    : 'no_confirmed_plan_wide_parent_weakness',
            'checkpoint' => [
                'assessed_question_count' =>
                    (int) (
                        $checkpoint[
                            'assessed_question_count'
                        ]
                        ?? 0
                    ),
                'hundred_reached' =>
                    (bool) data_get(
                        $checkpoint,
                        'checkpoints.100.reached',
                        false,
                    ),
            ],
            'candidate_topics' => $candidates,
            'policy' => [
                'minimum_parent_exposures' =>
                    self::MIN_PARENT_EXPOSURES,
                'minimum_unique_questions' =>
                    self::MIN_UNIQUE_QUESTIONS,
                'maximum_correct_rate_percent' =>
                    self::MAX_CORRECT_RATE,
                'maximum_candidates' =>
                    self::MAX_CANDIDATES,
                'maximum_recheck_questions' =>
                    max(
                        0,
                        (int) config(
                            'study.practice_routing.broad_assessment.weakness_recheck_questions',
                            2,
                        ),
                    ),
            ],
            'note' =>
                '100問Checkpoint後のPlan-wide親Topic観測です。Task-local Routingが許可した場合だけ次回の再確認枠へ使います。',
        ];
    }

    /**
     * @param array<string,mixed> $checkpoint
     * @return array<string,mixed>
     */
    private function unavailable(
        string $reason,
        array $checkpoint,
    ): array {
        return [
            'version' => 'v1',
            'available' => false,
            'eligible' => false,
            'reason' => $reason,
            'checkpoint' => [
                'assessed_question_count' =>
                    (int) (
                        $checkpoint[
                            'assessed_question_count'
                        ]
                        ?? 0
                    ),
                'hundred_reached' =>
                    (bool) data_get(
                        $checkpoint,
                        'checkpoints.100.reached',
                        false,
                    ),
            ],
            'candidate_topics' => [],
            'policy' => [
                'minimum_parent_exposures' =>
                    self::MIN_PARENT_EXPOSURES,
                'minimum_unique_questions' =>
                    self::MIN_UNIQUE_QUESTIONS,
                'maximum_correct_rate_percent' =>
                    self::MAX_CORRECT_RATE,
                'maximum_candidates' =>
                    self::MAX_CANDIDATES,
                'maximum_recheck_questions' =>
                    max(
                        0,
                        (int) config(
                            'study.practice_routing.broad_assessment.weakness_recheck_questions',
                            2,
                        ),
                    ),
            ],
        ];
    }
}
