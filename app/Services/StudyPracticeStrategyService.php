<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;
use Illuminate\Support\Collection;

class StudyPracticeStrategyService
{
    public function __construct(
        private readonly StudyPracticeExamProfileService $examProfiles,
        private readonly StudyWeaknessPrioritizationService $weaknessPriorities,
        private readonly StudyExamConvergencePolicyService $convergence,
        private readonly StudyTaskProgressionService $progression,
        private readonly StudyPracticeRoutingPolicyService $routingPolicy,
    ) {}

    /**
     * @param Collection<int, mixed> $recentAttempts newest first
     * @return array<string, mixed>
     */
    public function build(
        Plan $plan,
        Task $task,
        Collection $recentAttempts,
    ): array {
        $historyLimit = max(
            4,
            (int) config(
                'study.exam_convergence.history_attempt_limit',
                16,
            ),
        );
        $recentAttempts = $recentAttempts
            ->take($historyLimit)
            ->values();

        $latest = $recentAttempts->first();
        $latestScore = $latest
            ? (int) $latest->score_percent
            : null;

        $guidedNextStep = is_array(data_get(
            $latest?->assessment,
            'next_step',
        ))
            ? data_get($latest?->assessment, 'next_step')
            : [];
        $guidedPractice = ($guidedNextStep['kind'] ?? null)
            === 'practice';
        $guidedFocusTopics = $guidedPractice
            ? collect($guidedNextStep['focus_topics'] ?? [])
                ->filter(
                    fn ($item) =>
                        is_string($item)
                        && trim($item) !== '',
                )
                ->map(fn ($item) => trim($item))
                ->unique()
                ->values()
                ->all()
            : [];

        $normalQuestionCount = max(
            1,
            min(
                20,
                (int) config(
                    'study.exam_convergence.practice.normal_question_count',
                    10,
                ),
            ),
        );

        // AI question_count remains a bounded hint. It cannot decide whether
        // Weakness Reinforcement is allowed to continue.
        $suggestedQuestionCount = $guidedPractice
            ? max(
                5,
                min(
                    $normalQuestionCount,
                    (int) ($guidedNextStep['question_count'] ?? 5),
                ),
            )
            : $normalQuestionCount;

        $weakness = $this->weaknessPriorities->analyze(
            $plan,
            $task,
            $recentAttempts->take(8)->values(),
            $guidedFocusTopics,
            $suggestedQuestionCount,
        );

        $learningPhase = $this->convergence->resolve(
            $plan,
            $task,
            $recentAttempts,
            $weakness,
        );

        $routingPolicy = $this->routingPolicy->analyze(
            $plan,
            $task,
            $recentAttempts,
            $weakness,
            $learningPhase,
        );

        $policyPhase = (string) (
            $learningPhase['phase']
            ?? StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE
        );
        $taskMode = (string) ($routingPolicy['task_mode'] ?? 'adaptive');
        $eligibleActiveTopics = collect(
            $routingPolicy['eligible_active_topics']
                ?? $learningPhase['active_topics']
                ?? [],
        )
            ->filter(fn ($topic) => is_string($topic) && trim($topic) !== '')
            ->map(fn ($topic) => trim((string) $topic))
            ->unique()
            ->values();

        $learningPhase['policy_phase'] = $policyPhase;
        $learningPhase['task_mode'] = $taskMode;

        if (
            $policyPhase
                === StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT
            && $taskMode === 'broad_assessment'
        ) {
            $learningPhase['phase'] =
                StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE;
            $learningPhase['label'] = '横断探索';
            $learningPhase['reason'] =
                'このTaskは分野横断型のため、確認済み弱点は1〜2問だけ再確認し、残りは未探索・低confidence分野を優先します。';
            $learningPhase['active_topics'] = [];
            $learningPhase['routing_override'] = 'broad_assessment';
        } elseif (
            $policyPhase
                === StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT
            && $eligibleActiveTopics->isEmpty()
        ) {
            $learningPhase['phase'] =
                StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE;
            $learningPhase['label'] = '総合演習';
            $learningPhase['reason'] =
                '重点弱点がmastery/cooldown条件を満たしたため、集中補強を止めて横断探索へ戻します。';
            $learningPhase['active_topics'] = [];
            $learningPhase['routing_override'] = 'mastery_cooldown';
        } elseif (
            $policyPhase
                === StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT
        ) {
            $learningPhase['active_topics'] = $eligibleActiveTopics->all();
        }

        $phase = (string) (
            $learningPhase['phase']
            ?? StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE
        );

        $allocationQuestionCount = $phase
                === StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT
            ? $suggestedQuestionCount
            : $normalQuestionCount;

        $controlledWeakness = match (true) {
            $taskMode === 'broad_assessment' =>
                $this->broadRoutingWeakness(
                    $weakness,
                    $routingPolicy,
                    $normalQuestionCount,
                    true,
                ),
            $phase
                === StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT =>
                $this->controlledWeakness(
                    $weakness,
                    (array) ($learningPhase['active_topics'] ?? []),
                    $allocationQuestionCount,
                ),
            default => $this->broadRoutingWeakness(
                $weakness,
                $routingPolicy,
                $normalQuestionCount,
                false,
            ),
        };

        $progression = $this->progression->resolve(
            $plan,
            $task,
            $recentAttempts->take(8)->values(),
            $learningPhase,
        );

        // Mastery verification is already a broad diagnostic check, not a
        // weakness drill. Keep it during General Practice for compatibility
        // and count it as broad evidence through learning_phase. Exam Mode
        // remains authoritative in the final exam window.
        $masteryVerification = (
            ($progression['kind'] ?? null) === 'verify_mastery'
            && $phase
                !== StudyExamConvergencePolicyService::PHASE_EXAM_MODE
        );

        $targetQuestionCount = $masteryVerification
            ? 5
            : (
                $phase
                    === StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT
                    ? $suggestedQuestionCount
                    : $normalQuestionCount
            );

        if ($masteryVerification) {
            $key = 'mastery_verification';
            $label = '完了前の仕上げ確認';
            $reason = (string) (
                $progression['reason']
                ?? '現在Taskを完了する前に、別の問題で理解が安定しているか確認します。'
            );
            $focusTopics = [];
            $questionMix = [
                'primary' => 0,
                'secondary' => 0,
                'diagnostic' => $targetQuestionCount,
            ];
        } elseif (
            $phase
            === StudyExamConvergencePolicyService::PHASE_DIAGNOSIS
        ) {
            $key = 'baseline_assessment';
            $label = '初回理解度確認';
            $reason = (string) $learningPhase['reason'];
            $focusTopics = [];
            $questionMix = $this->broadMix($targetQuestionCount);
        } elseif (
            $phase
            === StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT
        ) {
            $key = 'weakness_reinforcement';
            $label = '弱点補完';
            $reason = (string) $learningPhase['reason'];
            $focusTopics = collect(
                $learningPhase['active_topics'] ?? [],
            )
                ->filter()
                ->take(5)
                ->values()
                ->all();
            $questionMix = $controlledWeakness['question_mix'];
        } elseif (
            $phase === StudyExamConvergencePolicyService::PHASE_EXAM_MODE
        ) {
            $key = 'exam_mode';
            $label = 'Exam Mode';
            $reason = (string) $learningPhase['reason'];
            $focusTopics = [];
            $questionMix = $this->broadMix($targetQuestionCount);
        } else {
            // Keep the established strategy key for compatibility.
            // V56.4 differentiates broad Task intent inside routing_policy.
            $key = 'general_practice';
            $label = $taskMode === 'broad_assessment'
                ? '分野横断演習'
                : '総合演習';
            $reason = (string) $learningPhase['reason'];
            $focusTopics = [];
            $questionMix = $controlledWeakness['question_mix'];
        }

        $examProfile = $this->examProfiles->forPlanTask(
            $plan,
            $task,
        );

        return [
            'key' => $key,
            'version' => 'v4',
            'label' => $label,
            'reason' => $reason,
            'learning_phase' => $learningPhase,
            'weakness_control' => [
                'active_topics' => $learningPhase['active_topics'] ?? [],
                'graduated_topics' =>
                    $learningPhase['graduated_topics'] ?? [],
                'capped_topics' =>
                    $learningPhase['capped_topics'] ?? [],
                'reopened_topics' =>
                    $learningPhase['reopened_topics'] ?? [],
                'topic_states' =>
                    $learningPhase['topic_states'] ?? [],
                'cooldown_topics' =>
                    $routingPolicy['cooldown_topics'] ?? [],
                'mastered_topics' =>
                    $routingPolicy['mastered_topics'] ?? [],
                'retention_due_topics' =>
                    $routingPolicy['retention_due_topics'] ?? [],
            ],
            'routing_policy' => $routingPolicy,
            'focus_topics' => $focusTopics,
            'target_question_count' => $targetQuestionCount,
            'history_sample_count' => $recentAttempts->count(),
            'latest_score_percent' => $latestScore,
            'exam_profile' => $examProfile,
            'weakness_priority' => $controlledWeakness,
            'question_mix' => $questionMix,
            'progression' => [
                'kind' => $progression['kind'] ?? 'continue_current',
                'label' => $progression['label'] ?? null,
                'reason' => $progression['reason'] ?? null,
                'verification' => $progression['verification'] ?? null,
            ],
            'task_snapshot' => [
                'title' => (string) $task->title,
                'progress_percent' => (int) $task->progress_percent,
                'remaining_minutes' => (int) $task->remaining_minutes,
                'next_action' => trim(
                    (string) ($task->next_action_note ?? ''),
                ),
            ],
            'plan_snapshot' => [
                'title' => (string) $plan->title,
                'category' => (string) ($plan->category ?? ''),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $weakness
     * @param array<int,string> $activeTopics
     * @return array<string,mixed>
     */
    private function controlledWeakness(
        array $weakness,
        array $activeTopics,
        int $targetQuestionCount,
    ): array {
        $active = collect($activeTopics)
            ->filter(
                fn ($topic) =>
                    is_string($topic)
                    && trim($topic) !== '',
            )
            ->map(fn ($topic) => trim($topic))
            ->unique()
            ->values();

        $primary = $active->take(2)->values();
        $secondary = $active->skip(2)->take(3)->values();

        $primaryRatio = max(
            0.0,
            min(
                1.0,
                (float) config(
                    'study.practice_routing.focused_remediation.primary_ratio',
                    0.60,
                ),
            ),
        );
        $secondaryRatio = max(
            0.0,
            min(
                1.0,
                (float) config(
                    'study.practice_routing.focused_remediation.secondary_ratio',
                    0.20,
                ),
            ),
        );

        $primaryCount = $primary->isNotEmpty()
            ? max(1, (int) round($targetQuestionCount * $primaryRatio))
            : 0;
        $secondaryCount = $secondary->isNotEmpty()
            ? max(1, (int) round($targetQuestionCount * $secondaryRatio))
            : 0;

        if (
            $targetQuestionCount >= 2
            && $primaryCount + $secondaryCount
                > $targetQuestionCount - 1
        ) {
            $secondaryCount = max(
                0,
                $targetQuestionCount - $primaryCount - 1,
            );
        }

        $questionMix = [
            'primary' => min(
                $targetQuestionCount,
                $primaryCount,
            ),
            'secondary' => min(
                $targetQuestionCount,
                $secondaryCount,
            ),
            'diagnostic' => max(
                0,
                $targetQuestionCount
                    - $primaryCount
                    - $secondaryCount,
            ),
        ];

        $activeKeys = $active
            ->map(fn ($topic) => $this->topicKey($topic))
            ->flip();

        $ranked = collect($weakness['ranked'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) use (
                $activeKeys,
                $primary,
                $secondary,
            ) {
                $topic = (string) ($item['topic'] ?? '');
                $key = $this->topicKey($topic);

                $item['tier'] = match (true) {
                    $primary->contains($topic) => 'primary',
                    $secondary->contains($topic) => 'secondary',
                    isset($activeKeys[$key]) => 'monitor',
                    default => 'resolved',
                };

                return $item;
            })
            ->values()
            ->all();

        return [
            ...$weakness,
            'ranked' => $ranked,
            'primary_topics' => $primary->all(),
            'secondary_topics' => $secondary->all(),
            'monitor_topics' => [],
            'question_mix' => $questionMix,
            'has_confirmed_weakness' => $active->isNotEmpty(),
            'has_any_weakness_signal' => $active->isNotEmpty(),
        ];
    }

    /**
     * Broad routing keeps a small recheck/retention budget and reserves the
     * majority of the block for cross-domain exploration.
     *
     * @param array<string,mixed> $weakness
     * @param array<string,mixed> $routingPolicy
     * @return array<string,mixed>
     */
    private function broadRoutingWeakness(
        array $weakness,
        array $routingPolicy,
        int $targetQuestionCount,
        bool $includeWeaknessRecheck,
    ): array {
        $minimumExploration = max(
            0,
            min(
                $targetQuestionCount,
                (int) config(
                    'study.practice_routing.broad_assessment.minimum_exploration_questions',
                    6,
                ),
            ),
        );
        $recheckLimit = max(
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

        $primary = $includeWeaknessRecheck
            ? collect($routingPolicy['broad_recheck_topics'] ?? [])
                ->filter(fn ($topic) => is_string($topic) && trim($topic) !== '')
                ->map(fn ($topic) => trim((string) $topic))
                ->unique()
                ->take($recheckLimit)
                ->values()
            : collect();

        $secondary = collect($routingPolicy['retention_due_topics'] ?? [])
            ->filter(fn ($topic) => is_string($topic) && trim($topic) !== '')
            ->map(fn ($topic) => trim((string) $topic))
            ->reject(fn (string $topic) => $primary->contains($topic))
            ->unique()
            ->take($retentionLimit)
            ->values();

        $availableNonExploration = max(
            0,
            $targetQuestionCount - $minimumExploration,
        );
        $primaryCount = $primary->isNotEmpty()
            ? min($recheckLimit, $availableNonExploration)
            : 0;
        $remainingNonExploration = max(
            0,
            $availableNonExploration - $primaryCount,
        );
        $secondaryCount = $secondary->isNotEmpty()
            ? min($retentionLimit, $remainingNonExploration)
            : 0;

        $questionMix = [
            'primary' => $primaryCount,
            'secondary' => $secondaryCount,
            'diagnostic' => max(
                $minimumExploration,
                $targetQuestionCount - $primaryCount - $secondaryCount,
            ),
        ];

        // Keep exact target size if the configured exploration minimum is
        // larger than the remaining seats after clamping.
        $questionMix['diagnostic'] = max(
            0,
            $targetQuestionCount
                - $questionMix['primary']
                - $questionMix['secondary'],
        );

        $primaryKeys = $primary
            ->map(fn ($topic) => $this->topicKey((string) $topic))
            ->flip();
        $secondaryKeys = $secondary
            ->map(fn ($topic) => $this->topicKey((string) $topic))
            ->flip();
        $cooldownKeys = collect($routingPolicy['cooldown_topics'] ?? [])
            ->filter()
            ->map(fn ($topic) => $this->topicKey((string) $topic))
            ->flip();

        $ranked = collect($weakness['ranked'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) use (
                $primaryKeys,
                $secondaryKeys,
                $cooldownKeys,
            ) {
                $key = $this->topicKey((string) ($item['topic'] ?? ''));

                $item['tier'] = match (true) {
                    isset($primaryKeys[$key]) => 'primary',
                    isset($secondaryKeys[$key]) => 'secondary',
                    isset($cooldownKeys[$key]) => 'resolved',
                    default => 'monitor',
                };

                return $item;
            })
            ->values()
            ->all();

        return [
            ...$weakness,
            'ranked' => $ranked,
            'primary_topics' => $primary->all(),
            'secondary_topics' => $secondary->all(),
            'monitor_topics' => collect($weakness['monitor_topics'] ?? [])
                ->filter()
                ->reject(fn ($topic) =>
                    $primary->contains($topic)
                    || $secondary->contains($topic)
                )
                ->values()
                ->all(),
            'question_mix' => $questionMix,
            'has_confirmed_weakness' => $primary->isNotEmpty(),
            'has_any_weakness_signal' =>
                $primary->isNotEmpty() || $secondary->isNotEmpty(),
        ];
    }

    /**
     * @return array{primary:int,secondary:int,diagnostic:int}
     */
    private function broadMix(int $targetQuestionCount): array
    {
        return [
            'primary' => 0,
            'secondary' => 0,
            'diagnostic' => $targetQuestionCount,
        ];
    }

    private function topicKey(string $topic): string
    {
        return mb_strtolower(trim(
            preg_replace('/[\s　]+/u', ' ', $topic) ?? $topic,
        ));
    }
}
