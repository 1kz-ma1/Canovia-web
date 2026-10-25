<?php

namespace App\Bus\Middleware;

use App\Services\ReleaseLevelService;
use Closure;
use RuntimeException;

/** Covers direct/synchronous bus handlers that never raise Queue::before. */
final class EnsurePreparationExecution
{
    public function __construct(private readonly ReleaseLevelService $release) {}

    public function handle(mixed $command, Closure $next): mixed
    {
        if ($this->release->preparationOnly()) {
            throw new RuntimeException('PREPARATION_EXECUTION_CLOSED');
        }

        return $next($command);
    }
}
