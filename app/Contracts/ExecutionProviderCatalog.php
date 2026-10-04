<?php

namespace App\Contracts;

use App\Data\ExecutionProviderDefinitionData;
use Illuminate\Support\Collection;

interface ExecutionProviderCatalog
{
    /**
     * @return Collection<int,ExecutionProviderDefinitionData>
     */
    public function all(): Collection;

    public function find(string $providerKey): ?ExecutionProviderDefinitionData;

    /**
     * @return Collection<int,ExecutionProviderDefinitionData>
     */
    public function forCapability(string $capability): Collection;
}
