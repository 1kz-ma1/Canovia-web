<?php

namespace App\Intelligence\Contracts;

use App\Intelligence\Data\ReasoningProviderSelection;
use App\Intelligence\Data\ReasoningRequest;

interface DecisionReasoningProvider
{
    public function key(): string;

    public function isAvailable(): bool;

    public function select(ReasoningRequest $request): ReasoningProviderSelection;
}
