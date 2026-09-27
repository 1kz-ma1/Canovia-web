<?php

namespace App\Services;

use Illuminate\Support\Collection;

class ExecutionActionPolicyService
{
    /**
     * One Task should expose one primary execution action.
     * Specialized recommended tools win. Timer is only the fallback when
     * Canovia cannot identify a more appropriate execution method.
     *
     * @param iterable<int,array<string,mixed>> $tools
     */
    public function primary(iterable $tools): ?array
    {
        $tools = collect($tools);

        $specialized = $tools
            ->filter(fn (array $tool) => ($tool['id'] ?? null) !== 'timer' && (bool) ($tool['recommended'] ?? false))
            ->sortBy(fn (array $tool) => $this->priority((string) ($tool['id'] ?? '')))
            ->first();

        if ($specialized) {
            return array_merge($specialized, ['is_fallback' => false]);
        }

        $timer = $tools->firstWhere('id', 'timer');

        return $timer
            ? array_merge($timer, [
                'recommended' => true,
                'is_fallback' => true,
                'badge' => 'Fallback',
            ])
            : null;
    }

    public function isTimerFallback(?array $tool): bool
    {
        return ($tool['id'] ?? null) === 'timer' && (bool) ($tool['is_fallback'] ?? false);
    }

    private function priority(string $toolId): int
    {
        return match ($toolId) {
            'study_activity' => 0,
            'ai_practice' => 1,
            'career_workspace' => 2,
            'artifacts' => 3,
            'resources' => 4,
            'guided_execution' => 5,
            default => 50,
        };
    }
}
