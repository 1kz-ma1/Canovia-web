<?php

namespace App\Services;

use App\Data\ExecutionLaunchDecisionData;
use App\Models\Plan;
use App\Models\Task;

final class ExecutionLaunchResolver
{
    public const VALIDATION_STUDY_PRACTICE_PROVIDER =
        'validation.study.practice.external';

    public function __construct(
        private readonly ExecutionResolver $resolver,
    ) {}

    public function forStudyAction(
        Plan $plan,
        Task $task,
        string $fallbackRouteName,
    ): ExecutionLaunchDecisionData {
        $resolution = $this->resolver->resolve($plan, $task);
        $provider = $resolution->provider;

        if (
            $provider
            && $provider->key === self::VALIDATION_STUDY_PRACTICE_PROVIDER
        ) {
            return new ExecutionLaunchDecisionData(
                provider: $provider,
                routeName: 'execution.validation.study_practice',
                parameters: [$plan, $task],
                validationExternal: true,
            );
        }

        return new ExecutionLaunchDecisionData(
            provider: $provider
                ?? app(ExecutionProviderRegistry::class)
                    ->find('canovia.study.practice'),
            routeName: $fallbackRouteName,
            parameters: [$plan, $task],
        );
    }
}
