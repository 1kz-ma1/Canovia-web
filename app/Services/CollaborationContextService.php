<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class CollaborationContextService
{
    public const CONTEXTS = [
        'my_action' => [
            'label' => '自分が進める',
            'eyebrow' => 'MY NEXT ACTION',
            'summary' => '自分が担当している制作物と、編集できる共同Planの次Actionをまとめます。',
        ],
        'review' => [
            'label' => 'レビュー待ち',
            'eyebrow' => 'REVIEW WAITING',
            'summary' => '人が明示的に「レビュー待ち」とした共同制作物をまとめます。',
        ],
        'waiting' => [
            'label' => '相手待ち',
            'eyebrow' => 'WAITING ON OTHERS',
            'summary' => '人が明示的に「相手待ち」とした共同制作物をまとめます。',
        ],
        'external' => [
            'label' => '外部Toolで確認',
            'eyebrow' => 'EXTERNAL FOLLOW-UP',
            'summary' => 'GitHub・Drive・OneDriveなど、Canovia外で確認する制作物をまとめます。',
        ],
    ];

    public function __construct(
        private readonly PlanOwnershipService $ownership,
        private readonly MapHierarchyContextService $hierarchy,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function resolve(Request $request): array
    {
        $plans = $this->ownership->ownedPlans($request, [
            'user:id,name,email',
            'memberships.user:id,name,email',
            'tasks' => fn ($query) => $query
                ->orderBy('sort_order')
                ->orderBy('id'),
            'artifacts' => fn ($query) => $query
                ->with([
                    'assignedUser:id,name',
                    'createdBy:id,name',
                    'tasks:id,plan_id,title,status,progress_percent,priority,sort_order',
                ])
                ->latest('updated_at')
                ->latest('id'),
            'activityLogs' => fn ($query) => $query
                ->with('user:id,name')
                ->latest('created_at')
                ->latest('id'),
        ])
            ->filter(fn (Plan $plan) => (bool) $plan->is_collaborative)
            ->values();

        $items = collect(array_keys(self::CONTEXTS))
            ->mapWithKeys(fn (string $key) => [$key => collect()]);

        $userId = $request->user()?->id;

        foreach ($plans as $plan) {
            $role = $this->ownership->role($request, $plan);
            $canEdit = $this->ownership->canEdit($request, $plan);
            $hasAssignedArtifact = false;

            foreach ($plan->artifacts as $artifact) {
                $item = $this->artifactItem($plan, $artifact, $role);
                $state = $artifact->collaborationState();
                $assignedToMe = $userId !== null
                    && (int) ($artifact->assigned_user_id ?? 0) === (int) $userId;
                $external = $artifact->provider !== 'canovia'
                    || $state === 'external_followup';

                if ($assignedToMe && ! in_array($state, ['review', 'waiting'], true)) {
                    $items['my_action']->push($item);
                    $hasAssignedArtifact = true;
                }

                if ($state === 'review') {
                    $items['review']->push($item);
                }

                if ($state === 'waiting') {
                    $items['waiting']->push($item);
                }

                if ($external) {
                    $items['external']->push($item);
                }
            }

            if ($canEdit && ! $hasAssignedArtifact) {
                $nextTask = $this->nextEditableTask($plan);

                if ($nextTask) {
                    $items['my_action']->push($this->taskItem($plan, $nextTask, $role));
                }
            }
        }

        $items = $items->map(fn (Collection $contextItems) => $contextItems
            ->unique('id')
            ->sortByDesc(fn (array $item) => (int) ($item['updated_at_ts'] ?? 0))
            ->take(12)
            ->values());

        $selectedKey = $this->contextKey((string) $request->query('collab_context', 'my_action'));
        $requestedPlanId = max(0, (int) $request->query('plan', 0));
        $selectedPlan = $requestedPlanId > 0
            ? $plans->first(fn (Plan $plan) => (int) $plan->id === $requestedPlanId)
            : null;

        $projects = $plans
            ->map(fn (Plan $plan) => $this->projectSummary($request, $plan));

        if ($selectedKey === 'review') {
            $projects = $projects
                ->filter(fn (array $project) => (int) ($project['review_count'] ?? 0) > 0);
        }

        $projects = $projects
            ->sort(function (array $left, array $right) use ($selectedKey) {
                if ($selectedKey === 'review') {
                    $reviewOrder = (int) ($right['review_count'] ?? 0)
                        <=> (int) ($left['review_count'] ?? 0);
                    if ($reviewOrder !== 0) {
                        return $reviewOrder;
                    }
                }

                $activityOrder = (int) ($right['updated_at_ts'] ?? 0)
                    <=> (int) ($left['updated_at_ts'] ?? 0);

                return $activityOrder !== 0
                    ? $activityOrder
                    : strcmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
            })
            ->values();

        $recentActivityCount = $plans
            ->flatMap(fn (Plan $plan) => $plan->activityLogs)
            ->filter(fn ($activity) => $activity->created_at?->gte(now()->subDays(14)))
            ->count();

        return [
            'plans' => $plans,
            'projects' => $projects,
            'selected_plan' => $selectedPlan,
            'contexts' => collect(self::CONTEXTS)->map(function (array $definition, string $key) use ($items) {
                return [
                    'key' => $key,
                    ...$definition,
                    'count' => $items->get($key, collect())->count(),
                    'items' => $items->get($key, collect()),
                ];
            })->values(),
            'selected_context' => [
                'key' => $selectedKey,
                ...self::CONTEXTS[$selectedKey],
                'count' => $items->get($selectedKey, collect())->count(),
                'items' => $items->get($selectedKey, collect()),
            ],
            'shared_plan_count' => $plans->count(),
            'member_count' => $plans->sum(fn (Plan $plan) => 1 + $plan->memberships->count()),
            'recent_activity_count' => $recentActivityCount,
        ];
    }

    public function contextKey(string $value): string
    {
        return array_key_exists($value, self::CONTEXTS) ? $value : 'my_action';
    }

    /**
     * @return array<string,mixed>
     */
    private function projectSummary(Request $request, Plan $plan): array
    {
        $reviewCount = $plan->artifacts
            ->filter(fn (PlanArtifact $artifact) => $artifact->collaborationState() === 'review')
            ->count();
        $waitingCount = $plan->artifacts
            ->filter(fn (PlanArtifact $artifact) => $artifact->collaborationState() === 'waiting')
            ->count();
        $latestArtifact = $plan->artifacts->max(fn (PlanArtifact $artifact) => $artifact->updated_at?->timestamp ?? 0);
        $latestActivity = $plan->activityLogs->max(fn ($activity) => $activity->created_at?->timestamp ?? 0);
        $latestTask = $plan->tasks->max(fn (Task $task) => $task->updated_at?->timestamp ?? 0);

        return [
            'id' => (int) $plan->id,
            'title' => (string) $plan->title,
            'category' => (string) ($plan->category ?? ''),
            'role' => $this->ownership->role($request, $plan),
            'can_edit' => $this->ownership->canEdit($request, $plan),
            'review_count' => $reviewCount,
            'waiting_count' => $waitingCount,
            'artifact_count' => $plan->artifacts->count(),
            'member_count' => 1 + $plan->memberships->count(),
            'active_task_count' => $plan->tasks
                ->filter(fn (Task $task) => ! in_array($task->status, ['done', 'cancelled'], true)
                    && (int) $task->progress_percent < 100)
                ->count(),
            'updated_at_ts' => max(
                (int) ($plan->updated_at?->timestamp ?? 0),
                (int) $latestArtifact,
                (int) $latestActivity,
                (int) $latestTask,
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function artifactItem(Plan $plan, PlanArtifact $artifact, ?string $role): array
    {
        $linkedTask = $artifact->tasks
            ->first(fn (Task $task) => ! in_array($task->status, ['done', 'cancelled'], true)
                && (int) $task->progress_percent < 100)
            ?? $artifact->tasks->first();

        return [
            'id' => 'artifact:'.$artifact->id,
            'kind' => 'artifact',
            'entity_id' => (int) $artifact->id,
            'plan_id' => (int) $plan->id,
            'plan_title' => (string) $plan->title,
            'domain_key' => $this->hierarchy->domainKey($plan->category),
            'domain_label' => $this->hierarchy->domainLabel($plan->category),
            'task_id' => $linkedTask?->id,
            'task_title' => $linkedTask?->title,
            'label' => (string) $artifact->title,
            'provider' => (string) $artifact->provider,
            'provider_label' => $artifact->providerLabel(),
            'external_kind' => $this->externalKind($artifact),
            'url' => (string) $artifact->url,
            'assigned_user_id' => $artifact->assigned_user_id ? (int) $artifact->assigned_user_id : null,
            'assigned_user_name' => $artifact->assignedUser?->name,
            'collaboration_state' => $artifact->collaborationState(),
            'collaboration_state_label' => $artifact->collaborationStateLabel(),
            'role' => $role,
            'updated_at_ts' => $artifact->updated_at?->timestamp ?? 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function taskItem(Plan $plan, Task $task, ?string $role): array
    {
        return [
            'id' => 'task:'.$task->id,
            'kind' => 'task',
            'entity_id' => (int) $task->id,
            'plan_id' => (int) $plan->id,
            'plan_title' => (string) $plan->title,
            'domain_key' => $this->hierarchy->domainKey($plan->category),
            'domain_label' => $this->hierarchy->domainLabel($plan->category),
            'task_id' => (int) $task->id,
            'task_title' => (string) $task->title,
            'label' => (string) $task->title,
            'provider' => 'canovia',
            'provider_label' => 'Canovia',
            'external_kind' => null,
            'url' => null,
            'assigned_user_id' => null,
            'assigned_user_name' => null,
            'collaboration_state' => 'active',
            'collaboration_state_label' => '進行中',
            'role' => $role,
            'updated_at_ts' => $task->updated_at?->timestamp ?? 0,
        ];
    }

    private function nextEditableTask(Plan $plan): ?Task
    {
        return $plan->tasks
            ->filter(fn (Task $task) => ! in_array($task->status, ['done', 'cancelled'], true)
                && (int) $task->progress_percent < 100)
            ->sort(function (Task $left, Task $right) {
                $doing = ($left->status === 'doing' ? 0 : 1)
                    <=> ($right->status === 'doing' ? 0 : 1);
                if ($doing !== 0) {
                    return $doing;
                }

                $priority = max(1, min(5, (int) $left->priority))
                    <=> max(1, min(5, (int) $right->priority));
                if ($priority !== 0) {
                    return $priority;
                }

                $sort = (int) ($left->sort_order ?? PHP_INT_MAX)
                    <=> (int) ($right->sort_order ?? PHP_INT_MAX);

                return $sort !== 0 ? $sort : (int) $left->id <=> (int) $right->id;
            })
            ->first();
    }

    private function externalKind(PlanArtifact $artifact): ?string
    {
        if ($artifact->provider === 'github') {
            $path = (string) parse_url((string) $artifact->url, PHP_URL_PATH);

            if (preg_match('#/pull/\d+(?:/|$)#', $path)) {
                return 'GitHub Pull Request';
            }

            if (preg_match('#/issues/\d+(?:/|$)#', $path)) {
                return 'GitHub Issue';
            }

            return 'GitHub';
        }

        return match ($artifact->provider) {
            'google_drive' => 'Google Drive',
            'onedrive' => 'OneDrive',
            'external' => 'External Link',
            default => null,
        };
    }
}
