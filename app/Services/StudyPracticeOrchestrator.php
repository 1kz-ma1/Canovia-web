<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class StudyPracticeOrchestrator
{
    public function __construct(
        private readonly StudyPracticeStrategyService $strategyService,
        private readonly StudyPracticeProviderRouter $providerRouter,
        private readonly PracticeQuestionDemandRecorder $demandRecorder,
        private readonly PracticeQuestionCandidateRecorder $candidateRecorder,
    ) {}

    /**
     * @param Collection<int, mixed> $recentAttempts
     */
    public function preview(
        Plan $plan,
        Task $task,
        Collection $recentAttempts,
        array $planWideWeaknessHandoff = [],
    ): array {
        return $this->strategyService->build(
            $plan,
            $task,
            $recentAttempts,
            $planWideWeaknessHandoff,
        );
    }

    /**
     * Build the same provider handoff that will later be persisted, without
     * mutating the database. This keeps the current external-AI UX zero-cost
     * while allowing an embedded provider to replace it later.
     *
     * @param Collection<int, mixed> $recentAttempts
     * @return array{strategy:array<string,mixed>,provider:array<string,mixed>}
     */
    public function previewHandoff(
        Plan $plan,
        Task $task,
        Collection $recentAttempts,
        ?string $providerKey = null,
        array $planWideWeaknessHandoff = [],
    ): array {
        $strategy = $this->strategyService->build(
            $plan,
            $task,
            $recentAttempts,
            $planWideWeaknessHandoff,
        );
        $provider = $providerKey !== null
            ? $this->providerRouter->questionProviderByKey($providerKey)
            : $this->providerRouter->questionProvider($plan, $task, $strategy);

        return [
            'strategy' => $strategy,
            'provider' => $provider->prepare($plan, $task, $recentAttempts, $strategy, null),
        ];
    }

    /**
     * @param Collection<int, mixed> $recentAttempts
     */
    public function prepare(
        Plan $plan,
        Task $task,
        Collection $recentAttempts,
        ?int $userId,
        ?string $actorToken,
        string $prepareRequestId,
        ?string $providerKey = null,
        array $planWideWeaknessHandoff = [],
    ): StudyPracticeSession {
        $existing = StudyPracticeSession::query()
            ->where('prepare_request_id', $prepareRequestId)
            ->first();

        if ($existing) {
            if (
                (int) $existing->plan_id !== (int) $plan->id
                || (int) $existing->task_id !== (int) $task->id
                || ($userId !== null && (int) $existing->user_id !== $userId)
                || ($userId === null && (string) $existing->actor_token !== (string) $actorToken)
                || ($providerKey !== null && (string) $existing->question_provider !== $providerKey)
            ) {
                throw new RuntimeException('この演習準備リクエストは別の対象で使用済みです。');
            }

            $storedStrategy = is_array(data_get($existing->selection_context, 'strategy'))
                ? data_get($existing->selection_context, 'strategy')
                : $this->strategyService->build(
                    $plan,
                    $task,
                    $recentAttempts,
                    $planWideWeaknessHandoff,
                );
            $demand = $this->demandRecorder->record(
                $existing,
                $plan,
                $task,
                $storedStrategy,
                [
                    'provider' => $existing->question_provider,
                    'questions' => is_array($existing->questions_snapshot) ? $existing->questions_snapshot : [],
                    'payload' => is_array($existing->provider_payload) ? $existing->provider_payload : [],
                ],
            );
            $selectionContext = is_array($existing->selection_context) ? $existing->selection_context : [];
            if ((int) ($selectionContext['practice_demand_id'] ?? 0) !== (int) $demand->id) {
                $selectionContext['practice_demand_id'] = $demand->id;
                $existing->update(['selection_context' => $selectionContext]);
            }

            $this->candidateRecorder->record(
                $existing,
                $demand,
                $storedStrategy,
                [
                    'provider' => $existing->question_provider,
                    'questions' => is_array($existing->questions_snapshot) ? $existing->questions_snapshot : [],
                    'selected_questions' => is_array($existing->selected_questions) ? $existing->selected_questions : [],
                    'payload' => is_array($existing->provider_payload) ? $existing->provider_payload : [],
                ],
            );

            return $existing;
        }

        $strategy = $this->strategyService->build(
            $plan,
            $task,
            $recentAttempts,
            $planWideWeaknessHandoff,
        );

        $provider = $providerKey !== null
            ? $this->providerRouter->questionProviderByKey($providerKey)
            : $this->providerRouter->questionProvider($plan, $task, $strategy);
        $prepared = $provider->prepare($plan, $task, $recentAttempts, $strategy, $userId);

        $isDirect = (string) ($prepared['mode'] ?? 'handoff') === 'direct'
            && is_array($prepared['questions'] ?? null)
            && ($prepared['questions'] ?? []) !== [];

        $session = StudyPracticeSession::query()->createOrFirst(
            ['prepare_request_id' => $prepareRequestId],
            [
                'plan_id' => $plan->id,
                'task_id' => $task->id,
                'user_id' => $userId,
                'actor_token' => $userId ? null : $actorToken,
                'session_token' => (string) Str::uuid(),
                'status' => $isDirect
                    ? StudyPracticeSession::STATUS_READY
                    : StudyPracticeSession::STATUS_AWAITING_PROVIDER,
                'exercise_title' => $isDirect
                    ? mb_substr(trim((string) data_get($prepared, 'payload.title', 'Canovia Question Bank演習')), 0, 120)
                    : null,
                'strategy' => (string) $strategy['key'],
                'strategy_version' => (string) ($strategy['version'] ?? 'v1'),
                'selector_type' => (string) ($prepared['selector_type'] ?? 'external_ai'),
                'selector_version' => (string) ($prepared['selector_version'] ?? 'v1'),
                'question_provider' => (string) ($prepared['provider'] ?? 'external_ai'),
                'question_provider_mode' => (string) ($prepared['mode'] ?? 'handoff'),
                'assessment_provider' => 'external_ai',
                'assessment_provider_mode' => 'handoff',
                'selection_context' => [
                    'strategy' => $strategy,
                    'recent_attempt_ids' => $recentAttempts->pluck('id')->map(fn ($id) => (int) $id)->all(),
                ],
                'provider_payload' => is_array($prepared['payload'] ?? null) ? $prepared['payload'] : [],
                'selected_questions' => is_array($prepared['selected_questions'] ?? null)
                    ? $prepared['selected_questions']
                    : null,
                'questions_snapshot' => $isDirect ? $prepared['questions'] : null,
                'draft_answers' => null,
                'draft_saved_at' => null,
                'started_at' => now(),
            ]
        );

        if (
            (int) $session->plan_id !== (int) $plan->id
            || (int) $session->task_id !== (int) $task->id
            || ($userId !== null && (int) $session->user_id !== $userId)
            || ($userId === null && (string) $session->actor_token !== (string) $actorToken)
            || ($providerKey !== null && (string) $session->question_provider !== $providerKey)
        ) {
            throw new RuntimeException('この演習準備リクエストは別の対象で使用済みです。');
        }

        $demand = $this->demandRecorder->record(
            $session,
            $plan,
            $task,
            $strategy,
            $prepared,
        );
        $selectionContext = is_array($session->selection_context) ? $session->selection_context : [];
        $selectionContext['practice_demand_id'] = $demand->id;
        $session->update(['selection_context' => $selectionContext]);

        $this->candidateRecorder->record(
            $session,
            $demand,
            $strategy,
            $prepared,
        );

        return $session;
    }

    /**
     * @param array<int, array<string, mixed>> $questions
     * @param array<int, array<string, mixed>> $answers
     * @return array<string, mixed>
     */
    public function prepareAssessment(
        StudyPracticeSession $session,
        Plan $plan,
        Task $task,
        array $questions,
        array $answers,
        ?string $providerKey = null,
    ): array {
        $provider = $providerKey !== null
            ? $this->providerRouter->assessmentProviderByKey($providerKey)
            : $this->providerRouter->assessmentProvider($plan, $task, $questions, $answers);
        $prepared = $provider->prepare(
            $plan,
            $task,
            $questions,
            $answers,
            $session->user_id ? (int) $session->user_id : null,
            (int) $session->id,
        );

        $session->update([
            'assessment_provider' => (string) ($prepared['provider'] ?? $provider->key()),
            'assessment_provider_mode' => (string) ($prepared['mode'] ?? $provider->mode()),
            'assessment_payload' => is_array($prepared['payload'] ?? null) ? $prepared['payload'] : [],
        ]);

        return $prepared;
    }
}
