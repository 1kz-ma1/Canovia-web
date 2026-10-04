<?php

namespace App\Services;

use App\Contracts\ExecutionProviderCatalog;
use App\Data\ExecutionProviderDefinitionData;
use App\Enums\ExecutionProviderKind;
use App\Execution\ExecutionCapability;
use Illuminate\Support\Collection;

final class ExecutionProviderRegistry implements ExecutionProviderCatalog
{
    public function __construct(
        private readonly bool $includeValidationProviders = false,
    ) {}

    /**
     * @return Collection<int,ExecutionProviderDefinitionData>
     */
    public function all(): Collection
    {
        $providers = [
            new ExecutionProviderDefinitionData(
                key: 'canovia.study.practice',
                name: 'Canovia Question Practice',
                kind: ExecutionProviderKind::Native,
                capabilities: [ExecutionCapability::STUDY_PRACTICE],
            ),
            new ExecutionProviderDefinitionData(
                key: 'canovia.study.recall',
                name: 'Canovia Recall',
                kind: ExecutionProviderKind::Native,
                capabilities: [ExecutionCapability::STUDY_RECALL],
            ),
            new ExecutionProviderDefinitionData(
                key: 'canovia.study.resource',
                name: 'Canovia Resource Study',
                kind: ExecutionProviderKind::Native,
                capabilities: [ExecutionCapability::STUDY_RESOURCE],
            ),
            new ExecutionProviderDefinitionData(
                key: 'canovia.development',
                name: 'Canovia Development Flow',
                kind: ExecutionProviderKind::Native,
                capabilities: [ExecutionCapability::CODING_REPOSITORY],
            ),
            new ExecutionProviderDefinitionData(
                key: 'github',
                name: 'GitHub',
                kind: ExecutionProviderKind::External,
                capabilities: [ExecutionCapability::CODING_REPOSITORY],
            ),
            new ExecutionProviderDefinitionData(
                key: 'canovia.general',
                name: 'Canovia General Execution',
                kind: ExecutionProviderKind::Native,
                capabilities: [ExecutionCapability::GENERAL_TASK],
            ),
        ];

        if ($this->includeValidationProviders) {
            $providers[] = new ExecutionProviderDefinitionData(
                key: 'validation.study.practice.external',
                name: 'External Practice Partner (Validation)',
                kind: ExecutionProviderKind::External,
                capabilities: [ExecutionCapability::STUDY_PRACTICE],
            );
        }

        return collect($providers);
    }

    public function find(string $providerKey): ?ExecutionProviderDefinitionData
    {
        $providerKey = mb_strtolower(trim($providerKey));

        if ($providerKey === '') {
            return null;
        }

        return $this->all()
            ->first(fn (ExecutionProviderDefinitionData $provider) =>
                $provider->key === $providerKey
            );
    }

    /**
     * @return Collection<int,ExecutionProviderDefinitionData>
     */
    public function forCapability(string $capability): Collection
    {
        $capability = ExecutionCapability::normalize($capability);

        return $this->all()
            ->filter(fn (ExecutionProviderDefinitionData $provider) =>
                $provider->supports($capability)
            )
            ->values();
    }
}
