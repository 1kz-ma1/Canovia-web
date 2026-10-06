<?php

namespace App\Intelligence\Study;

use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Services\StudyExamConvergencePolicyService;
use Illuminate\Support\Collection;

final class StudyWeaknessInterventionOutcomeService
{
    private const ATTEMPT_LIMIT = 120;

    private const SAMPLE_LIMIT = 5;

    private const MIN_SAMPLE = 2;

    private const IMPROVEMENT_BAND = 15;

    /**
     * @return array<string,mixed>
     */
    public function project(
        Plan $plan,
        Task $task,
        ?int $userId,
        ?string $actorToken,
    ): array {
        $attempts = $this->attempts(
            $plan,
            $task,
            $userId,
            $actorToken,
        );

        $historyTruncated =
            $attempts->count() > self::ATTEMPT_LIMIT;

        $attempts = $attempts
            ->take(self::ATTEMPT_LIMIT)
            ->sortBy([
                ['created_at', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        $rows = $attempts
            ->map(function (
                StudyPracticeAttempt $attempt,
                int $index,
            ) {
                $strategy = $this->strategy($attempt);
                $phase = $this->phase($strategy);
                $events = $this->events(
                    $attempt,
                    $phase,
                    $index,
                );

                return [
                    'index' => $index,
                    'attempt' => $attempt,
                    'strategy' => $strategy,
                    'phase' => $phase,
                    'targets' =>
                        $phase === StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT
                            ? $this->targetTopics($strategy)
                            : [],
                    'events' => $events,
                ];
            })
            ->values();

        $cycles = $this->cycles($rows);

        if ($cycles->isEmpty()) {
            return [
                'version' => 'v1',
                'available' => false,
                'reason' => 'no_reinforcement_cycle',
                'attempt_limit' => self::ATTEMPT_LIMIT,
                'attempts_considered' => $attempts->count(),
                'history_truncated' => $historyTruncated,
                'topics' => [],
                'note' =>
                    'Focused weakness reinforcementのbefore / afterを観測します。補強中の得点だけでは改善判定しません。',
            ];
        }

        $events = $rows
            ->flatMap(
                fn (array $row) =>
                    $row['events'],
            )
            ->values();

        $byTopic = $cycles
            ->groupBy('topic_key')
            ->map(
                fn (Collection $topicCycles) =>
                    $topicCycles
                        ->sortBy('start_index')
                        ->values(),
            );

        $topics = $byTopic
            ->map(function (
                Collection $topicCycles,
            ) use ($events) {
                $latestIndex =
                    $topicCycles->count() - 1;
                $cycle = $topicCycles[
                    $latestIndex
                ];
                $nextStart = null;

                return $this->summarizeCycle(
                    $cycle,
                    $events,
                    $nextStart,
                    $topicCycles->count(),
                );
            })
            ->sortByDesc('intervention_started_at')
            ->take(5)
            ->values();

        return [
            'version' => 'v1',
            'available' => $topics->isNotEmpty(),
            'reason' =>
                $topics->isNotEmpty()
                    ? 'observed_reinforcement_cycles'
                    : 'no_reinforcement_cycle',
            'attempt_limit' => self::ATTEMPT_LIMIT,
            'attempts_considered' =>
                $attempts->count(),
            'history_truncated' =>
                $historyTruncated,
            'minimum_sample_questions' =>
                self::MIN_SAMPLE,
            'sample_limit' =>
                self::SAMPLE_LIMIT,
            'improvement_band_points' =>
                self::IMPROVEMENT_BAND,
            'topics' => $topics->all(),
            'note' =>
                '補強前後のBroad Practiceを比較する観測値です。改善・悪化の因果を証明するものではなく、RoutingやMasteryを自動変更しません。',
        ];
    }

    private function attempts(
        Plan $plan,
        Task $task,
        ?int $userId,
        ?string $actorToken,
    ): Collection {
        $query = StudyPracticeAttempt::query()
            ->with('practiceSession')
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        } else {
            $query
                ->whereNull('user_id')
                ->where(
                    'actor_token',
                    (string) $actorToken,
                );
        }

        return $query
            ->latest('created_at')
            ->latest('id')
            ->take(self::ATTEMPT_LIMIT + 1)
            ->get();
    }

    /**
     * @param Collection<int,array<string,mixed>> $rows
     * @return Collection<int,array<string,mixed>>
     */
    private function cycles(
        Collection $rows,
    ): Collection {
        $open = [];
        $cycles = collect();

        foreach ($rows as $row) {
            $phase = (string) $row['phase'];
            $index = (int) $row['index'];
            /** @var StudyPracticeAttempt $attempt */
            $attempt = $row['attempt'];

            if (
                $phase
                === StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT
            ) {
                foreach (
                    (array) $row['targets']
                    as $topicKey => $topic
                ) {
                    if (! isset($open[$topicKey])) {
                        $open[$topicKey] = [
                            'topic_key' => $topicKey,
                            'topic' => $topic,
                            'start_index' => $index,
                            'last_targeted_index' =>
                                $index,
                            'intervention_started_at' =>
                                $attempt->created_at,
                            'intervention_ended_at' =>
                                null,
                            'targeted_attempt_ids' =>
                                [],
                            'in_progress' => true,
                            'post_start_index' => null,
                        ];
                    }

                    $open[$topicKey][
                        'last_targeted_index'
                    ] = $index;
                    $open[$topicKey][
                        'intervention_ended_at'
                    ] = $attempt->created_at;
                    $open[$topicKey][
                        'targeted_attempt_ids'
                    ][] = (int) $attempt->id;
                    $open[$topicKey][
                        'targeted_attempt_ids'
                    ] = array_values(
                        array_unique(
                            $open[$topicKey][
                                'targeted_attempt_ids'
                            ],
                        ),
                    );
                }

                continue;
            }

            if (! in_array(
                $phase,
                [
                    StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
                    StudyExamConvergencePolicyService::PHASE_EXAM_MODE,
                ],
                true,
            )) {
                continue;
            }

            foreach ($open as $topicKey => $cycle) {
                $cycle['in_progress'] = false;
                $cycle['post_start_index'] =
                    $index;
                $cycles->push($cycle);
                unset($open[$topicKey]);
            }
        }

        foreach ($open as $cycle) {
            $cycles->push($cycle);
        }

        return $cycles
            ->sortBy('start_index')
            ->values();
    }

    /**
     * @param Collection<int,array<string,mixed>> $events
     * @return array<string,mixed>
     */
    private function summarizeCycle(
        array $cycle,
        Collection $events,
        ?int $nextCycleStart,
        int $cycleCount,
    ): array {
        $topicKey = (string) $cycle[
            'topic_key'
        ];
        $startIndex = (int) $cycle[
            'start_index'
        ];
        $lastTargetedIndex = (int) $cycle[
            'last_targeted_index'
        ];

        $baseline = $events
            ->filter(
                fn (array $event) =>
                    (int) $event[
                        'attempt_index'
                    ] < $startIndex
                    && $event['phase']
                        !== StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT
                    && $this->eventMatches(
                        $event,
                        $topicKey,
                    ),
            )
            ->take(-1 * self::SAMPLE_LIMIT)
            ->values();

        $intervention = $events
            ->filter(
                fn (array $event) =>
                    (int) $event[
                        'attempt_index'
                    ] >= $startIndex
                    && (int) $event[
                        'attempt_index'
                    ] <= $lastTargetedIndex
                    && $event['phase']
                        === StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT
                    && $this->eventMatches(
                        $event,
                        $topicKey,
                    ),
            )
            ->values();

        $after = collect();

        if (! (bool) $cycle['in_progress']) {
            $after = $events
                ->filter(
                    fn (array $event) =>
                        (int) $event[
                            'attempt_index'
                        ] >= (int) (
                            $cycle[
                                'post_start_index'
                            ]
                            ?? PHP_INT_MAX
                        )
                        && (
                            $nextCycleStart === null
                            || (int) $event[
                                'attempt_index'
                            ] < $nextCycleStart
                        )
                        && in_array(
                            $event['phase'],
                            [
                                StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
                                StudyExamConvergencePolicyService::PHASE_EXAM_MODE,
                            ],
                            true,
                        )
                        && $this->eventMatches(
                            $event,
                            $topicKey,
                        ),
                )
                ->take(self::SAMPLE_LIMIT)
                ->values();
        }

        $baselineSummary =
            $this->summarizeEvents($baseline);
        $interventionSummary =
            $this->summarizeEvents($intervention);
        $afterSummary =
            $this->summarizeEvents($after);

        $baselineReady =
            $this->sampleReady(
                $baselineSummary,
            );
        $afterReady =
            $this->sampleReady(
                $afterSummary,
            );

        $delta = null;
        $status = 'waiting_baseline';
        $confidence = 'insufficient';

        if ((bool) $cycle['in_progress']) {
            $status = 'reinforcing';
        } elseif (! $baselineReady) {
            $status = 'waiting_baseline';
        } elseif (! $afterReady) {
            $status = 'waiting_recheck';
        } else {
            $delta =
                (int) $afterSummary[
                    'observed_correct_rate_percent'
                ]
                - (int) $baselineSummary[
                    'observed_correct_rate_percent'
                ];

            $status = match (true) {
                $delta >= self::IMPROVEMENT_BAND
                    => 'improved_observation',
                $delta <= -self::IMPROVEMENT_BAND
                    => 'regressed_observation',
                default => 'stable_observation',
            };

            $confidence = (
                (int) $baselineSummary[
                    'question_count'
                ] >= 4
                && (int) $afterSummary[
                    'question_count'
                ] >= 4
            )
                ? 'moderate'
                : 'low';
        }

        return [
            'topic' => (string) $cycle['topic'],
            'topic_key' => $topicKey,
            'cycle_count' => $cycleCount,
            'status' => $status,
            'confidence' => $confidence,
            'baseline' => $baselineSummary,
            'intervention' => [
                ...$interventionSummary,
                'targeted_attempt_count' =>
                    count(
                        $cycle[
                            'targeted_attempt_ids'
                        ] ?? [],
                    ),
            ],
            'after' => $afterSummary,
            'observed_delta_points' => $delta,
            'intervention_started_at' =>
                $cycle[
                    'intervention_started_at'
                ],
            'intervention_ended_at' =>
                $cycle[
                    'intervention_ended_at'
                ],
            'note' => $this->statusNote(
                $status,
                $delta,
            ),
        ];
    }

    /**
     * @param Collection<int,array<string,mixed>> $events
     * @return array<string,mixed>
     */
    private function summarizeEvents(
        Collection $events,
    ): array {
        $count = $events->count();
        $correct = $events
            ->where(
                'correctness',
                'correct',
            )
            ->count();
        $partial = $events
            ->where(
                'correctness',
                'partial',
            )
            ->count();
        $incorrect = $events
            ->where(
                'correctness',
                'incorrect',
            )
            ->count();

        return [
            'question_count' => $count,
            'unique_question_count' =>
                $events
                    ->pluck('question_ref')
                    ->filter()
                    ->unique()
                    ->count(),
            'correct_count' => $correct,
            'partial_count' => $partial,
            'incorrect_count' => $incorrect,
            'observed_correct_rate_percent' =>
                $count > 0
                    ? (int) round(
                        ($correct / $count)
                        * 100,
                    )
                    : null,
        ];
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function sampleReady(
        array $summary,
    ): bool {
        return (int) (
            $summary['question_count']
            ?? 0
        ) >= self::MIN_SAMPLE
            && (int) (
                $summary[
                    'unique_question_count'
                ]
                ?? 0
            ) >= self::MIN_SAMPLE;
    }

    /**
     * @return array<string,string>
     */
    private function targetTopics(
        array $strategy,
    ): array {
        $topics = collect([
            ...((array) data_get(
                $strategy,
                'weakness_control.active_topics',
                [],
            )),
            ...((array) data_get(
                $strategy,
                'weakness_priority.primary_topics',
                [],
            )),
            ...((array) (
                $strategy['focus_topics']
                ?? []
            )),
        ])
            ->filter(
                fn ($topic) =>
                    is_scalar($topic)
                    && trim(
                        (string) $topic,
                    ) !== '',
            )
            ->map(
                fn ($topic) =>
                    trim((string) $topic),
            )
            ->unique(
                fn (string $topic) =>
                    $this->key($topic),
            )
            ->values();

        return $topics
            ->mapWithKeys(
                fn (string $topic) => [
                    $this->key($topic)
                        => $topic,
                ],
            )
            ->all();
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function events(
        StudyPracticeAttempt $attempt,
        string $phase,
        int $attemptIndex,
    ): Collection {
        $topicMap = $this->questionTopicMap(
            $attempt,
        );
        $seen = [];

        return collect(data_get(
            $attempt->assessment,
            'question_feedback',
            [],
        ))
            ->filter(
                fn ($feedback) =>
                    is_array($feedback),
            )
            ->values()
            ->map(function (
                array $feedback,
                int $feedbackIndex,
            ) use (
                $attempt,
                $phase,
                $attemptIndex,
                $topicMap,
                &$seen,
            ) {
                $questionRef = trim(
                    (string) (
                        $feedback['question_id']
                        ?? ''
                    ),
                );
                $correctness = trim(
                    (string) (
                        $feedback['correctness']
                        ?? ''
                    ),
                );

                if (
                    $questionRef === ''
                    || isset($seen[$questionRef])
                    || ! in_array(
                        $correctness,
                        [
                            'correct',
                            'partial',
                            'incorrect',
                        ],
                        true,
                    )
                ) {
                    return null;
                }

                $seen[$questionRef] = true;
                $topics = $topicMap[
                    $questionRef
                ] ?? [];

                if ($topics === []) {
                    return null;
                }

                return [
                    'attempt_id' =>
                        (int) $attempt->id,
                    'attempt_index' =>
                        $attemptIndex,
                    'feedback_index' =>
                        $feedbackIndex,
                    'attempt_created_at' =>
                        $attempt->created_at,
                    'phase' => $phase,
                    'question_ref' =>
                        $questionRef,
                    'correctness' =>
                        $correctness,
                    'topics' => $topics,
                    'topic_keys' =>
                        collect($topics)
                            ->map(
                                fn (string $topic) =>
                                    $this->key($topic),
                            )
                            ->filter()
                            ->unique()
                            ->values()
                            ->all(),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return array<string,array<int,string>>
     */
    private function questionTopicMap(
        StudyPracticeAttempt $attempt,
    ): array {
        $map = [];

        foreach (
            (array) ($attempt->questions ?? [])
            as $question
        ) {
            if (! is_array($question)) {
                continue;
            }

            $ref = trim(
                (string) (
                    $question['id']
                    ?? ''
                ),
            );

            if ($ref === '') {
                continue;
            }

            foreach ([
                $question['topic'] ?? null,
                $question['parent_topic'] ?? null,
            ] as $topic) {
                $this->putTopic(
                    $map,
                    $ref,
                    $topic,
                );
            }
        }

        $session = $attempt->practiceSession;

        if (
            $session
            instanceof StudyPracticeSession
        ) {
            foreach (
                (array) (
                    $session->selected_questions
                    ?? []
                )
                as $selected
            ) {
                if (! is_array($selected)) {
                    continue;
                }

                $ref = trim(
                    (string) (
                        $selected[
                            'question_ref'
                        ]
                        ?? ''
                    ),
                );

                if ($ref === '') {
                    continue;
                }

                foreach ([
                    $selected[
                        'selection_topic'
                    ] ?? null,
                    $selected[
                        'selection_parent_topic'
                    ] ?? null,
                ] as $topic) {
                    $this->putTopic(
                        $map,
                        $ref,
                        $topic,
                    );
                }
            }
        }

        return collect($map)
            ->map(
                fn (array $topics) =>
                    collect($topics)
                        ->filter()
                        ->unique(
                            fn (string $topic) =>
                                $this->key($topic),
                        )
                        ->values()
                        ->all(),
            )
            ->all();
    }

    /**
     * @param array<string,array<int,string>> $map
     */
    private function putTopic(
        array &$map,
        string $ref,
        mixed $topic,
    ): void {
        if (! is_scalar($topic)) {
            return;
        }

        $topic = trim((string) $topic);

        if ($topic === '') {
            return;
        }

        $map[$ref] ??= [];
        $map[$ref][] = $topic;
    }

    /**
     * @param array<string,mixed> $event
     */
    private function eventMatches(
        array $event,
        string $topicKey,
    ): bool {
        return in_array(
            $topicKey,
            (array) (
                $event['topic_keys']
                ?? []
            ),
            true,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function strategy(
        StudyPracticeAttempt $attempt,
    ): array {
        $strategy = data_get(
            $attempt->practiceSession?->selection_context,
            'strategy',
            [],
        );

        return is_array($strategy)
            ? $strategy
            : [];
    }

    private function phase(
        array $strategy,
    ): string {
        $phase = (string) data_get(
            $strategy,
            'learning_phase.phase',
            '',
        );

        if (in_array(
            $phase,
            [
                StudyExamConvergencePolicyService::PHASE_DIAGNOSIS,
                StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT,
                StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
                StudyExamConvergencePolicyService::PHASE_EXAM_MODE,
            ],
            true,
        )) {
            return $phase;
        }

        return match (
            (string) (
                $strategy['key']
                ?? ''
            )
        ) {
            'baseline_assessment' =>
                StudyExamConvergencePolicyService::PHASE_DIAGNOSIS,
            'weakness_reinforcement' =>
                StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT,
            'exam_mode' =>
                StudyExamConvergencePolicyService::PHASE_EXAM_MODE,
            default =>
                StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
        };
    }

    private function statusNote(
        string $status,
        ?int $delta,
    ): string {
        return match ($status) {
            'reinforcing' =>
                '現在補強中です。補強後にBroad Practiceへ戻って同Topicを再確認するまで効果判定しません。',
            'waiting_baseline' =>
                '補強前の比較可能な同Topic問題が2問以上そろっていないため、効果判定を保留します。',
            'waiting_recheck' =>
                '補強は終了しています。Broad Practiceで同Topicを2問以上再確認するとbefore / afterを比較できます。',
            'improved_observation' =>
                '補強後のBroad Practiceで正答観測が'
                .($delta ?? 0)
                .'pt上がっています。因果効果の証明ではありません。',
            'regressed_observation' =>
                '補強後のBroad Practiceで正答観測が'
                .abs($delta ?? 0)
                .'pt下がっています。問題難度など他要因もあるため再観測が必要です。',
            default =>
                '補強前後の差は±'
                .(self::IMPROVEMENT_BAND - 1)
                .'pt以内で、現時点では大きな変化を観測していません。',
        };
    }

    private function key(
        string $topic,
    ): string {
        return mb_strtolower(
            trim(
                preg_replace(
                    '/[\s　]+/u',
                    ' ',
                    $topic,
                )
                ?? $topic,
            ),
        );
    }
}
