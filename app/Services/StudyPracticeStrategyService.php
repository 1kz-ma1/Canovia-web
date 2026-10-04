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

        $controlledWeakness = $this->controlledWeakness(
            $weakness,
            (array) ($learningPhase['active_topics'] ?? []),
            $normalQuestionCount,
        );

        $progression = $this->progression->resolve(
            $plan,
            $task,
            $recentAttempts->take(8)->values(),
            $learningPhase,
        );

        $phase = (string) (
            $learningPhase['phase']
            ?? StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE
        );

        // Task completion verification is a short, broad safety check,
        // not weakness drilling. Keep it even when the convergence policy
        // would otherwise prefer General Practice or Exam Mode.
        $masteryVerification = (
            ($progression['kind'] ?? null) === 'verify_mastery'
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
            $key = 'general_practice';
            $label = '総合演習';
            $reason = (string) $learningPhase['reason'];
            $focusTopics = [];
            $questionMix = $this->broadMix($targetQuestionCount);
        }

        $examProfile = $this->examProfiles->forPlanTask(
            $plan,
            $task,
        );

        return [
            'key' => $key,
            'version' => 'v3',
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
            ],
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

        $primaryCount = $primary->isNotEmpty()
            ? max(1, (int) round($targetQuestionCount * 0.50))
            : 0;
        $secondaryCount = $secondary->isNotEmpty()
            ? max(1, (int) round($targetQuestionCount * 0.30))
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
