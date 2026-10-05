<?php

namespace App\Intelligence\Study;

use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Services\StudyPracticeStrategyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class StudyWorkspaceRecommendationService
{
    public function __construct(
        private readonly StudyPracticeStrategyService $strategies,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function recommend(
        Plan $plan,
        Task $task,
        ?int $userId,
        ?string $actorToken,
    ): array {
        $resumable = $this->resumableSession(
            $plan,
            $task,
            $userId,
            $actorToken,
        );

        if ($resumable instanceof StudyPracticeSession) {
            $storedStrategy = data_get(
                $resumable->selection_context,
                'strategy',
            );
            $strategy = is_array($storedStrategy)
                ? $storedStrategy
                : [];

            return $this->present(
                $plan,
                $task,
                $strategy,
                true,
                $resumable,
            );
        }

        $historyLimit = max(
            4,
            (int) config(
                'study.exam_convergence.history_attempt_limit',
                16,
            ),
        );
        $attempts = $this->attemptQuery(
            $plan,
            $task,
            $userId,
            $actorToken,
        )
            ->latest('created_at')
            ->latest('id')
            ->take($historyLimit)
            ->get();

        $strategy = $this->strategies->build(
            $plan,
            $task,
            $attempts,
        );

        return $this->present(
            $plan,
            $task,
            $strategy,
            false,
            null,
        );
    }

    /**
     * @param array<string,mixed> $strategy
     * @return array<string,mixed>
     */
    private function present(
        Plan $plan,
        Task $task,
        array $strategy,
        bool $resume,
        ?StudyPracticeSession $session,
    ): array {
        $questionCount = max(
            0,
            (int) ($strategy['target_question_count'] ?? 0),
        );
        if (
            $questionCount === 0
            && $session instanceof StudyPracticeSession
        ) {
            $questionCount = count(
                is_array($session->questions_snapshot)
                    ? $session->questions_snapshot
                    : [],
            );
        }

        $phase = (string) data_get(
            $strategy,
            'learning_phase.phase',
            '',
        );
        $phaseLabel = trim((string) data_get(
            $strategy,
            'learning_phase.label',
            '',
        ));
        $strategyLabel = trim((string) (
            $strategy['label']
            ?? ($resume ? '途中の演習' : '次の演習')
        ));
        $questionMix = is_array(
            $strategy['question_mix'] ?? null
        )
            ? $strategy['question_mix']
            : [];

        return [
            'source' => $resume ? 'session' : 'strategy',
            'resume' => $resume,
            'session_id' => $session?->id,
            'strategy_key' => (string) (
                $strategy['key']
                ?? $session?->strategy
                ?? 'existing_practice'
            ),
            'strategy_version' => (string) (
                $strategy['version']
                ?? $session?->strategy_version
                ?? ''
            ),
            'title' => $resume
                ? '途中の演習を続ける'
                : ($strategyLabel !== '' ? $strategyLabel : '次の演習'),
            'strategy_label' => $strategyLabel,
            'question_count' => $questionCount,
            'phase' => $phase,
            'phase_label' => $phaseLabel,
            'reason' => trim((string) ($strategy['reason'] ?? '')),
            'task_mode' => (string) data_get(
                $strategy,
                'routing_policy.task_mode',
                data_get(
                    $strategy,
                    'learning_phase.task_mode',
                    'adaptive',
                ),
            ),
            'mix' => $this->mix(
                $phase,
                $questionMix,
            ),
            'primary_topics' => $this->strings(
                data_get(
                    $strategy,
                    'weakness_priority.primary_topics',
                    [],
                ),
            ),
            'secondary_topics' => $this->strings(
                data_get(
                    $strategy,
                    'weakness_priority.secondary_topics',
                    [],
                ),
            ),
            'retention_due_topics' => $this->strings(
                data_get(
                    $strategy,
                    'weakness_control.retention_due_topics',
                    [],
                ),
            ),
            'cooldown_topics' => $this->strings(
                data_get(
                    $strategy,
                    'weakness_control.cooldown_topics',
                    [],
                ),
            ),
            'mastered_topics' => $this->strings(
                data_get(
                    $strategy,
                    'weakness_control.mastered_topics',
                    [],
                ),
            ),
            'suppressed_parent_topics' => $this->strings(
                data_get(
                    $strategy,
                    'routing_policy.suppressed_parent_topics',
                    [],
                ),
            ),
            'preferred_parent_topics' => $this->strings(
                data_get(
                    $strategy,
                    'routing_policy.preferred_parent_topics',
                    [],
                ),
            ),
            'resume_progress' => $session
                ? $this->resumeProgress($session)
                : null,
            'url' => route(
                'plans.tasks.study_practice.show',
                [$plan, $task],
            ),
            'action_label' => $resume
                ? '続きから再開'
                : (
                    $questionCount > 0
                        ? $questionCount.'問始める'
                        : '演習を始める'
                ),
        ];
    }

    /**
     * @param array<string,mixed> $questionMix
     * @return array<int,array{key:string,label:string,count:int}>
     */
    private function mix(
        string $phase,
        array $questionMix,
    ): array {
        $labels = match ($phase) {
            'weakness_reinforcement' => [
                'primary' => '重点弱点',
                'secondary' => '関連弱点',
                'diagnostic' => '確認問題',
            ],
            'diagnosis' => [
                'primary' => '重点確認',
                'secondary' => '補助確認',
                'diagnostic' => '現在地診断',
            ],
            'exam_mode' => [
                'primary' => '重点確認',
                'secondary' => '定着確認',
                'diagnostic' => '本番横断',
            ],
            default => [
                'primary' => '弱点の再確認',
                'secondary' => '定着確認',
                'diagnostic' => '横断・未探索',
            ],
        };

        return collect(['primary', 'secondary', 'diagnostic'])
            ->map(function (string $key) use (
                $questionMix,
                $labels,
            ) {
                return [
                    'key' => $key,
                    'label' => $labels[$key],
                    'count' => max(
                        0,
                        (int) ($questionMix[$key] ?? 0),
                    ),
                ];
            })
            ->filter(fn (array $item) => $item['count'] > 0)
            ->values()
            ->all();
    }

    /**
     * @return array{answered:int,total:int}
     */
    private function resumeProgress(
        StudyPracticeSession $session,
    ): array {
        $questions = is_array($session->questions_snapshot)
            ? $session->questions_snapshot
            : [];
        $draftAnswers = is_array($session->draft_answers)
            ? $session->draft_answers
            : [];

        $answered = collect($questions)
            ->filter(fn ($question) => is_array($question))
            ->filter(function (array $question) use ($draftAnswers) {
                $questionId = trim((string) (
                    $question['id'] ?? ''
                ));
                if ($questionId === '') {
                    return false;
                }

                $fields = is_array(
                    $draftAnswers[$questionId] ?? null
                )
                    ? $draftAnswers[$questionId]
                    : [];

                foreach ($fields as $value) {
                    if (is_array($value) && $value !== []) {
                        return true;
                    }

                    if (
                        is_scalar($value)
                        && trim((string) $value) !== ''
                    ) {
                        return true;
                    }
                }

                return false;
            })
            ->count();

        return [
            'answered' => $answered,
            'total' => count($questions),
        ];
    }

    private function resumableSession(
        Plan $plan,
        Task $task,
        ?int $userId,
        ?string $actorToken,
    ): ?StudyPracticeSession {
        return $this->sessionQuery(
            $plan,
            $task,
            $userId,
            $actorToken,
        )
            ->whereIn('status', [
                StudyPracticeSession::STATUS_READY,
                StudyPracticeSession::STATUS_IN_PROGRESS,
            ])
            ->latest('updated_at')
            ->latest('id')
            ->get()
            ->first(function (
                StudyPracticeSession $session
            ): bool {
                return is_array($session->questions_snapshot)
                    && $session->questions_snapshot !== [];
            });
    }

    private function attemptQuery(
        Plan $plan,
        Task $task,
        ?int $userId,
        ?string $actorToken,
    ): Builder {
        $query = StudyPracticeAttempt::query()
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id);

        if ($userId !== null) {
            return $query->where('user_id', $userId);
        }

        return $query
            ->whereNull('user_id')
            ->where('actor_token', (string) $actorToken);
    }

    private function sessionQuery(
        Plan $plan,
        Task $task,
        ?int $userId,
        ?string $actorToken,
    ): Builder {
        $query = StudyPracticeSession::query()
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id);

        if ($userId !== null) {
            return $query->where('user_id', $userId);
        }

        return $query
            ->whereNull('user_id')
            ->where('actor_token', (string) $actorToken);
    }

    /**
     * @return array<int,string>
     */
    private function strings(mixed $value): array
    {
        return Collection::wrap(
            is_array($value) ? $value : [],
        )
            ->filter(fn ($item) =>
                is_scalar($item)
                && trim((string) $item) !== ''
            )
            ->map(fn ($item) =>
                mb_substr(trim((string) $item), 0, 120)
            )
            ->unique()
            ->take(4)
            ->values()
            ->all();
    }
}
