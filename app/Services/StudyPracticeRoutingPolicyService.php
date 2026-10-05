<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Question;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use Illuminate\Support\Collection;

final class StudyPracticeRoutingPolicyService
{
    private const BLOCKING_ERROR_TYPES = [
        'knowledge_gap',
        'concept_gap',
        'reasoning_gap',
        'condition_reading',
        'unit_error',
        'unknown',
    ];

    private const MINOR_ERROR_TYPES = [
        'calculation_slip',
        'careless',
    ];

    public function __construct(
        private readonly StudyTopicTaxonomyService $taxonomy,
    ) {}

    /**
     * @param Collection<int,mixed> $recentAttempts newest first
     * @param array<string,mixed> $weaknessPriority
     * @param array<string,mixed> $learningPhase
     * @return array<string,mixed>
     */
    public function analyze(
        Plan $plan,
        Task $task,
        Collection $recentAttempts,
        array $weaknessPriority,
        array $learningPhase,
    ): array {
        $historyLimit = max(
            4,
            (int) config('study.practice_routing.history_attempt_limit', 12),
        );
        $attempts = $recentAttempts->take($historyLimit)->values();
        $sessions = $this->sessionsForAttempts($attempts);
        $questions = $this->questionModelsForAttempts($attempts);

        [$topicObservations, $parentGrades] = $this->observations(
            $attempts,
            $sessions,
            $questions,
        );

        foreach ((array) ($weaknessPriority['ranked'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $topic = trim((string) ($item['topic'] ?? ''));
            $key = $this->taxonomy->key($topic);
            if ($key === '') {
                continue;
            }

            $topicObservations[$key] ??= [
                'topic' => $topic,
                'parent_topic' => $this->taxonomy->parentForLabel($topic),
                'events' => [],
            ];
        }

        $subtopics = collect($topicObservations)
            ->map(fn (array $item) => $this->subtopicState($item))
            ->sort(function (array $left, array $right) {
                return [
                    $this->stateRank((string) $left['status']),
                    -1 * (float) $left['recent_exposure'],
                    (string) $left['topic'],
                ] <=> [
                    $this->stateRank((string) $right['status']),
                    -1 * (float) $right['recent_exposure'],
                    (string) $right['topic'],
                ];
            })
            ->values();

        $parentExposure = $this->recentParentExposure($plan, $attempts);
        $parents = $this->parentStates($parentExposure, $parentGrades);

        $cooldownKeys = $subtopics
            ->filter(fn (array $item) => in_array(
                $item['status'],
                ['cooldown', 'mastered'],
                true,
            ))
            ->pluck('topic')
            ->map(fn ($topic) => $this->taxonomy->key((string) $topic))
            ->flip();

        $activeTopics = collect($learningPhase['active_topics'] ?? [])
            ->filter(fn ($topic) => is_string($topic) && trim($topic) !== '')
            ->map(fn ($topic) => trim((string) $topic))
            ->unique()
            ->values();

        $eligibleActive = $activeTopics
            ->reject(fn (string $topic) => isset(
                $cooldownKeys[$this->taxonomy->key($topic)],
            ))
            ->values();

        $confirmedTopics = collect($weaknessPriority['ranked'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->filter(fn (array $item) => ($item['state'] ?? null) === 'confirmed')
            ->pluck('topic')
            ->filter(fn ($topic) => is_string($topic) && trim($topic) !== '')
            ->map(fn ($topic) => trim((string) $topic))
            ->reject(fn (string $topic) => isset(
                $cooldownKeys[$this->taxonomy->key($topic)],
            ))
            ->values();

        $retentionTopics = $subtopics
            ->where('status', 'retention_due')
            ->pluck('topic')
            ->filter()
            ->values();

        $mode = $this->taskMode($task);
        $broadRecheckLimit = max(
            0,
            (int) config(
                'study.practice_routing.broad_assessment.weakness_recheck_questions',
                2,
            ),
        );
        $retentionLimit = max(
            0,
            (int) config(
                'study.practice_routing.broad_assessment.retention_questions',
                2,
            ),
        );

        $recheckTopics = $eligibleActive
            ->concat($confirmedTopics)
            ->unique()
            ->take(max(1, $broadRecheckLimit))
            ->values();

        return [
            'version' => 'v1',
            'task_mode' => $mode,
            'subtopic_states' => $subtopics->all(),
            'cooldown_topics' => $subtopics
                ->whereIn('status', ['cooldown', 'mastered'])
                ->pluck('topic')
                ->values()
                ->all(),
            'mastered_topics' => $subtopics
                ->where('status', 'mastered')
                ->pluck('topic')
                ->values()
                ->all(),
            'retention_due_topics' => $retentionTopics
                ->take(max(1, $retentionLimit))
                ->values()
                ->all(),
            'eligible_active_topics' => $eligibleActive->all(),
            'broad_recheck_topics' => $recheckTopics->all(),
            'parent_topics' => $parents->all(),
            'suppressed_parent_topics' => $parents
                ->where('suppressed', true)
                ->pluck('parent_topic')
                ->values()
                ->all(),
            'preferred_parent_topics' => $parents
                ->where('preferred', true)
                ->pluck('parent_topic')
                ->values()
                ->all(),
            'broad_parent_control' => $mode !== 'focused_remediation',
            'policy' => [
                'remediation_correct_streak' => $this->correctStreakThreshold(),
                'mastery_correct_count' => $this->masteryCorrectCount(),
                'cooldown_sets' => $this->cooldownSets(),
                'mastered_cooldown_sets' => $this->masteredCooldownSets(),
                'parent_question_window' => $this->parentQuestionWindow(),
                'broad_parent_cap' => $this->broadParentCap(),
                'high_exposure_parent_cap' => $this->highExposureParentCap(),
            ],
        ];
    }

    /**
     * @param Collection<int,mixed> $attempts
     * @param Collection<int,StudyPracticeSession> $sessions
     * @param Collection<int,Question> $questions
     * @return array{0:array<string,array<string,mixed>>,1:array<string,array<string,mixed>>}
     */
    private function observations(
        Collection $attempts,
        Collection $sessions,
        Collection $questions,
    ): array {
        $topics = [];
        $parents = [];
        $eventOrder = 0;

        foreach ($attempts as $attemptIndex => $attempt) {
            $questionPayloads = collect($attempt->questions ?? [])
                ->filter(fn ($item) => is_array($item))
                ->keyBy(fn (array $item) => (string) ($item['id'] ?? ''));

            $feedback = collect(data_get(
                $attempt->assessment,
                'question_feedback',
                [],
            ))->filter(fn ($item) => is_array($item))->values();

            $structured = $feedback->contains(
                fn (array $item) =>
                    array_key_exists('correctness', $item)
                    || array_key_exists('error_type', $item),
            );

            $strategy = $this->strategyForAttempt($attempt, $sessions);
            $focusTopics = collect($strategy['focus_topics'] ?? [])
                ->filter(fn ($item) => is_string($item) && trim($item) !== '')
                ->map(fn ($item) => trim((string) $item))
                ->unique()
                ->values();
            $fallbackFocus = $focusTopics->count() === 1
                ? (string) $focusTopics->first()
                : null;

            $observedThisAttempt = [];

            foreach ($feedback as $item) {
                $questionId = trim((string) ($item['question_id'] ?? ''));
                $payload = $questionPayloads->get($questionId);
                $payload = is_array($payload) ? $payload : [];
                $sourceQuestionId = is_numeric($payload['source_question_id'] ?? null)
                    ? (int) $payload['source_question_id']
                    : null;
                $question = $sourceQuestionId
                    ? $questions->get($sourceQuestionId)
                    : null;

                [$questionTopic, $questionParent] = $this->questionTopic(
                    $payload,
                    $question instanceof Question ? $question : null,
                    $fallbackFocus,
                );

                $correctness = trim((string) ($item['correctness'] ?? ''));
                $errorType = trim((string) ($item['error_type'] ?? 'unknown'));
                $feedbackTopics = $this->strings($item['weakness_topics'] ?? []);

                if ($correctness === 'correct') {
                    $positiveTopics = $questionTopic !== null
                        ? [$questionTopic]
                        : $feedbackTopics;

                    if ($positiveTopics === [] && $fallbackFocus !== null) {
                        $positiveTopics = [$fallbackFocus];
                    }

                    foreach ($positiveTopics as $topic) {
                        $this->addTopicEvent(
                            $topics,
                            $topic,
                            $this->taxonomy->parentForLabel($topic) ?? $questionParent,
                            [
                                'attempt_index' => (int) $attemptIndex,
                                'order' => $eventOrder++,
                                'outcome' => 'correct',
                                'error_type' => 'none',
                                'score_percent' => (int) $attempt->score_percent,
                            ],
                        );
                        $observedThisAttempt[$this->taxonomy->key($topic)] = true;
                    }
                } elseif (in_array($correctness, ['incorrect', 'partial'], true)) {
                    $failureTopics = $feedbackTopics;
                    if ($failureTopics === [] && $questionTopic !== null) {
                        $failureTopics = [$questionTopic];
                    }
                    if ($failureTopics === [] && $fallbackFocus !== null) {
                        $failureTopics = [$fallbackFocus];
                    }

                    foreach ($failureTopics as $topic) {
                        $this->addTopicEvent(
                            $topics,
                            $topic,
                            $this->taxonomy->parentForLabel($topic) ?? $questionParent,
                            [
                                'attempt_index' => (int) $attemptIndex,
                                'order' => $eventOrder++,
                                'outcome' => 'failure',
                                'error_type' => $this->normalizeErrorType($errorType),
                                'score_percent' => (int) $attempt->score_percent,
                            ],
                        );
                        $observedThisAttempt[$this->taxonomy->key($topic)] = true;
                    }
                }

                $gradeParents = collect(
                    $feedbackTopics !== []
                        ? $feedbackTopics
                        : array_filter([$questionTopic]),
                )
                    ->map(fn ($topic) => $this->taxonomy->parentForLabel((string) $topic))
                    ->filter()
                    ->when(
                        $questionParent !== null,
                        fn (Collection $items) => $items->push($questionParent),
                    )
                    ->unique()
                    ->values();

                if (in_array($correctness, ['correct', 'incorrect', 'partial'], true)) {
                    foreach ($gradeParents as $parent) {
                        $this->addParentGrade(
                            $parents,
                            (string) $parent,
                            $correctness === 'correct',
                        );
                    }
                }
            }

            foreach ($this->strings($attempt->strengths ?? []) as $strength) {
                $key = $this->taxonomy->key($strength);
                if ($key === '' || isset($observedThisAttempt[$key])) {
                    continue;
                }

                $this->addTopicEvent(
                    $topics,
                    $strength,
                    $this->taxonomy->parentForLabel($strength),
                    [
                        'attempt_index' => (int) $attemptIndex,
                        'order' => $eventOrder++,
                        'outcome' => 'correct',
                        'error_type' => 'none',
                        'score_percent' => (int) $attempt->score_percent,
                    ],
                );
                $observedThisAttempt[$key] = true;
            }

            // Compatibility only: modern structured question feedback is
            // authoritative. Summary prose cannot create an additional weakness.
            if (! $structured) {
                foreach ($this->strings($attempt->weaknesses ?? []) as $weakness) {
                    $key = $this->taxonomy->key($weakness);
                    if ($key === '' || isset($observedThisAttempt[$key])) {
                        continue;
                    }

                    $this->addTopicEvent(
                        $topics,
                        $weakness,
                        $this->taxonomy->parentForLabel($weakness),
                        [
                            'attempt_index' => (int) $attemptIndex,
                            'order' => $eventOrder++,
                            'outcome' => 'failure',
                            'error_type' => 'unknown',
                            'score_percent' => (int) $attempt->score_percent,
                        ],
                    );
                }
            }
        }

        return [$topics, $parents];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function subtopicState(array $item): array
    {
        $events = collect($item['events'] ?? [])
            ->filter(fn ($event) => is_array($event))
            ->sort(function (array $left, array $right) {
                return [
                    (int) ($left['attempt_index'] ?? PHP_INT_MAX),
                    (int) ($left['order'] ?? PHP_INT_MAX),
                ] <=> [
                    (int) ($right['attempt_index'] ?? PHP_INT_MAX),
                    (int) ($right['order'] ?? PHP_INT_MAX),
                ];
            })
            ->values();

        $correctCount = $events->where('outcome', 'correct')->count();
        $failureEvents = $events->where('outcome', 'failure')->values();
        $failureCount = $failureEvents->count();
        $blockingFailureCount = $failureEvents
            ->filter(fn (array $event) => in_array(
                (string) ($event['error_type'] ?? 'unknown'),
                self::BLOCKING_ERROR_TYPES,
                true,
            ))
            ->count();
        $minorFailureCount = $failureEvents
            ->filter(fn (array $event) => in_array(
                (string) ($event['error_type'] ?? ''),
                self::MINOR_ERROR_TYPES,
                true,
            ))
            ->count();

        $latestAttemptIndex = $events->isNotEmpty()
            ? (int) $events->min('attempt_index')
            : null;
        $latestEvents = $latestAttemptIndex === null
            ? collect()
            : $events->where('attempt_index', $latestAttemptIndex)->values();
        $latestHasFailure = $latestEvents->contains(
            fn (array $event) => ($event['outcome'] ?? '') === 'failure',
        );

        $consecutiveCorrect = 0;
        foreach ($events as $event) {
            if (($event['outcome'] ?? '') !== 'correct') {
                break;
            }
            $consecutiveCorrect++;
        }

        $perfectLatest = $latestAttemptIndex === 0
            && $latestEvents->contains(
                fn (array $event) =>
                    ($event['outcome'] ?? '') === 'correct'
                    && (int) ($event['score_percent'] ?? 0)
                        >= $this->perfectScorePercent(),
            );

        $cooldownTrigger = ! $latestHasFailure
            && (
                $consecutiveCorrect >= $this->correctStreakThreshold()
                || $perfectLatest
            );
        $mastered = $cooldownTrigger
            && $correctCount >= $this->masteryCorrectCount();

        $cooldownLength = $mastered
            ? $this->masteredCooldownSets()
            : $this->cooldownSets();
        $cooldownRemaining = $cooldownTrigger && $latestAttemptIndex !== null
            ? max(0, $cooldownLength - $latestAttemptIndex)
            : 0;

        $status = match (true) {
            $latestHasFailure && $blockingFailureCount >= 2 => 'confirmed',
            $latestHasFailure => 'suspected',
            $mastered && $cooldownRemaining > 0 => 'mastered',
            $cooldownRemaining > 0 => 'cooldown',
            $cooldownTrigger => 'retention_due',
            $correctCount > 0 => 'monitoring',
            default => 'monitoring',
        };

        $graded = $correctCount + $failureCount;
        $confidence = match (true) {
            $graded >= 2 => $correctCount / max(1, $graded),
            $graded === 1 && $correctCount === 1 => 0.65,
            $graded === 1 => 0.35,
            default => 0.50,
        };
        $recentExposure = min(
            1.0,
            $events
                ->filter(fn (array $event) => (int) ($event['attempt_index'] ?? 99) <= 2)
                ->count() / 3.0,
        );

        return [
            'topic' => (string) ($item['topic'] ?? ''),
            'parent_topic' => $item['parent_topic'] ?? null,
            'status' => $status,
            'mastery_confidence' => round($confidence, 3),
            'recent_exposure' => round($recentExposure, 3),
            'correct_count' => $correctCount,
            'failure_count' => $failureCount,
            'blocking_failure_count' => $blockingFailureCount,
            'minor_failure_count' => $minorFailureCount,
            'consecutive_correct' => $consecutiveCorrect,
            'latest_observation_attempt_index' => $latestAttemptIndex,
            'cooldown_remaining_sets' => $cooldownRemaining,
            'retention_due' => $status === 'retention_due',
        ];
    }

    /**
     * @param array<string,int> $parentExposure
     * @param array<string,array<string,mixed>> $parentGrades
     * @return Collection<int,array<string,mixed>>
     */
    private function parentStates(array $parentExposure, array $parentGrades): Collection
    {
        $parents = collect(array_keys(
            array_merge($parentExposure, $parentGrades),
        ))->unique()->values();

        $window = $this->parentQuestionWindow();
        $highExposure = (float) config(
            'study.practice_routing.parent_exposure.high_exposure_threshold',
            0.40,
        );
        $highConfidence = (float) config(
            'study.practice_routing.parent_exposure.high_confidence_threshold',
            0.75,
        );

        return $parents->map(function (string $parent) use (
            $parentExposure,
            $parentGrades,
            $window,
            $highExposure,
            $highConfidence,
        ) {
            $grades = $parentGrades[$parent] ?? [
                'graded' => 0,
                'correct' => 0,
            ];
            $graded = (int) ($grades['graded'] ?? 0);
            $correct = (int) ($grades['correct'] ?? 0);
            $confidence = match (true) {
                $graded >= 2 => $correct / max(1, $graded),
                $graded === 1 && $correct === 1 => 0.65,
                $graded === 1 => 0.35,
                default => 0.50,
            };
            $recentCount = (int) ($parentExposure[$parent] ?? 0);
            $exposure = min(1.0, $recentCount / max(1, $window));
            $suppressed = $exposure >= 0.50
                || ($exposure >= $highExposure && $confidence >= $highConfidence);
            $preferred = $exposure <= 0.20 && $confidence <= 0.60;

            return [
                'parent_topic' => $parent,
                'key' => $this->taxonomy->key($parent),
                'mastery_confidence' => round($confidence, 3),
                'recent_exposure' => round($exposure, 3),
                'recent_question_count' => $recentCount,
                'graded_count' => $graded,
                'correct_count' => $correct,
                'suppressed' => $suppressed,
                'preferred' => $preferred,
                'broad_question_cap' => $suppressed
                    ? $this->highExposureParentCap()
                    : $this->broadParentCap(),
            ];
        })->sort(function (array $left, array $right) {
            return [
                $left['suppressed'] ? 1 : 0,
                $left['preferred'] ? 0 : 1,
                (float) $left['recent_exposure'],
                -1 * (1.0 - (float) $left['mastery_confidence']),
                (string) $left['parent_topic'],
            ] <=> [
                $right['suppressed'] ? 1 : 0,
                $right['preferred'] ? 0 : 1,
                (float) $right['recent_exposure'],
                -1 * (1.0 - (float) $right['mastery_confidence']),
                (string) $right['parent_topic'],
            ];
        })->values();
    }

    /**
     * @param Collection<int,mixed> $attempts
     * @return array<string,int>
     */
    private function recentParentExposure(Plan $plan, Collection $attempts): array
    {
        $window = $this->parentQuestionWindow();
        $query = StudyPracticeSession::query()
            ->where('plan_id', $plan->id)
            ->whereNotNull('selected_questions');

        $identity = $attempts->first();
        if ($identity && ! empty($identity->user_id)) {
            $query->where('user_id', (int) $identity->user_id);
        } elseif ($identity && filled($identity->actor_token ?? null)) {
            $query
                ->whereNull('user_id')
                ->where('actor_token', (string) $identity->actor_token);
        } elseif (! empty($plan->user_id)) {
            $query->where('user_id', (int) $plan->user_id);
        }

        $sessions = $query
            ->latest('created_at')
            ->latest('id')
            ->take(max(8, $window))
            ->get();

        $questionIds = $sessions
            ->flatMap(fn (StudyPracticeSession $session) => collect(
                $session->selected_questions ?? [],
            ))
            ->pluck('question_id')
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $questions = $questionIds->isEmpty()
            ? collect()
            : Question::query()
                ->whereIn('id', $questionIds->all())
                ->get()
                ->keyBy('id');

        $counts = [];
        $considered = 0;

        foreach ($sessions as $session) {
            $strategy = is_array(data_get($session->selection_context, 'strategy'))
                ? data_get($session->selection_context, 'strategy')
                : [];
            $sessionFocus = collect($strategy['focus_topics'] ?? [])
                ->filter(fn ($item) => is_string($item) && trim($item) !== '')
                ->map(fn ($item) => trim((string) $item))
                ->unique()
                ->values();
            $focusParent = $sessionFocus->count() === 1
                ? $this->taxonomy->parentForLabel((string) $sessionFocus->first())
                : null;

            foreach ((array) ($session->selected_questions ?? []) as $selected) {
                if ($considered >= $window) {
                    break 2;
                }
                if (! is_array($selected)) {
                    continue;
                }

                $parent = filled($selected['selection_parent_topic'] ?? null)
                    ? trim((string) $selected['selection_parent_topic'])
                    : null;
                $parent ??= filled($selected['parent_topic'] ?? null)
                    ? trim((string) $selected['parent_topic'])
                    : null;
                $parent ??= $this->taxonomy->parentForLabel(
                    is_scalar($selected['selection_domain'] ?? null)
                        ? (string) $selected['selection_domain']
                        : null,
                );

                $questionId = is_numeric($selected['question_id'] ?? null)
                    ? (int) $selected['question_id']
                    : null;
                $question = $questionId ? $questions->get($questionId) : null;
                if ($parent === null && $question instanceof Question) {
                    $parent = $this->taxonomy->parentForQuestion($question);
                }
                $parent ??= $focusParent;

                $considered++;
                if ($parent === null || $parent === '') {
                    continue;
                }

                $counts[$parent] = (int) ($counts[$parent] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param Collection<int,mixed> $attempts
     * @return Collection<int,StudyPracticeSession>
     */
    private function sessionsForAttempts(Collection $attempts): Collection
    {
        $ids = $attempts
            ->pluck('study_practice_session_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return $ids->isEmpty()
            ? collect()
            : StudyPracticeSession::query()
                ->whereIn('id', $ids->all())
                ->get()
                ->keyBy('id');
    }

    /**
     * @param Collection<int,mixed> $attempts
     * @return Collection<int,Question>
     */
    private function questionModelsForAttempts(Collection $attempts): Collection
    {
        $ids = $attempts
            ->flatMap(fn ($attempt) => collect($attempt->questions ?? []))
            ->filter(fn ($item) => is_array($item))
            ->pluck('source_question_id')
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return $ids->isEmpty()
            ? collect()
            : Question::query()
                ->whereIn('id', $ids->all())
                ->get()
                ->keyBy('id');
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function questionTopic(
        array $payload,
        ?Question $question,
        ?string $fallbackFocus,
    ): array {
        $topic = filled($payload['topic'] ?? null)
            ? trim((string) $payload['topic'])
            : null;
        $parent = filled($payload['parent_topic'] ?? null)
            ? trim((string) $payload['parent_topic'])
            : null;

        if ($question) {
            $topic ??= $this->taxonomy->subtopicForQuestion($question);
            $parent ??= $this->taxonomy->parentForQuestion($question);
        }

        $topic ??= $fallbackFocus;
        $parent ??= $this->taxonomy->parentForLabel($topic);

        return [$topic, $parent];
    }

    /**
     * @param array<string,array<string,mixed>> $topics
     * @param array<string,mixed> $event
     */
    private function addTopicEvent(
        array &$topics,
        string $topic,
        ?string $parent,
        array $event,
    ): void {
        $topic = trim($topic);
        $key = $this->taxonomy->key($topic);
        if ($key === '') {
            return;
        }

        $topics[$key] ??= [
            'topic' => $topic,
            'parent_topic' => $parent,
            'events' => [],
        ];
        if (($topics[$key]['parent_topic'] ?? null) === null && $parent !== null) {
            $topics[$key]['parent_topic'] = $parent;
        }

        $topics[$key]['events'][] = $event;
    }

    /**
     * @param array<string,array<string,mixed>> $parents
     */
    private function addParentGrade(
        array &$parents,
        string $parent,
        bool $correct,
    ): void {
        $parents[$parent] ??= [
            'graded' => 0,
            'correct' => 0,
        ];
        $parents[$parent]['graded']++;
        if ($correct) {
            $parents[$parent]['correct']++;
        }
    }

    /**
     * @param Collection<int,StudyPracticeSession> $sessions
     * @return array<string,mixed>
     */
    private function strategyForAttempt($attempt, Collection $sessions): array
    {
        $sessionId = (int) ($attempt->study_practice_session_id ?? 0);
        $session = $sessionId > 0 ? $sessions->get($sessionId) : null;
        if (! $session instanceof StudyPracticeSession) {
            return [];
        }

        $strategy = data_get($session->selection_context, 'strategy', []);

        return is_array($strategy) ? $strategy : [];
    }

    private function taskMode(Task $task): string
    {
        $scope = mb_strtolower(trim(implode(' ', [
            (string) $task->title,
            (string) ($task->description ?? ''),
        ])));

        $broadTerms = config(
            'study.practice_routing.task_intent.broad_terms',
            [],
        );
        $focusedTerms = config(
            'study.practice_routing.task_intent.focused_terms',
            [],
        );

        $broad = collect(is_array($broadTerms) ? $broadTerms : [])
            ->contains(fn ($term) => is_string($term) && $term !== ''
                && str_contains($scope, mb_strtolower($term)));
        $focused = collect(is_array($focusedTerms) ? $focusedTerms : [])
            ->contains(fn ($term) => is_string($term) && $term !== ''
                && str_contains($scope, mb_strtolower($term)));

        // Explicit broad wording wins when a Task also mentions weaknesses,
        // e.g. "分野横断で弱点探索".
        if ($broad) {
            return 'broad_assessment';
        }
        if ($focused) {
            return 'focused_remediation';
        }

        return 'adaptive';
    }

    private function normalizeErrorType(string $type): string
    {
        $type = trim($type);

        return in_array(
            $type,
            array_merge(
                self::BLOCKING_ERROR_TYPES,
                self::MINOR_ERROR_TYPES,
                ['none'],
            ),
            true,
        ) ? $type : 'unknown';
    }

    /**
     * @return array<int,string>
     */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($item) => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn ($item) => trim((string) $item))
            ->unique()
            ->values()
            ->all();
    }

    private function stateRank(string $state): int
    {
        return match ($state) {
            'confirmed' => 0,
            'suspected' => 1,
            'retention_due' => 2,
            'monitoring' => 3,
            'cooldown' => 4,
            'mastered' => 5,
            default => 6,
        };
    }

    private function correctStreakThreshold(): int
    {
        return max(1, (int) config(
            'study.practice_routing.mastery.remediation_correct_streak',
            2,
        ));
    }

    private function masteryCorrectCount(): int
    {
        return max(1, (int) config(
            'study.practice_routing.mastery.mastery_correct_count',
            3,
        ));
    }

    private function cooldownSets(): int
    {
        return max(1, (int) config(
            'study.practice_routing.mastery.cooldown_sets',
            2,
        ));
    }

    private function masteredCooldownSets(): int
    {
        return max($this->cooldownSets(), (int) config(
            'study.practice_routing.mastery.mastered_cooldown_sets',
            4,
        ));
    }

    private function perfectScorePercent(): int
    {
        return max(1, min(100, (int) config(
            'study.practice_routing.mastery.perfect_score_percent',
            100,
        )));
    }

    private function parentQuestionWindow(): int
    {
        return max(1, (int) config(
            'study.practice_routing.parent_exposure.question_window',
            10,
        ));
    }

    private function broadParentCap(): int
    {
        return max(1, (int) config(
            'study.practice_routing.parent_exposure.broad_max_questions',
            2,
        ));
    }

    private function highExposureParentCap(): int
    {
        return max(0, (int) config(
            'study.practice_routing.parent_exposure.high_exposure_max_questions',
            1,
        ));
    }
}
