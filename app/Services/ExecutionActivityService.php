<?php

namespace App\Services;

use App\Contracts\ExecutionActivityProjector;
use App\Contracts\ExecutionProviderCatalog;
use App\Execution\ExecutionCapability;
use App\Models\ExecutionActivity;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ExecutionActivityService
{
    public function __construct(
        private readonly ExecutionProviderCatalog $providers,
        private readonly ExecutionActivityProjector $projector,
    ) {}

    /**
     * Store normalized execution facts.
     *
     * Raw provider payloads should be normalized by the provider adapter before
     * reaching this boundary. metadata is retained for traceability but never
     * crosses into Intelligence automatically.
     *
     * @param array<string,mixed> $metrics
     * @param array<string,mixed> $metadata
     */
    public function record(
        string $providerKey,
        string $capability,
        string $type,
        string $title,
        string $status,
        array $metrics = [],
        array $metadata = [],
        ?string $externalKey = null,
        ?int $userId = null,
        ?string $actorToken = null,
        ?CarbonInterface $startedAt = null,
        ?CarbonInterface $completedAt = null,
        ?int $durationSeconds = null,
    ): ExecutionActivity {
        $providerKey = mb_strtolower(trim($providerKey));
        $provider = $this->providers->find($providerKey);
        $capability = ExecutionCapability::normalize($capability);

        if (
            $provider === null
            || ! $provider->enabled
            || ! $provider->supports($capability)
        ) {
            throw new InvalidArgumentException(
                'Execution provider does not support this capability.',
            );
        }

        if ($userId === null && blank($actorToken)) {
            throw new InvalidArgumentException(
                'Execution Activity requires a user or actor token.',
            );
        }

        $status = mb_strtolower(trim($status));
        if (! in_array($status, ExecutionActivity::STATUSES, true)) {
            throw new InvalidArgumentException('Invalid execution activity status.');
        }

        $type = Str::limit(trim($type), 64, '');
        $title = Str::limit(trim($title), 255, '');

        if ($type === '' || $title === '') {
            throw new InvalidArgumentException(
                'Execution Activity type and title are required.',
            );
        }

        $externalKey = filled($externalKey)
            ? Str::limit(trim((string) $externalKey), 191, '')
            : null;
        $actorToken = $userId === null && filled($actorToken)
            ? Str::limit(trim((string) $actorToken), 64, '')
            : null;

        $values = [
            'user_id' => $userId,
            'actor_token' => $actorToken,
            'capability' => $capability,
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'duration_seconds' => $durationSeconds !== null
                ? max(0, $durationSeconds)
                : null,
            'metrics' => $metrics ?: null,
            'metadata' => $metadata ?: null,
        ];

        if ($externalKey !== null) {
            // External business keys are provider-local, not global actor IDs.
            // Keep the existing (provider_key, external_key) unique index while
            // making each persisted identity actor/connection-qualified.
            $connectionId = max(0, (int) ($metadata['provider_connection_id'] ?? 0));
            $scopedKey = 'actor:v2:'.hash('sha256', json_encode([
                $userId !== null ? 'user' : 'guest',
                $userId !== null ? (string) $userId : $actorToken,
                $connectionId,
                $externalKey,
            ], JSON_THROW_ON_ERROR));

            return DB::transaction(function () use (
                $providerKey,
                $externalKey,
                $scopedKey,
                $userId,
                $actorToken,
                $connectionId,
                $values,
            ): ExecutionActivity {
                $scope = ['provider_key' => $providerKey, 'external_key' => $scopedKey];

                // Prefer the scoped identity when it already exists. Check
                // ownership BEFORE updating any row, including a hash collision.
                $existing = ExecutionActivity::query()
                    ->where($scope)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if (! $this->matchesActor($existing, $userId, $actorToken, $connectionId)) {
                        throw new InvalidArgumentException('Activity identity belongs to a different actor.');
                    }

                    $existing->update($values);

                    return $existing->fresh();
                }

                // Adopt an existing V55.9 row only if its historical owner
                // AND connection match. This preserves Activity IDs and
                // projected Evidence IDs for valid same-connection retries.
                $legacy = ExecutionActivity::query()
                    ->where('provider_key', $providerKey)
                    ->where('external_key', $externalKey)
                    ->lockForUpdate()
                    ->first();

                if ($legacy && $this->matchesActor($legacy, $userId, $actorToken, $connectionId)) {
                    $legacy->update(['external_key' => $scopedKey, ...$values]);

                    return $legacy->fresh();
                }

                // An unrelated actor's legacy key is deliberately left alone.
                // createOrFirst handles concurrent insertion into the existing
                // unique (provider_key, external_key) index.
                $created = ExecutionActivity::query()->createOrFirst($scope, $values);

                if (! $this->matchesActor($created, $userId, $actorToken, $connectionId)) {
                    throw new InvalidArgumentException('Activity identity belongs to a different actor.');
                }

                if (! $created->wasRecentlyCreated) {
                    $created->update($values);
                }

                return $created->fresh();
            }, 3);
        }

        return ExecutionActivity::query()->create([
            'provider_key' => $providerKey,
            'external_key' => null,
            ...$values,
        ]);
    }

    private function matchesActor(
        ExecutionActivity $activity,
        ?int $userId,
        ?string $actorToken,
        int $connectionId,
    ): bool {
        $sameActor = $userId !== null
            ? $activity->user_id !== null
                && (int) $activity->user_id === $userId
                && blank($activity->actor_token)
            : $activity->user_id === null
                && (string) $activity->actor_token === (string) $actorToken;

        return $sameActor
            && (int) data_get($activity->metadata, 'provider_connection_id', 0) === $connectionId;
    }

    public function linkToTask(
        ExecutionActivity $activity,
        Task $task,
    ): ExecutionActivity {
        return DB::transaction(function () use ($activity, $task) {
            $locked = ExecutionActivity::query()
                ->whereKey($activity->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $locked->task_evidence_id !== null
                && (int) $locked->task_id !== (int) $task->id
            ) {
                throw new InvalidArgumentException(
                    'A projected Execution Activity cannot be moved to another Task.',
                );
            }

            if (
                $locked->plan_id !== null
                && (int) $locked->plan_id !== (int) $task->plan_id
            ) {
                throw new InvalidArgumentException(
                    'Execution Activity cannot be linked across Plans.',
                );
            }

            $locked->update([
                'plan_id' => (int) $task->plan_id,
                'task_id' => (int) $task->id,
            ]);

            $evidence = $this->projector->project($locked->fresh(), $task);

            $locked->update([
                'task_evidence_id' => (int) $evidence->id,
                'linked_at' => $locked->linked_at ?? now(),
            ]);

            return $locked->fresh();
        });
    }
}
