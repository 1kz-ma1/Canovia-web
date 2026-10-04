<?php

namespace App\Services;

use App\Contracts\ExecutionProviderCatalog;
use App\Data\ExecutionProviderDefinitionData;
use App\Data\ExecutionSetupData;
use App\Models\Plan;
use App\Models\PlanExecutionPreference;
use App\Models\Task;
use InvalidArgumentException;

final class ExecutionSetupService
{
    public function __construct(
        private readonly ExecutionProviderCatalog $providers,
        private readonly ExecutionCapabilityResolver $capabilities,
        private readonly ExecutionResolver $resolver,
    ) {}

    public function inspect(Plan $plan, Task $task): ExecutionSetupData
    {
        $this->assertTaskPlan($plan, $task);

        $capability = $this->capabilities->forTask($plan, $task);
        $providers = $this->providers
            ->forCapability($capability)
            ->filter(
                fn (ExecutionProviderDefinitionData $provider) =>
                    $provider->enabled
            )
            ->values();

        $preference = PlanExecutionPreference::query()
            ->where('plan_id', $plan->id)
            ->where('capability', $capability)
            ->first();

        $explicitProvider = $preference instanceof PlanExecutionPreference
            ? $providers->first(
                fn (ExecutionProviderDefinitionData $provider) =>
                    $provider->key === $preference->provider_key
            )
            : null;

        $selected = $explicitProvider
            ?? $this->resolver->resolve($plan, $task, $capability)->provider;

        return new ExecutionSetupData(
            capability: $capability,
            providers: $providers->all(),
            selectedProvider: $selected,
            hasExplicitPreference:
                $explicitProvider instanceof ExecutionProviderDefinitionData,
        );
    }

    public function choose(
        Plan $plan,
        Task $task,
        string $providerKey,
    ): PlanExecutionPreference {
        $setup = $this->inspect($plan, $task);
        $providerKey = mb_strtolower(trim($providerKey));

        $provider = collect($setup->providers)->first(
            fn (ExecutionProviderDefinitionData $candidate) =>
                $candidate->key === $providerKey
        );

        if (! $provider instanceof ExecutionProviderDefinitionData) {
            throw new InvalidArgumentException(
                'Selected execution provider is not available for this Task.',
            );
        }

        return PlanExecutionPreference::query()->updateOrCreate(
            [
                'plan_id' => $plan->id,
                'capability' => $setup->capability,
            ],
            [
                'provider_key' => $provider->key,
                'user_selected' => true,
            ],
        );
    }

    private function assertTaskPlan(Plan $plan, Task $task): void
    {
        if ((int) $task->plan_id !== (int) $plan->id) {
            throw new InvalidArgumentException(
                'Execution Setup can only be changed inside the Task Plan.',
            );
        }
    }
}
