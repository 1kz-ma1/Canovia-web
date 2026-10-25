<?php

namespace App\Bus\Middleware;

use App\Services\PreparationExecutionGuard;
use Closure;

/** Covers direct/synchronous bus handlers that never raise Queue::before. */
final class EnsurePreparationExecution
{
    public function __construct(private readonly PreparationExecutionGuard $guard) {}

    public function handle(mixed $command, Closure $next): mixed
    {
        $this->guard->assertCanExecute();

        return $next($command);
    }
}
