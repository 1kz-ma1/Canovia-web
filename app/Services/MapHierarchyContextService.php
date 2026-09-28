<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class MapHierarchyContextService
{
    public const INTENTS = ['plan', 'execution', 'reflection', 'collaboration'];

    public function __construct(
        private readonly PlanOwnershipService $ownership,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function resolve(Request $request): array
    {
        $plans = $this->ownership->ownedPlans($request, [
            'goalContext',
            'memberships',
            'tasks' => fn ($query) => $query
                ->orderBy('sort_order')
                ->orderBy('id'),
        ]);

        $intent = $this->intent((string) $request->query('intent', 'plan'));
        $domains = $this->domains($plans);

        $requestedDomainKey = trim((string) $request->query('domain', ''));
        $selectedDomain = $domains->firstWhere('key', $requestedDomainKey)
            ?? $domains->first();

        $requestedPlanId = max(0, (int) $request->query('plan', 0));
        $selectedPlan = null;
        if ($requestedPlanId > 0) {
            $selectedPlan = $plans->firstWhere('id', $requestedPlanId);
        }
        if (! $selectedPlan && is_array($selectedDomain)) {
            $selectedPlan = collect($selectedDomain['plans'] ?? [])->first();
        }

        if ($selectedPlan instanceof Plan && is_array($selectedDomain)) {
            $belongsToSelectedDomain = collect($selectedDomain['plans'] ?? [])
                ->contains(fn (Plan $plan) => (int) $plan->id === (int) $selectedPlan->id);

            if (! $belongsToSelectedDomain) {
                $selectedDomain = $domains->first(function (array $domain) use ($selectedPlan) {
                    return collect($domain['plans'] ?? [])
                        ->contains(fn (Plan $plan) => (int) $plan->id === (int) $selectedPlan->id);
                }) ?? $selectedDomain;
            }
        }

        return [
            'intent' => $intent,
            'intent_label' => $this->intentLabel($intent),
            'plans' => $plans,
            'domains' => $domains,
            'selected_domain' => $selectedDomain,
            'selected_plan' => $selectedPlan,
        ];
    }

    public function intent(string $value): string
    {
        return in_array($value, self::INTENTS, true) ? $value : 'plan';
    }

    public function intentLabel(string $intent): string
    {
        return match ($this->intent($intent)) {
            'execution' => '実行',
            'reflection' => '振り返り',
            'collaboration' => '共同',
            default => '計画',
        };
    }

    public function domainKey(?string $category): string
    {
        $label = $this->domainLabel($category);

        return substr(hash('sha256', mb_strtolower($label)), 0, 12);
    }

    public function domainLabel(?string $category): string
    {
        $label = trim((string) $category);

        return $label !== '' ? mb_substr($label, 0, 120) : '未分類';
    }

    /**
     * @param Collection<int,Plan> $plans
     * @return Collection<int,array{key:string,label:string,plans:Collection<int,Plan>,plan_count:int,active_task_count:int,collaborative_count:int}>
     */
    private function domains(Collection $plans): Collection
    {
        return $plans
            ->groupBy(fn (Plan $plan) => $this->domainKey($plan->category))
            ->map(function (Collection $domainPlans) {
                /** @var Plan|null $first */
                $first = $domainPlans->first();
                $sorted = $domainPlans
                    ->sort(function (Plan $left, Plan $right) {
                        $priority = max(1, min(5, (int) $left->priority))
                            <=> max(1, min(5, (int) $right->priority));
                        if ($priority !== 0) {
                            return $priority;
                        }

                        $deadline = ($left->deadline?->timestamp ?? PHP_INT_MAX)
                            <=> ($right->deadline?->timestamp ?? PHP_INT_MAX);
                        if ($deadline !== 0) {
                            return $deadline;
                        }

                        return (int) $left->id <=> (int) $right->id;
                    })
                    ->values();

                return [
                    'key' => $this->domainKey($first?->category),
                    'label' => $this->domainLabel($first?->category),
                    'plans' => $sorted,
                    'plan_count' => $sorted->count(),
                    'active_task_count' => $sorted
                        ->flatMap(fn (Plan $plan) => $plan->tasks)
                        ->filter(fn ($task) => ! in_array($task->status, ['done', 'cancelled'], true)
                            && (int) $task->progress_percent < 100)
                        ->count(),
                    'collaborative_count' => $sorted
                        ->filter(fn (Plan $plan) => (bool) $plan->is_collaborative)
                        ->count(),
                ];
            })
            ->sortBy(fn (array $domain) => mb_strtolower((string) $domain['label']))
            ->values();
    }
}
