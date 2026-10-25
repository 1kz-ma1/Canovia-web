<?php

namespace App\Services;

/** One application lifetime: observed STOP is terminal, not a retry permit. */
final class PreparationExecutionGuard
{
    private bool $stopped = false;

    public function __construct(private readonly ReleaseLevelService $release) {}

    public function stopped(): bool
    {
        $this->stopped = $this->stopped || $this->release->preparationOnly();

        return $this->stopped;
    }

    public function assertCanExecute(): void
    {
        if ($this->stopped()) {
            throw new \RuntimeException('PREPARATION_EXECUTION_CLOSED');
        }
    }
}
