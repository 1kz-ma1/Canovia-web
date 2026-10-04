<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use Illuminate\Support\Collection;

final class StudyExamConvergencePolicyService
{
    public const PHASE_DIAGNOSIS = 'diagnosis';
    public const PHASE_WEAKNESS_REINFORCEMENT = 'weakness_reinforcement';
    public const PHASE_GENERAL_PRACTICE = 'general_practice';
    public const PHASE_EXAM_MODE = 'exam_mode';

    private const BLOCKING_ERROR_TYPES = [
        'knowledge_gap',
        'concept_gap',
        'reasoning_gap',
        'condition_reading',
        'unit_error',
        'unknown',
    ];

    public function __construct(
        private readonly StudyExamDateService $examDates,
    ) {}

    /**
     * @param Collection<int,mixed> $recentAttempts newest first
     * @param array<string,mixed> $weaknessPriority
     * @return array<string,mixed>
     */
    public function resolve(
        Plan $plan,
        Task $task,
        Collection $recentAttempts,
        array $weaknessPriority,
    ): array {
        $limit = max(
            4,
            (int) config('study.exam_convergence.history_attempt_limit', 16),
        );
        $attempts = $recentAttempts->take($limit)->values();
        $sessions = $this->sessionsFor($attempts);

        $topics = $this->topicLabels(
            $attempts,
            $sessions,
            $weaknessPriority,
        );
        $priorityScores = collect($weaknessPriority['ranked'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->mapWithKeys(fn (array $item) => [
                $this->key((string) ($item['topic'] ?? ''))
                    => (float) ($item['priority_score'] ?? 0),
            ]);

        $states = collect($topics)
            ->map(function (string $label, string $key) use (
                $attempts,
                $sessions,
                $priorityScores,
            ) {
                $state = $this->topicState(
                    $key,
                    $label,
                    $attempts,
                    $sessions,
                );
                $state['priority_score'] = (float) (
                    $priorityScores->get($key, 0.0)
                );

                return $state;
            })
            ->sortByDesc('priority_score')
            ->values();

        $active = $states
            ->filter(fn (array $item) => in_array(
                $item['status'],
                ['active', 'reopened'],
                true,
            ))
            ->values();

        $graduated = $states
            ->where('status', 'graduated')
            ->pluck('topic')
            ->values()
            ->all();
        $capped = $states
            ->where('status', 'capped')
            ->pluck('topic')
            ->values()
            ->all();
        $reopened = $states
            ->where('status', 'reopened')
            ->pluck('topic')
            ->values()
            ->all();

        $generalReturnRequired = $states->contains(
            fn (array $item) =>
                in_array($item['status'], ['graduated', 'capped'], true)
                && ! (bool) $item['broad_practice_after_transition'],
        );

        // Preserve V41.4 semantics for a single suspected weakness: it may
        // receive a small Secondary re-check, but it is not yet a confirmed
        // intervention. Graduation/re-entry state remains history-owned here.
        $candidateTopics = collect(
            $weaknessPriority['primary_topics'] ?? [],
        )
            ->merge($weaknessPriority['secondary_topics'] ?? [])
            ->filter(
                fn ($topic) =>
                    is_string($topic)
                    && trim($topic) !== '',
            )
            ->unique()
            ->take(5)
            ->values();

        $deadline = $this->examDates->resolve($plan);
        $daysUntilExam = $this->examDates->daysUntil($plan);
        $generalDays = max(
            1,
            (int) config(
                'study.exam_convergence.deadline.general_practice_days',
                30,
            ),
        );
        $examDays = max(
            0,
            (int) config(
                'study.exam_convergence.deadline.exam_mode_days',
                14,
            ),
        );

        [$phase, $reason] = match (true) {
            $daysUntilExam !== null && $daysUntilExam <= $examDays => [
                self::PHASE_EXAM_MODE,
                "試験まで{$daysUntilExam}日のため、新しい細部探索より本番バランスを優先します。",
            ],
            $generalReturnRequired => [
                self::PHASE_GENERAL_PRACTICE,
                '弱点補完の卒業または深掘り上限に到達したため、次は総合演習で全体を再測定します。',
            ],
            $daysUntilExam !== null && $daysUntilExam <= $generalDays => [
                self::PHASE_GENERAL_PRACTICE,
                "試験まで{$daysUntilExam}日のため、局所補強より総合演習を優先します。",
            ],
            $attempts->isEmpty() => [
                self::PHASE_DIAGNOSIS,
                'まだ演習履歴がないため、最初は幅広く現在地を確認します。',
            ],
            $active->isNotEmpty() => [
                self::PHASE_WEAKNESS_REINFORCEMENT,
                '繰り返しEvidenceがある未卒業の弱点だけを短く補完します。',
            ],
            $candidateTopics->isNotEmpty() => [
                self::PHASE_WEAKNESS_REINFORCEMENT,
                '単発Signalを弱点へ固定せず、横断診断を残した短い再確認で本当に補完が必要か確かめます。',
            ],
            default => [
                self::PHASE_GENERAL_PRACTICE,
                '集中補完を続ける根拠がないため、総合演習へ戻して全体成績を確認します。',
            ],
        };

        $phaseLabel = match ($phase) {
            self::PHASE_DIAGNOSIS => '診断',
            self::PHASE_WEAKNESS_REINFORCEMENT => '弱点補完',
            self::PHASE_EXAM_MODE => 'Exam Mode',
            default => '総合演習',
        };

        return [
            'version' => 'v1',
            'phase' => $phase,
            'label' => $phaseLabel,
            'reason' => $reason,
            'exam_date' => $deadline['exam_date'],
            'exam_date_source' => $deadline['source'],
            'exam_date_conflict' => $deadline['conflict'],
            'days_until_exam' => $daysUntilExam,
            'active_topics' => $phase === self::PHASE_WEAKNESS_REINFORCEMENT
                ? $active->pluck('topic')
                    ->merge($candidateTopics)
                    ->unique()
                    ->take(5)
                    ->values()
                    ->all()
                : [],
            'graduated_topics' => $graduated,
            'capped_topics' => $capped,
            'reopened_topics' => $reopened,
            'general_return_required' => $generalReturnRequired,
            'topic_states' => $states->all(),
            'policy' => [
                'graduation_minimum_targeted_sessions' =>
                    $this->graduationMinimumSessions(),
                'graduation_minimum_targeted_question_budget' =>
                    $this->graduationMinimumBudget(),
                'graduation_minimum_score_percent' =>
                    $this->graduationMinimumScore(),
                'reinforcement_maximum_sessions' =>
                    $this->reinforcementMaximumSessions(),
                'reinforcement_maximum_question_budget' =>
                    $this->reinforcementMaximumBudget(),
                'reentry_window_attempts' => $this->reentryWindow(),
                'reentry_required_failure_attempts' =>
                    $this->reentryRequiredFailures(),
                'general_practice_days' => $generalDays,
                'exam_mode_days' => $examDays,
            ],
        ];
    }

    /**
     * @param Collection<int,mixed> $attempts newest first
     * @return Collection<int,StudyPracticeSession>
     */
    private function sessionsFor(Collection $attempts): Collection
    {
        $ids = $attempts
            ->pluck('study_practice_session_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return StudyPracticeSession::query()
            ->whereIn('id', $ids->all())
            ->get()
            ->keyBy('id');
    }

    /**
     * @param Collection<int,mixed> $attempts
     * @param Collection<int,StudyPracticeSession> $sessions
     * @param array<string,mixed> $weaknessPriority
     * @return array<string,string>
     */
    private function topicLabels(
        Collection $attempts,
        Collection $sessions,
        array $weaknessPriority,
    ): array {
        $labels = [];

        $put = function (mixed $value) use (&$labels): void {
            if (! is_scalar($value)) {
                return;
            }

            $label = trim((string) $value);
            $key = $this->key($label);

            if ($key !== '' && ! isset($labels[$key])) {
                $labels[$key] = $label;
            }
        };

        foreach ((array) ($weaknessPriority['ranked'] ?? []) as $item) {
            if (is_array($item)) {
                $put($item['topic'] ?? null);
            }
        }

        foreach ($attempts as $attempt) {
            foreach ((array) ($attempt->weaknesses ?? []) as $topic) {
                $put($topic);
            }

            foreach ((array) data_get(
                $attempt->assessment,
                'question_feedback',
                [],
            ) as $feedback) {
                if (! is_array($feedback)) {
                    continue;
                }

                foreach ((array) ($feedback['weakness_topics'] ?? []) as $topic) {
                    $put($topic);
                }
            }

            $strategy = $this->strategyForAttempt($attempt, $sessions);
            foreach ([
                data_get($strategy, 'weakness_priority.primary_topics', []),
                data_get($strategy, 'weakness_priority.secondary_topics', []),
                $strategy['focus_topics'] ?? [],
                data_get($strategy, 'weakness_control.active_topics', []),
            ] as $topics) {
                foreach ((array) $topics as $topic) {
                    $put($topic);
                }
            }
        }

        return $labels;
    }

    /**
     * @param Collection<int,mixed> $attempts newest first
     * @param Collection<int,StudyPracticeSession> $sessions
     * @return array<string,mixed>
     */
    private function topicState(
        string $topicKey,
        string $label,
        Collection $attempts,
        Collection $sessions,
    ): array {
        $status = 'monitoring';
        $detectionAttemptIds = [];
        $cycleSessions = 0;
        $cycleBudget = 0;
        $targeted = [];
        $transitionAttemptId = null;
        $broadAfterTransition = false;
        $broadFailures = [];
        $reopenedCount = 0;

        foreach ($attempts->reverse()->values() as $attempt) {
            $attemptId = (int) $attempt->id;
            $strategy = $this->strategyForAttempt($attempt, $sessions);
            $phase = $this->phaseForStrategy($strategy);
            $weakSignal = $this->hasWeakSignal($attempt, $topicKey);

            if ($status === 'monitoring' && $weakSignal) {
                $detectionAttemptIds[] = $attemptId;
                $detectionAttemptIds = array_values(array_unique(
                    $detectionAttemptIds,
                ));

                if (count($detectionAttemptIds) >= 2) {
                    $status = 'active';
                    $cycleSessions = 0;
                    $cycleBudget = 0;
                    $targeted = [];
                    $transitionAttemptId = $attemptId;
                }
            }

            if (in_array($status, ['active', 'reopened'], true)) {
                if ($phase === self::PHASE_WEAKNESS_REINFORCEMENT) {
                    $budget = $this->topicBudget(
                        $attempt,
                        $strategy,
                        $topicKey,
                    );

                    if ($budget > 0) {
                        $cycleSessions++;
                        $cycleBudget += $budget;
                        $targeted[] = [
                            'attempt_id' => $attemptId,
                            'score_percent' => (int) $attempt->score_percent,
                            'blocking_error' => $this->hasBlockingError(
                                $attempt,
                                $topicKey,
                            ),
                        ];

                        if ($this->graduated(
                            $cycleSessions,
                            $cycleBudget,
                            $targeted,
                        )) {
                            $status = 'graduated';
                            $transitionAttemptId = $attemptId;
                            $broadAfterTransition = false;
                            $broadFailures = [];
                            continue;
                        }

                        if (
                            $cycleSessions >= $this->reinforcementMaximumSessions()
                            || $cycleBudget >= $this->reinforcementMaximumBudget()
                        ) {
                            $status = 'capped';
                            $transitionAttemptId = $attemptId;
                            $broadAfterTransition = false;
                            $broadFailures = [];
                            continue;
                        }
                    }
                }

                continue;
            }

            if (
                in_array($status, ['graduated', 'capped'], true)
                && $attemptId !== $transitionAttemptId
                && in_array(
                    $phase,
                    [
                        self::PHASE_GENERAL_PRACTICE,
                        self::PHASE_EXAM_MODE,
                    ],
                    true,
                )
            ) {
                $broadAfterTransition = true;
                $broadFailures[] = $this->hasBlockingError(
                    $attempt,
                    $topicKey,
                );
                $broadFailures = array_slice(
                    $broadFailures,
                    -1 * $this->reentryWindow(),
                );

                if (
                    count(array_filter($broadFailures))
                    >= $this->reentryRequiredFailures()
                ) {
                    $status = 'reopened';
                    $reopenedCount++;
                    $cycleSessions = 0;
                    $cycleBudget = 0;
                    $targeted = [];
                    $transitionAttemptId = $attemptId;
                    $broadAfterTransition = false;
                    $broadFailures = [];
                }
            }
        }

        $latestTargeted = array_slice($targeted, -2);
        $lastScores = array_values(array_map(
            fn (array $item) => (int) $item['score_percent'],
            $latestTargeted,
        ));

        return [
            'topic' => $label,
            'status' => $status,
            'targeted_sessions' => $cycleSessions,
            'targeted_question_budget' => $cycleBudget,
            'latest_targeted_scores' => $lastScores,
            'blocking_error_in_latest_targeted' => collect(
                $latestTargeted,
            )->contains(
                fn (array $item) => (bool) $item['blocking_error'],
            ),
            'remaining_sessions_to_graduation' => max(
                0,
                $this->graduationMinimumSessions() - $cycleSessions,
            ),
            'remaining_question_budget_to_graduation' => max(
                0,
                $this->graduationMinimumBudget() - $cycleBudget,
            ),
            'broad_practice_after_transition' => $broadAfterTransition,
            'reopened_count' => $reopenedCount,
            'transition_attempt_id' => $transitionAttemptId,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $targeted
     */
    private function graduated(
        int $sessions,
        int $budget,
        array $targeted,
    ): bool {
        if (
            $sessions < $this->graduationMinimumSessions()
            || $budget < $this->graduationMinimumBudget()
        ) {
            return false;
        }

        $latest = array_slice($targeted, -2);
        if (count($latest) < 2) {
            return false;
        }

        foreach ($latest as $item) {
            if (
                (int) $item['score_percent']
                    < $this->graduationMinimumScore()
                || (bool) $item['blocking_error']
            ) {
                return false;
            }
        }

        return true;
    }

    private function hasWeakSignal($attempt, string $topicKey): bool
    {
        if ($this->listContainsTopic(
            $attempt->weaknesses ?? [],
            $topicKey,
        )) {
            return true;
        }

        foreach ((array) data_get(
            $attempt->assessment,
            'question_feedback',
            [],
        ) as $feedback) {
            if (! is_array($feedback)) {
                continue;
            }

            if (
                in_array(
                    (string) ($feedback['correctness'] ?? ''),
                    ['incorrect', 'partial'],
                    true,
                )
                && $this->listContainsTopic(
                    $feedback['weakness_topics'] ?? [],
                    $topicKey,
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasBlockingError($attempt, string $topicKey): bool
    {
        $feedbackSeen = false;

        foreach ((array) data_get(
            $attempt->assessment,
            'question_feedback',
            [],
        ) as $feedback) {
            if (! is_array($feedback)) {
                continue;
            }

            if (! $this->listContainsTopic(
                $feedback['weakness_topics'] ?? [],
                $topicKey,
            )) {
                continue;
            }

            $feedbackSeen = true;

            if (
                in_array(
                    (string) ($feedback['correctness'] ?? ''),
                    ['incorrect', 'partial'],
                    true,
                )
                && in_array(
                    (string) ($feedback['error_type'] ?? 'unknown'),
                    self::BLOCKING_ERROR_TYPES,
                    true,
                )
            ) {
                return true;
            }
        }

        return ! $feedbackSeen
            && $this->listContainsTopic(
                $attempt->weaknesses ?? [],
                $topicKey,
            );
    }

    /**
     * @param Collection<int,StudyPracticeSession> $sessions
     * @return array<string,mixed>
     */
    private function strategyForAttempt(
        $attempt,
        Collection $sessions,
    ): array {
        $sessionId = (int) ($attempt->study_practice_session_id ?? 0);
        if ($sessionId <= 0) {
            return [];
        }

        $session = $sessions->get($sessionId);
        if (! $session instanceof StudyPracticeSession) {
            return [];
        }

        $strategy = data_get(
            $session->selection_context,
            'strategy',
            [],
        );

        return is_array($strategy) ? $strategy : [];
    }

    /**
     * @param array<string,mixed> $strategy
     */
    private function phaseForStrategy(array $strategy): string
    {
        $phase = (string) data_get(
            $strategy,
            'learning_phase.phase',
            '',
        );

        if (in_array($phase, [
            self::PHASE_DIAGNOSIS,
            self::PHASE_WEAKNESS_REINFORCEMENT,
            self::PHASE_GENERAL_PRACTICE,
            self::PHASE_EXAM_MODE,
        ], true)) {
            return $phase;
        }

        return match ((string) ($strategy['key'] ?? '')) {
            'baseline_assessment' => self::PHASE_DIAGNOSIS,
            'weakness_reinforcement' =>
                self::PHASE_WEAKNESS_REINFORCEMENT,
            'exam_mode' => self::PHASE_EXAM_MODE,
            default => self::PHASE_GENERAL_PRACTICE,
        };
    }

    /**
     * @param array<string,mixed> $strategy
     */
    private function topicBudget(
        $attempt,
        array $strategy,
        string $topicKey,
    ): int {
        $primary = collect(data_get(
            $strategy,
            'weakness_priority.primary_topics',
            [],
        ))
            ->filter(fn ($topic) => is_scalar($topic))
            ->map(fn ($topic) => $this->key((string) $topic))
            ->filter()
            ->values();

        $secondary = collect(data_get(
            $strategy,
            'weakness_priority.secondary_topics',
            [],
        ))
            ->filter(fn ($topic) => is_scalar($topic))
            ->map(fn ($topic) => $this->key((string) $topic))
            ->filter()
            ->values();

        $focus = collect($strategy['focus_topics'] ?? [])
            ->filter(fn ($topic) => is_scalar($topic))
            ->map(fn ($topic) => $this->key((string) $topic))
            ->filter()
            ->values();

        $mix = is_array($strategy['question_mix'] ?? null)
            ? $strategy['question_mix']
            : [];

        $budget = 0;

        if ($primary->contains($topicKey)) {
            $budget = (int) ceil(
                max(0, (int) ($mix['primary'] ?? 0))
                / max(1, $primary->count()),
            );
        } elseif ($secondary->contains($topicKey)) {
            $budget = (int) ceil(
                max(0, (int) ($mix['secondary'] ?? 0))
                / max(1, $secondary->count()),
            );
        } elseif ($focus->contains($topicKey)) {
            $budget = (int) ceil(
                max(
                    1,
                    (int) ($strategy['target_question_count'] ?? 0),
                ) / max(1, $focus->count()),
            );
        }

        $actualQuestionCount = is_array($attempt->questions ?? null)
            ? count($attempt->questions)
            : 0;

        if ($actualQuestionCount > 0) {
            $budget = min($budget, $actualQuestionCount);
        }

        return max(0, $budget);
    }

    private function listContainsTopic(
        mixed $topics,
        string $topicKey,
    ): bool {
        return collect(is_array($topics) ? $topics : [])
            ->filter(fn ($topic) => is_scalar($topic))
            ->contains(
                fn ($topic) =>
                    $this->key((string) $topic) === $topicKey,
            );
    }

    private function key(string $topic): string
    {
        $topic = mb_strtolower(trim(
            preg_replace('/[\s　]+/u', ' ', $topic) ?? $topic,
        ));

        return $topic;
    }

    private function graduationMinimumSessions(): int
    {
        return max(1, (int) config(
            'study.exam_convergence.graduation.minimum_targeted_sessions',
            2,
        ));
    }

    private function graduationMinimumBudget(): int
    {
        return max(1, (int) config(
            'study.exam_convergence.graduation.minimum_targeted_question_budget',
            8,
        ));
    }

    private function graduationMinimumScore(): int
    {
        return max(0, min(100, (int) config(
            'study.exam_convergence.graduation.minimum_score_percent',
            80,
        )));
    }

    private function reinforcementMaximumSessions(): int
    {
        return max(1, (int) config(
            'study.exam_convergence.reinforcement.maximum_sessions_per_cycle',
            3,
        ));
    }

    private function reinforcementMaximumBudget(): int
    {
        return max(1, (int) config(
            'study.exam_convergence.reinforcement.maximum_question_budget_per_cycle',
            20,
        ));
    }

    private function reentryWindow(): int
    {
        return max(1, (int) config(
            'study.exam_convergence.reentry.general_exam_window_attempts',
            3,
        ));
    }

    private function reentryRequiredFailures(): int
    {
        return max(1, (int) config(
            'study.exam_convergence.reentry.required_failure_attempts',
            2,
        ));
    }
}
