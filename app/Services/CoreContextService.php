<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class CoreContextService
{
    /** @var Collection<int,Plan>|null */
    private ?Collection $plans = null;

    /** @var array<string,bool> */
    private array $loaded = [];

    private ?string $actorToken = null;

    private ?Request $activeRequest = null;

    public function __construct(
        private readonly PlanOwnershipService $ownership,
        private readonly BehaviorIdentityService $identity,
    ) {}

    /**
     * @param array<int,string> $features
     * @return Collection<int,Plan>
     */
    public function plans(Request $request, array $features = []): Collection
    {
        $this->bindRequest($request);

        if ($this->plans === null) {
            $this->plans = $this->ownership->ownedPlans($request);
        }

        foreach (array_values(array_unique($features)) as $feature) {
            $this->ensure($feature);
        }

        return $this->plans;
    }

    /** @return Collection<int,Plan> */
    public function editablePlans(Request $request, array $features = []): Collection
    {
        return $this->plans($request, $features)
            ->filter(fn (Plan $plan) => $this->ownership->canEdit($request, $plan))
            ->values();
    }

    public function actorToken(Request $request): string
    {
        $this->bindRequest($request);

        return $this->actorToken ??= $this->identity->resolve($request);
    }

    public function role(Request $request, Plan $plan): ?string
    {
        $this->bindRequest($request);

        return $this->ownership->role($request, $plan);
    }

    public function canEdit(Request $request, Plan $plan): bool
    {
        $this->bindRequest($request);

        return $this->ownership->canEdit($request, $plan);
    }

    public function owns(Request $request, Plan $plan): bool
    {
        $this->bindRequest($request);

        return $this->ownership->owns($request, $plan);
    }

    private function bindRequest(Request $request): void
    {
        if ($this->activeRequest === $request) {
            return;
        }

        $this->activeRequest = $request;
        $this->plans = null;
        $this->loaded = [];
        $this->actorToken = null;
    }

    private function ensure(string $feature): void
    {
        if (($this->loaded[$feature] ?? false) || $this->plans === null || $this->plans->isEmpty()) {
            $this->loaded[$feature] = true;
            return;
        }

        switch ($feature) {
            case 'tasks':
                $this->loadTasks();
                break;
            case 'task_dependencies':
                $this->loadTaskDependencies();
                break;
            case 'task_artifacts':
                $this->loadTaskArtifacts();
                break;
            case 'work_logs':
                $this->loadWorkLogs();
                break;
            case 'task_evidences':
                $this->loadTaskEvidences();
                break;
            case 'availability':
                $this->loadPlanRelations(['availabilityRules', 'availabilityOverrides']);
                break;
            case 'plan_resources':
                $this->loadPlanRelations(['resources']);
                break;
            case 'plan_artifacts':
                $this->loadPlanRelations(['artifacts']);
                break;
            case 'career':
                $this->loadPlanRelations([
                    'careerApplications.selectionEvents.interviewReview',
                    'careerCaptures',
                ]);
                break;
            case 'memberships':
                $this->loadPlanRelations(['memberships']);
                break;
        }

        $this->loaded[$feature] = true;
    }

    private function loadTasks(): void
    {
        $this->eloquentPlans()->loadMissing([
            'tasks' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
        ]);
    }

    private function loadTaskDependencies(): void
    {
        $this->ensure('tasks');

        $tasks = $this->plans
            ->flatMap(fn (Plan $plan) => $plan->tasks)
            ->values();

        if ($tasks->isEmpty()) {
            return;
        }

        (new EloquentCollection($tasks->all()))
            ->loadMissing(['prerequisite', 'prerequisites', 'resources']);
    }

    private function loadTaskArtifacts(): void
    {
        $this->ensure('tasks');

        $tasks = $this->plans
            ->flatMap(fn (Plan $plan) => $plan->tasks)
            ->values();

        if ($tasks->isEmpty()) {
            return;
        }

        (new EloquentCollection($tasks->all()))
            ->loadMissing(['artifacts']);
    }

    private function loadWorkLogs(): void
    {
        $this->eloquentPlans()->loadMissing([
            'workLogs' => fn ($query) => $query
                ->with('task')
                ->latest('worked_on')
                ->latest('id'),
        ]);
    }

    private function loadTaskEvidences(): void
    {
        $this->eloquentPlans()->loadMissing([
            'taskEvidences' => fn ($query) => $query
                ->with('task')
                ->latest('occurred_at')
                ->latest('id'),
        ]);
    }

    /** @param array<int,string> $relations */
    private function loadPlanRelations(array $relations): void
    {
        $this->eloquentPlans()->loadMissing($relations);
    }

    private function eloquentPlans(): EloquentCollection
    {
        return new EloquentCollection($this->plans?->all() ?? []);
    }
}
