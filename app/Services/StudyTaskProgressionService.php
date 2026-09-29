<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;
use Illuminate\Support\Collection;

class StudyTaskProgressionService
{
    /**
     * Decide whether Study Practice should stay on the current Task, request a
     * mastery verification, or advance to the next eligible Task.
     *
     * AI assessment is a signal only. Canovia owns Task completion and routing.
     *
     * @param Collection<int,mixed> $recentAttempts newest first
     * @return array<string,mixed>
     */
    public function resolve(Plan $plan, Task $task, Collection $recentAttempts): array
    {
        $attempts = $recentAttempts->take(8)->values();
        $latest = $attempts->first();

        if ((int) $task->progress_percent >= 100 || $task->status === 'done') {
            return $this->afterCompletion($plan, $task, [
                'passed' => true,
                'strong_attempt_count' => 0,
                'required_strong_attempts' => 0,
                'reason' => 'Taskはすでに完了しています。',
            ]);
        }

        if (! $latest) {
            return [
                'kind' => 'continue_current',
                'label' => 'このTaskの理解度を確認する',
                'reason' => 'まだ演習結果がないため、現在Taskの理解度確認から始めます。',
                'verification' => null,
                'next_task' => null,
            ];
        }

        $nextKind = (string) data_get($latest->assessment, 'next_step.kind', '');
        $recommended = max(0, min(100, (int) $latest->recommended_task_progress_percent));
        $completionSignal = $nextKind === 'complete_task' || $recommended >= 100;

        if (! $completionSignal) {
            if ($nextKind === 'plan_update') {
                return [
                    'kind' => 'review_plan',
                    'label' => '学習結果をもとに計画を見直す',
                    'reason' => (string) data_get($latest->assessment, 'next_step.reason', '計画側の見直しが提案されているため。'),
                    'verification' => null,
                    'next_task' => null,
                ];
            }

            if ($nextKind === 'practice') {
                return [
                    'kind' => 'reinforce_current',
                    'label' => (string) data_get($latest->assessment, 'next_step.label', 'このTaskの弱点補強を続ける'),
                    'reason' => (string) data_get($latest->assessment, 'next_step.reason', '現在Taskに補強対象が残っているため。'),
                    'verification' => null,
                    'next_task' => null,
                ];
            }

            return [
                'kind' => 'continue_current',
                'label' => (string) data_get($latest->assessment, 'next_step.label', 'このTaskの学習を続ける'),
                'reason' => (string) data_get($latest->assessment, 'next_step.reason', 'Task完了を確定する十分なSignalがまだないため。'),
                'verification' => null,
                'next_task' => null,
            ];
        }

        $verification = $this->verification($attempts);
        if (! $verification['passed']) {
            return [
                'kind' => 'verify_mastery',
                'label' => '完了前に仕上げ確認をする',
                'reason' => $verification['reason'],
                'verification' => $verification,
                'next_task' => null,
            ];
        }

        return $this->afterCompletion($plan, $task, $verification);
    }

    /**
     * Two consecutive strong assessments are required before an AI completion
     * signal can finish a Task. One good result therefore becomes a verification
     * session instead of immediately advancing the learner.
     *
     * @param Collection<int,mixed> $attempts newest first
     * @return array<string,mixed>
     */
    private function verification(Collection $attempts): array
    {
        $required = 2;
        $latestTwo = $attempts->take($required)->values();
        $strong = $latestTwo->filter(fn ($attempt) => $this->isStrongAttempt($attempt))->count();

        if ($latestTwo->count() < $required) {
            return [
                'passed' => false,
                'strong_attempt_count' => $strong,
                'required_strong_attempts' => $required,
                'reason' => '1回の高得点だけではTask完了にせず、もう一度別の問題で理解が安定しているか確認します。',
            ];
        }

        if ($strong < $required) {
            return [
                'passed' => false,
                'strong_attempt_count' => $strong,
                'required_strong_attempts' => $required,
                'reason' => '直近2回の結果がまだ安定していないため、Task全体から仕上げ確認を行います。',
            ];
        }

        return [
            'passed' => true,
            'strong_attempt_count' => $strong,
            'required_strong_attempts' => $required,
            'reason' => '直近2回で高い理解度が続き、重大な弱点Signalも残っていません。',
        ];
    }

    private function isStrongAttempt($attempt): bool
    {
        if ((int) $attempt->score_percent < 85) {
            return false;
        }

        if (collect($attempt->weaknesses ?? [])->filter()->isNotEmpty()) {
            return false;
        }

        $blockingErrors = collect(data_get($attempt->assessment, 'question_feedback', []))
            ->filter(fn ($feedback) => is_array($feedback))
            ->contains(function (array $feedback) {
                if (! in_array((string) ($feedback['correctness'] ?? ''), ['incorrect', 'partial'], true)) {
                    return false;
                }

                return in_array((string) ($feedback['error_type'] ?? 'unknown'), [
                    'knowledge_gap',
                    'concept_gap',
                    'reasoning_gap',
                    'condition_reading',
                    'unit_error',
                    'unknown',
                ], true);
            });

        return ! $blockingErrors;
    }

    /**
     * @param array<string,mixed> $verification
     * @return array<string,mixed>
     */
    private function afterCompletion(Plan $plan, Task $task, array $verification): array
    {
        $nextTask = $this->nextEligibleTask($plan, $task);

        if ($nextTask) {
            return [
                'kind' => 'advance_task',
                'label' => '次のTask「'.$nextTask->title.'」へ進む',
                'reason' => '現在Taskの理解が安定したため、次に実行可能なTaskへ学習対象を切り替えます。',
                'verification' => $verification,
                'next_task' => $nextTask,
            ];
        }

        return [
            'kind' => 'plan_complete',
            'label' => 'このPlanの学習完了を確認する',
            'reason' => '現在Taskの理解が安定し、次に実行可能なTaskがありません。',
            'verification' => $verification,
            'next_task' => null,
        ];
    }

    private function nextEligibleTask(Plan $plan, Task $currentTask): ?Task
    {
        $tasks = $plan->tasks()->with('prerequisites')->get();
        $byId = $tasks->keyBy('id');

        return $tasks
            ->filter(function (Task $candidate) use ($currentTask, $byId) {
                if ((int) $candidate->id === (int) $currentTask->id) {
                    return false;
                }

                if (in_array($candidate->status, ['done', 'cancelled'], true) || (int) $candidate->progress_percent >= 100) {
                    return false;
                }

                return collect($candidate->dependencyIds())->every(function (int $dependencyId) use ($currentTask, $byId) {
                    if ($dependencyId === (int) $currentTask->id) {
                        return true;
                    }

                    $dependency = $byId->get($dependencyId);

                    return $dependency
                        && ($dependency->status === 'done' || (int) $dependency->progress_percent >= 100);
                });
            })
            ->sort(function (Task $left, Task $right) {
                $leftRank = [
                    $left->status === 'doing' ? 0 : 1,
                    max(1, min(5, (int) $left->priority)),
                    max(1, min(5, (int) ($left->activation_cost ?? 3))),
                    (int) ($left->sort_order ?? PHP_INT_MAX),
                    (int) $left->id,
                ];
                $rightRank = [
                    $right->status === 'doing' ? 0 : 1,
                    max(1, min(5, (int) $right->priority)),
                    max(1, min(5, (int) ($right->activation_cost ?? 3))),
                    (int) ($right->sort_order ?? PHP_INT_MAX),
                    (int) $right->id,
                ];

                return $leftRank <=> $rightRank;
            })
            ->first();
    }
}
