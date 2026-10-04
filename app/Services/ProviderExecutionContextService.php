<?php

namespace App\Services;

use App\Contracts\ExecutionProviderCatalog;
use App\Data\ProviderExecutionContextData;
use App\Execution\ExecutionCapability;
use App\Models\Plan;
use App\Models\ProviderConnection;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use Throwable;

final class ProviderExecutionContextService
{
    public const TTL_MINUTES = 30;

    public function __construct(
        private readonly ExecutionProviderCatalog $providers,
    ) {}

    /**
     * @return array{token:string,expires_at:CarbonImmutable}
     */
    public function issue(
        ProviderConnection $connection,
        Plan $plan,
        Task $task,
        string $capability,
    ): array {
        if (! $connection->isActive()) {
            throw new InvalidArgumentException(
                'Provider connection is not active.',
            );
        }

        if ((int) $task->plan_id !== (int) $plan->id) {
            throw new InvalidArgumentException(
                'Execution context Task must belong to the Plan.',
            );
        }

        $capability = ExecutionCapability::normalize($capability);
        $provider = $this->providers->find(
            (string) $connection->provider_key,
        );

        if (
            $provider === null
            || ! $provider->enabled
            || ! $provider->supports($capability)
        ) {
            throw new InvalidArgumentException(
                'Provider connection cannot execute this capability.',
            );
        }

        $expiresAt = CarbonImmutable::now()->addMinutes(
            self::TTL_MINUTES,
        );

        $payload = json_encode([
            'v' => 1,
            'connection_public_id' => (string) $connection->public_id,
            'user_id' => (int) $connection->user_id,
            'plan_id' => (int) $plan->id,
            'task_id' => (int) $task->id,
            'capability' => $capability,
            'issued_at' => now()->timestamp,
            'expires_at' => $expiresAt->timestamp,
        ], JSON_UNESCAPED_SLASHES);

        if (! is_string($payload)) {
            throw new InvalidArgumentException(
                'Execution context could not be encoded.',
            );
        }

        return [
            'token' => Crypt::encryptString($payload),
            'expires_at' => $expiresAt,
        ];
    }

    public function resolve(
        string $token,
        ProviderConnection $connection,
    ): ProviderExecutionContextData {
        try {
            $payload = json_decode(
                Crypt::decryptString($token),
                true,
            );
        } catch (Throwable) {
            throw new InvalidArgumentException(
                'Invalid execution context.',
            );
        }

        if (! is_array($payload) || (int) ($payload['v'] ?? 0) !== 1) {
            throw new InvalidArgumentException(
                'Unsupported execution context.',
            );
        }

        if (
            ! hash_equals(
                (string) $connection->public_id,
                (string) ($payload['connection_public_id'] ?? ''),
            )
            || (int) ($payload['user_id'] ?? 0)
                !== (int) $connection->user_id
        ) {
            throw new InvalidArgumentException(
                'Execution context does not belong to this connection.',
            );
        }

        $expiresAt = CarbonImmutable::createFromTimestamp(
            (int) ($payload['expires_at'] ?? 0),
        );

        if ($expiresAt->isPast()) {
            throw new InvalidArgumentException(
                'Execution context has expired.',
            );
        }

        $planId = (int) ($payload['plan_id'] ?? 0);
        $taskId = (int) ($payload['task_id'] ?? 0);
        $capability = ExecutionCapability::normalize(
            (string) ($payload['capability'] ?? ''),
        );

        $plan = Plan::query()->find($planId);
        $task = Task::query()->find($taskId);

        if (
            ! $plan
            || ! $task
            || (int) $task->plan_id !== (int) $plan->id
        ) {
            throw new InvalidArgumentException(
                'Execution context target is unavailable.',
            );
        }

        $provider = $this->providers->find(
            (string) $connection->provider_key,
        );

        if (
            ! $connection->isActive()
            || $provider === null
            || ! $provider->enabled
            || ! $provider->supports($capability)
        ) {
            throw new InvalidArgumentException(
                'Provider connection is unavailable.',
            );
        }

        return new ProviderExecutionContextData(
            plan: $plan,
            task: $task,
            capability: $capability,
            expiresAt: $expiresAt,
        );
    }
}
