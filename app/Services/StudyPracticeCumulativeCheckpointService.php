<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use Illuminate\Support\Collection;

final class StudyPracticeCumulativeCheckpointService
{
    private const ATTEMPT_LIMIT = 500;

    private const CHECKPOINTS = [50, 100];

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

        $historyTruncated = $attempts->count()
            > self::ATTEMPT_LIMIT;
        $attempts = $attempts
            ->take(self::ATTEMPT_LIMIT)
            ->values();

        $events = collect();

        foreach ($attempts as $attempt) {
            $session = $attempt->practiceSession;
            $bankByRef = $this->bankQuestionMap(
                $session instanceof StudyPracticeSession
                    ? $session
                    : null,
            );
            $seenFeedbackIds = [];

            foreach (
                collect(data_get(
                    $attempt->assessment,
                    'question_feedback',
                    [],
                ))
                    ->filter(fn ($item) => is_array($item))
                    ->values()
                as $feedbackIndex => $feedback
            ) {
                $questionId = trim(
                    (string) (
                        $feedback['question_id']
                        ?? ''
                    ),
                );

                if (
                    $questionId === ''
                    || isset(
                        $seenFeedbackIds[$questionId]
                    )
                ) {
                    continue;
                }

                $correctness = trim(
                    (string) (
                        $feedback['correctness']
                        ?? ''
                    ),
                );

                if (! in_array(
                    $correctness,
                    [
                        'correct',
                        'partial',
                        'incorrect',
                    ],
                    true,
                )) {
                    continue;
                }

                $seenFeedbackIds[$questionId] = true;

                $events->push([
                    'attempt_id' => (int) $attempt->id,
                    'attempt_created_at' =>
                        $attempt->created_at,
                    'feedback_index' =>
                        (int) $feedbackIndex,
                    'question_ref' => $questionId,
                    'correctness' => $correctness,
                    'bank_question_id' =>
                        $bankByRef[$questionId]
                        ?? null,
                ]);
            }
        }

        $events = $events
            ->sortBy([
                ['attempt_created_at', 'asc'],
                ['attempt_id', 'asc'],
                ['feedback_index', 'asc'],
            ])
            ->values();

        $totals = $this->summarize($events);
        $checkpoints = collect(self::CHECKPOINTS)
            ->mapWithKeys(
                fn (int $target) => [
                    (string) $target =>
                        $this->checkpoint(
                            $events,
                            $target,
                        ),
                ],
            )
            ->all();

        $nextCheckpoint = match (true) {
            $totals['assessed_question_count'] < 50
                => 50,
            $totals['assessed_question_count'] < 100
                => 100,
            default => null,
        };

        $bankIds = $events
            ->pluck('bank_question_id')
            ->filter(
                fn ($id) =>
                    is_numeric($id)
                    && (int) $id > 0,
            )
            ->map(fn ($id) => (int) $id)
            ->values();

        $uniqueBankCount =
            $bankIds->unique()->count();
        $bankExposureCount =
            $bankIds->count();

        return [
            'version' => 'v1',
            'attempt_limit' =>
                self::ATTEMPT_LIMIT,
            'attempts_considered' =>
                $attempts->count(),
            'history_truncated' =>
                $historyTruncated,
            ...$totals,
            'next_checkpoint' =>
                $nextCheckpoint,
            'questions_to_next_checkpoint' =>
                $nextCheckpoint !== null
                    ? max(
                        0,
                        $nextCheckpoint
                            - $totals[
                                'assessed_question_count'
                            ],
                    )
                    : 0,
            'checkpoints' => $checkpoints,
            'bank_question_exposure_count' =>
                $bankExposureCount,
            'unique_bank_question_count' =>
                $uniqueBankCount,
            'repeated_bank_exposure_count' =>
                max(
                    0,
                    $bankExposureCount
                        - $uniqueBankCount,
                ),
            'has_bank_provenance' =>
                $bankExposureCount > 0,
            'complete' =>
                $totals[
                    'assessed_question_count'
                ] >= 100,
            'note' =>
                '採点済みquestion_feedbackだけを累積します。50問・100問checkpointはTask進捗や弱点routingを自動変更しません。',
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
            ->oldest('created_at')
            ->oldest('id')
            ->take(self::ATTEMPT_LIMIT + 1)
            ->get();
    }

    /**
     * @return array<string,int>
     */
    private function bankQuestionMap(
        ?StudyPracticeSession $session,
    ): array {
        if (! $session) {
            return [];
        }

        return collect(
            $session->selected_questions ?? [],
        )
            ->filter(fn ($item) => is_array($item))
            ->mapWithKeys(function (
                array $item,
            ) {
                $questionRef = trim(
                    (string) (
                        $item['question_ref']
                        ?? ''
                    ),
                );
                $questionId = $item[
                    'question_id'
                ] ?? null;

                if (
                    $questionRef === ''
                    || ! is_numeric($questionId)
                    || (int) $questionId <= 0
                ) {
                    return [];
                }

                return [
                    $questionRef =>
                        (int) $questionId,
                ];
            })
            ->all();
    }

    /**
     * @param Collection<int,array<string,mixed>> $events
     * @return array<string,mixed>
     */
    private function checkpoint(
        Collection $events,
        int $target,
    ): array {
        if ($events->count() < $target) {
            return [
                'target' => $target,
                'reached' => false,
                'assessed_question_count' =>
                    $events->count(),
                'correct_count' => null,
                'partial_count' => null,
                'incorrect_count' => null,
                'observed_correct_rate_percent' =>
                    null,
                'reached_at' => null,
                'attempt_id' => null,
            ];
        }

        $slice = $events
            ->take($target)
            ->values();
        $summary = $this->summarize(
            $slice,
        );
        $last = $slice->last();

        return [
            'target' => $target,
            'reached' => true,
            ...$summary,
            'reached_at' =>
                $last[
                    'attempt_created_at'
                ] ?? null,
            'attempt_id' =>
                (int) (
                    $last['attempt_id']
                    ?? 0
                ),
        ];
    }

    /**
     * @param Collection<int,array<string,mixed>> $events
     * @return array{
     *   assessed_question_count:int,
     *   correct_count:int,
     *   partial_count:int,
     *   incorrect_count:int,
     *   observed_correct_rate_percent:?int
     * }
     */
    private function summarize(
        Collection $events,
    ): array {
        $total = $events->count();
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
            'assessed_question_count' =>
                $total,
            'correct_count' => $correct,
            'partial_count' => $partial,
            'incorrect_count' => $incorrect,
            'observed_correct_rate_percent' =>
                $total > 0
                    ? (int) round(
                        (
                            $correct
                            / $total
                        )
                        * 100,
                    )
                    : null,
        ];
    }
}
