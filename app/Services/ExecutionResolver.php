<?php

namespace App\Services;

use App\Contracts\ExecutionProviderCatalog;
use App\Data\ExecutionProviderDefinitionData;
use App\Data\ExecutionResolutionData;
use App\Execution\ExecutionCapability;
use App\Models\Plan;
use App\Models\PlanExecutionPreference;
use App\Models\Task;
use InvalidArgumentException;

final class ExecutionResolver
{
    public function __construct(
        private readonly ExecutionProviderCatalog $providers,
        private readonly ExecutionCapabilityResolver $capabilities,
    ) {}

    public function resolve(
        Plan $plan,
        Task $task,
        ?string $capability = null,
    ): ExecutionResolutionData {
        if ((int) $task->plan_id !== (int) $plan->id) {
            throw new InvalidArgumentException(
                'Execution provider can only be resolved inside the Task Plan.',
            );
        }

        $capability = ExecutionCapability::normalize(
            $capability ?? $this->capabilities->forTask($plan, $task),
        );

        $preference = PlanExecutionPreference::query()
            ->where('plan_id', $plan->id)
            ->where('capability', $capability)
            ->first();

        if ($preference instanceof PlanExecutionPreference) {
            $provider = $this->providers->find($preference->provider_key);

            if (
                $provider instanceof ExecutionProviderDefinitionData
                && $provider->enabled
                && $provider->supports($capability)
            ) {
                return new ExecutionResolutionData(
                    capability: $capability,
                    provider: $provider,
                    source: ExecutionResolutionData::SOURCE_PLAN_PREFERENCE,
                );
            }
        }

        $native = $this->providers
            ->forCapability($capability)
            ->first(fn (ExecutionProviderDefinitionData $provider) =>
                $provider->enabled && $provider->isNative()
            );

        if ($native instanceof ExecutionProviderDefinitionData) {
            return new ExecutionResolutionData(
                capability: $capability,
                provider: $native,
                source: ExecutionResolutionData::SOURCE_NATIVE_DEFAULT,
            );
        }

        return new ExecutionResolutionData(
            capability: $capability,
            provider: null,
            source: ExecutionResolutionData::SOURCE_CURRENT_FALLBACK,
        );
    }
}
