<?php

namespace App\Services;

use App\Enums\EvidenceSource;
use App\Models\GuidedExecution;
use App\Models\Task;
use App\Models\TaskEvidence;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class GuidedExecutionService
{
    public function __construct(
        private readonly TaskEvidenceService $evidence,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public function prepare(
        Task $task,
        array $data,
        ?int $userId,
        string $actorToken,
    ): GuidedExecution {
        $requestId = (string) ($data['prepare_request_id'] ?? Str::uuid());

        $sameRequest = GuidedExecution::query()
            ->where('prepare_request_id', $requestId)
            ->first();

        if ($sameRequest) {
            $this->assertIdentity($sameRequest, $userId, $actorToken);

            return $sameRequest;
        }

        $active = $this->ownQuery($task, $userId, $actorToken)
            ->where('status', GuidedExecution::STATUS_PREPARED)
            ->latest('prepared_at')
            ->first();

        $values = [
            'user_id' => $userId,
            'actor_token' => $userId ? null : $actorToken,
            'plan_id' => (int) $task->plan_id,
            'task_id' => (int) $task->id,
            'status' => GuidedExecution::STATUS_PREPARED,
            'prepare_request_id' => $requestId,
            'intent' => trim((string) $data['intent']),
            'focus_points' => $this->lines($data['focus_points'] ?? null),
            'observation_points' => $this->lines($data['observation_points'] ?? null),
            'success_signal' => $this->nullableText($data['success_signal'] ?? null),
            'prepared_at' => now(),
            'metadata' => [
                'source' => (string) ($data['source'] ?? 'guided_execution'),
            ],
        ];

        if ($active) {
            $active->update($values);

            return $active->fresh();
        }

        return GuidedExecution::query()->create($values);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function reflect(
        GuidedExecution $execution,
        array $data,
        ?int $userId,
        string $actorToken,
    ): GuidedExecution {
        $this->assertIdentity($execution, $userId, $actorToken);
        $requestId = (string) ($data['reflection_request_id'] ?? Str::uuid());

        if ($execution->status === GuidedExecution::STATUS_COMPLETED) {
            if ($execution->reflection_request_id === $requestId || $execution->task_evidence_id) {
                return $execution;
            }

            throw ValidationException::withMessages([
                'reflection' => 'この実行はすでに振り返り済みです。',
            ]);
        }

        if ($execution->status !== GuidedExecution::STATUS_PREPARED) {
            throw ValidationException::withMessages([
                'reflection' => 'この実行は振り返りできる状態ではありません。',
            ]);
        }

        $execution->loadMissing('task');

        $actualOutcome = trim((string) $data['actual_outcome']);
        $observations = $this->nullableText($data['observations'] ?? null);
        $discoveries = $this->nullableText($data['discoveries'] ?? null);
        $nextAdjustment = $this->nullableText($data['next_adjustment'] ?? null);

        $structuredCount = collect([$observations, $discoveries, $nextAdjustment])
            ->filter(fn ($value) => filled($value))
            ->count();

        // Structured self-reflection is useful Evidence, but remains weaker than
        // an artifact/external metric and never drives progress automatically.
        $confidence = $structuredCount >= 2 ? 0.70 : ($structuredCount >= 1 ? 0.65 : 0.55);

        $evidence = $this->evidence->record(
            $execution->task,
            EvidenceSource::Native,
            'guided_execution_reflected',
            [
                'guided_execution_id' => (int) $execution->id,
                'intent' => $execution->intent,
                'focus_points' => $execution->focus_points ?? [],
                'observation_points' => $execution->observation_points ?? [],
                'success_signal' => $execution->success_signal,
                'outcome_rating' => (string) $data['outcome_rating'],
                'actual_outcome' => $actualOutcome,
                'observations' => $observations,
                'discoveries' => $discoveries,
                'next_adjustment' => $nextAdjustment,
                'evidence_strength' => $structuredCount >= 1
                    ? 'structured_self_reflection'
                    : 'self_report',
            ],
            confidence: $confidence,
            externalKey: 'guided-execution:'.$execution->id.':reflection',
            userId: $userId,
            actorToken: $userId ? null : $actorToken,
            occurredAt: now(),
        );

        $execution->update([
            'task_evidence_id' => $evidence->id,
            'status' => GuidedExecution::STATUS_COMPLETED,
            'reflection_request_id' => $requestId,
            'outcome_rating' => (string) $data['outcome_rating'],
            'actual_outcome' => $actualOutcome,
            'observations' => $observations,
            'discoveries' => $discoveries,
            'next_adjustment' => $nextAdjustment,
            'reflected_at' => now(),
        ]);

        return $execution->fresh(['evidence', 'task', 'plan']);
    }

    public function cancel(GuidedExecution $execution, ?int $userId, string $actorToken): GuidedExecution
    {
        $this->assertIdentity($execution, $userId, $actorToken);

        if ($execution->status === GuidedExecution::STATUS_PREPARED) {
            $execution->update(['status' => GuidedExecution::STATUS_CANCELLED]);
        }

        return $execution->fresh();
    }

    public function activeFor(Task $task, ?int $userId, string $actorToken): ?GuidedExecution
    {
        return $this->ownQuery($task, $userId, $actorToken)
            ->where('status', GuidedExecution::STATUS_PREPARED)
            ->latest('prepared_at')
            ->first();
    }

    public function latestCompletedFor(Task $task, ?int $userId, string $actorToken): ?GuidedExecution
    {
        return $this->ownQuery($task, $userId, $actorToken)
            ->where('status', GuidedExecution::STATUS_COMPLETED)
            ->latest('reflected_at')
            ->first();
    }

    public function assertIdentity(GuidedExecution $execution, ?int $userId, string $actorToken): void
    {
        if ($execution->user_id !== null) {
            abort_unless($userId !== null && (int) $execution->user_id === $userId, 404);

            return;
        }

        abort_unless(
            is_string($execution->actor_token)
            && $execution->actor_token !== ''
            && hash_equals($execution->actor_token, $actorToken),
            404,
        );
    }

    private function ownQuery(Task $task, ?int $userId, string $actorToken)
    {
        return GuidedExecution::query()
            ->where('task_id', $task->id)
            ->where(function ($query) use ($userId, $actorToken) {
                if ($userId !== null) {
                    $query->where('user_id', $userId);

                    return;
                }

                $query->whereNull('user_id')->where('actor_token', $actorToken);
            });
    }

    /**
     * @return array<int,string>
     */
    private function lines(mixed $value): array
    {
        return collect(preg_split('/\r\n|\r|\n/', (string) $value) ?: [])
            ->map(fn ($line) => trim($line))
            ->filter()
            ->take(8)
            ->map(fn ($line) => mb_substr($line, 0, 500))
            ->values()
            ->all();
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
