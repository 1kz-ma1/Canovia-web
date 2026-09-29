<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanArtifact;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class GitHubWorkflowService
{
    public const UNCLASSIFIED = 'unclassified';

    public function __construct(
        private readonly PlanOwnershipService $ownership,
    ) {}

    /**
     * Build a user-facing GitHub workflow view from existing Canovia Artifacts.
     *
     * No GitHub network request is performed here. Remote PR / review / CI state
     * must not be inferred from URL shape or stale metadata.
     *
     * @return array<string,mixed>
     */
    public function dashboard(Request $request): array
    {
        $plans = $this->ownership->ownedPlans($request, [
            'tasks' => fn ($query) => $query
                ->orderBy('sort_order')
                ->orderBy('id'),
            'artifacts' => fn ($query) => $query
                ->where('provider', 'github')
                ->with([
                    'assignedUser:id,name',
                    'tasks:id,plan_id,title,status,progress_percent,sort_order',
                ])
                ->latest('updated_at')
                ->latest('id'),
        ])->values();

        $selectedPlanId = max(0, (int) $request->query('plan_id', 0));
        $selectedPlan = $selectedPlanId > 0
            ? $plans->firstWhere('id', $selectedPlanId)
            : null;

        $visiblePlans = $selectedPlan instanceof Plan
            ? collect([$selectedPlan])
            : $plans;

        $items = $visiblePlans
            ->flatMap(function (Plan $plan) use ($request) {
                $canEdit = $this->ownership->canEdit($request, $plan);

                return $plan->artifacts
                    ->filter(fn (PlanArtifact $artifact) => $artifact->provider === 'github')
                    ->map(fn (PlanArtifact $artifact) => $this->card($plan, $artifact, $canEdit));
            })
            ->sortByDesc(fn (array $item) => (int) ($item['updated_at_ts'] ?? 0))
            ->values();

        // Repository URLs are navigation roots, not pieces of work.
        // Keep them out of the decision lanes and use them to group the
        // GitHub objects Canovia already knows about for the same Plan/repo.
        $repositoryOverviews = $items
            ->filter(fn (array $item) => filled($item['repo_full_name'] ?? null))
            ->groupBy(fn (array $item) => $item['plan_id'].'|'.mb_strtolower((string) $item['repo_full_name']))
            ->map(fn (Collection $repoItems) => $this->repositoryOverview($repoItems))
            ->sortByDesc(fn (array $overview) => (int) ($overview['updated_at_ts'] ?? 0))
            ->values();

        $workflowItems = $items
            ->reject(fn (array $item) => ($item['kind'] ?? null) === 'repository')
            ->values();

        $lanes = collect(PlanArtifact::GITHUB_WORKFLOW_STATES)
            ->map(function (string $label, string $key) use ($workflowItems) {
                return [
                    'key' => $key,
                    'label' => $label,
                    'items' => $workflowItems
                        ->filter(fn (array $item) => ($item['workflow_state'] ?? null) === $key)
                        ->values(),
                ];
            })
            ->values();

        $unclassified = $workflowItems
            ->filter(fn (array $item) => ($item['workflow_state'] ?? null) === self::UNCLASSIFIED)
            ->values();

        $editablePlans = $plans
            ->filter(fn (Plan $plan) => $this->ownership->canEdit($request, $plan))
            ->values();

        return [
            'plans' => $plans,
            'editable_plans' => $editablePlans,
            'selected_plan' => $selectedPlan,
            'items' => $items,
            'workflow_items' => $workflowItems,
            'repository_overviews' => $repositoryOverviews,
            'lanes' => $lanes,
            'unclassified' => $unclassified,
            'workflow_states' => PlanArtifact::GITHUB_WORKFLOW_STATES,
            'summary' => [
                'total' => $workflowItems->count(),
                'repositories' => $repositoryOverviews->count(),
                'unclassified' => $unclassified->count(),
                'now' => $workflowItems->where('workflow_state', 'now')->count(),
                'review' => $workflowItems->where('workflow_state', 'review')->count(),
                'changes' => $workflowItems->where('workflow_state', 'changes')->count(),
                'merge' => $workflowItems->where('workflow_state', 'merge')->count(),
                'done' => $workflowItems->where('workflow_state', 'done')->count(),
            ],
        ];
    }

    /**
     * Build one repository-level overview from Canovia-known GitHub URLs.
     *
     * This is intentionally not a GitHub remote inventory. It only groups
     * artifacts that Canovia already owns for the same Plan/repository.
     *
     * @param Collection<int,array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function repositoryOverview(Collection $items): array
    {
        $items = $items
            ->sortByDesc(fn (array $item) => (int) ($item['updated_at_ts'] ?? 0))
            ->values();

        $repositoryItem = $items->first(
            fn (array $item) => ($item['kind'] ?? null) === 'repository',
        );
        $anchor = is_array($repositoryItem) ? $repositoryItem : $items->first();
        $workItems = $items
            ->reject(fn (array $item) => ($item['kind'] ?? null) === 'repository')
            ->values();

        $repoFullName = (string) ($anchor['repo_full_name'] ?? '');
        $repoUrl = is_array($repositoryItem)
            ? (string) ($repositoryItem['url'] ?? '')
            : 'https://github.com/'.$repoFullName;

        $linkedTasks = $items
            ->flatMap(fn (array $item) => $item['tasks'] ?? [])
            ->filter(fn ($task) => is_array($task) && isset($task['id']))
            ->unique(fn (array $task) => (int) $task['id'])
            ->values();

        $kindCounts = [
            'pull_request' => $workItems->where('kind', 'pull_request')->count(),
            'issue' => $workItems->where('kind', 'issue')->count(),
            'branch' => $workItems->where('kind', 'branch')->count(),
            'commit' => $workItems->where('kind', 'commit')->count(),
            'actions_run' => $workItems->where('kind', 'actions_run')->count(),
            'other' => $workItems
                ->reject(fn (array $item) => in_array(
                    $item['kind'] ?? null,
                    ['pull_request', 'issue', 'branch', 'commit', 'actions_run'],
                    true,
                ))
                ->count(),
        ];

        $workflowCounts = [
            'unclassified' => $workItems->where('workflow_state', self::UNCLASSIFIED)->count(),
            'now' => $workItems->where('workflow_state', 'now')->count(),
            'review' => $workItems->where('workflow_state', 'review')->count(),
            'changes' => $workItems->where('workflow_state', 'changes')->count(),
            'merge' => $workItems->where('workflow_state', 'merge')->count(),
            'done' => $workItems->where('workflow_state', 'done')->count(),
        ];

        $remoteSnapshot = is_array($repositoryItem['repository_snapshot'] ?? null)
            ? $repositoryItem['repository_snapshot']
            : null;

        return [
            'key' => ($anchor['plan_id'] ?? '0').'|'.mb_strtolower($repoFullName),
            'repo_full_name' => $repoFullName,
            'url' => $repoUrl,
            'plan_id' => (int) ($anchor['plan_id'] ?? 0),
            'plan_title' => (string) ($anchor['plan_title'] ?? ''),
            'plan_icon' => (string) ($anchor['plan_icon'] ?? ''),
            'repository_registered' => is_array($repositoryItem),
            'repository_artifact_id' => is_array($repositoryItem)
                ? (int) ($repositoryItem['id'] ?? 0)
                : null,
            'details_url' => is_array($repositoryItem)
                ? ($repositoryItem['details_url'] ?? null)
                : null,
            'can_edit' => (bool) ($anchor['can_edit'] ?? false),
            'remote_snapshot' => $remoteSnapshot,
            'work_count' => $workItems->count(),
            'kind_counts' => $kindCounts,
            'workflow_counts' => $workflowCounts,
            'linked_tasks' => $linkedTasks,
            'recent_items' => $workItems->take(5)->values(),
            'updated_at_ts' => $items->max('updated_at_ts') ?? 0,
        ];
    }

    /**
     * Parse only structural information encoded in a github.com URL.
     *
     * @return array{
     *     valid:bool,
     *     repo_full_name:?string,
     *     kind:string,
     *     kind_label:string,
     *     number:?int,
     *     branch:?string,
     *     reference:string,
     *     suggested_title:string
     * }
     */
    public function parseUrl(string $url): array
    {
        $url = trim($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?: $host;

        if ($host !== 'github.com') {
            return $this->invalidParse();
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = array_values(array_filter(
            explode('/', $path),
            fn ($segment) => $segment !== '',
        ));

        if (count($segments) < 2) {
            return [
                ...$this->invalidParse(),
                'valid' => true,
                'kind' => 'github',
                'kind_label' => 'GitHub',
                'reference' => 'GitHub',
                'suggested_title' => 'GitHub',
            ];
        }

        $owner = rawurldecode((string) $segments[0]);
        $repo = preg_replace('/\.git$/i', '', rawurldecode((string) $segments[1])) ?: rawurldecode((string) $segments[1]);
        $repoFullName = $owner.'/'.$repo;
        $kind = 'repository';
        $kindLabel = 'Repository';
        $number = null;
        $branch = null;
        $reference = $repoFullName;

        $resource = strtolower((string) ($segments[2] ?? ''));
        $resourceId = (string) ($segments[3] ?? '');

        if ($resource === 'pull' && ctype_digit($resourceId)) {
            $kind = 'pull_request';
            $kindLabel = 'Pull Request';
            $number = (int) $resourceId;
            $reference = 'PR #'.$number;
        } elseif ($resource === 'issues' && ctype_digit($resourceId)) {
            $kind = 'issue';
            $kindLabel = 'Issue';
            $number = (int) $resourceId;
            $reference = 'Issue #'.$number;
        } elseif ($resource === 'commit' && $resourceId !== '') {
            $kind = 'commit';
            $kindLabel = 'Commit';
            $reference = 'Commit '.mb_substr($resourceId, 0, 8);
        } elseif ($resource === 'actions' && strtolower((string) ($segments[3] ?? '')) === 'runs' && ctype_digit((string) ($segments[4] ?? ''))) {
            $kind = 'actions_run';
            $kindLabel = 'Actions';
            $number = (int) $segments[4];
            $reference = 'Actions #'.$number;
        } elseif ($resource === 'tree' && count($segments) >= 4) {
            $kind = 'branch';
            $kindLabel = 'Branch';
            $branch = rawurldecode(implode('/', array_slice($segments, 3)));
            $reference = $branch !== '' ? $branch : 'Branch';
        } elseif ($resource !== '') {
            $kind = 'github_link';
            $kindLabel = 'GitHub';
            $reference = $repoFullName;
        }

        return [
            'valid' => true,
            'repo_full_name' => $repoFullName,
            'kind' => $kind,
            'kind_label' => $kindLabel,
            'number' => $number,
            'branch' => $branch,
            'reference' => $reference,
            'suggested_title' => $kind === 'repository'
                ? $repoFullName
                : $repoFullName.' · '.$reference,
        ];
    }

    public function suggestedTitle(string $url): string
    {
        $parsed = $this->parseUrl($url);

        return (string) ($parsed['suggested_title'] ?? 'GitHub');
    }

    /**
     * @return array<string,mixed>
     */
    private function card(Plan $plan, PlanArtifact $artifact, bool $canEdit): array
    {
        $parsed = $this->parseUrl((string) $artifact->url);
        $state = $artifact->githubWorkflowState() ?? self::UNCLASSIFIED;

        return [
            'id' => (int) $artifact->id,
            'artifact' => $artifact,
            'plan_id' => (int) $plan->id,
            'plan_title' => (string) $plan->title,
            'plan_icon' => $plan->displayIcon(),
            'title' => (string) $artifact->title,
            'url' => (string) $artifact->url,
            'workflow_state' => $state,
            'workflow_state_label' => $state === self::UNCLASSIFIED
                ? '未整理'
                : (PlanArtifact::GITHUB_WORKFLOW_STATES[$state] ?? '未整理'),
            'repo_full_name' => $parsed['repo_full_name'],
            'kind' => $parsed['kind'],
            'kind_label' => $parsed['kind_label'],
            'reference' => $parsed['reference'],
            'branch' => $parsed['branch'],
            'repository_snapshot' => ($parsed['kind'] ?? null) === 'repository'
                && is_array(data_get($artifact->metadata, 'github_repository_snapshot'))
                    ? data_get($artifact->metadata, 'github_repository_snapshot')
                    : null,
            'assigned_user_name' => $artifact->assignedUser?->name,
            'tasks' => $artifact->tasks
                ->map(fn ($task) => [
                    'id' => (int) $task->id,
                    'title' => (string) $task->title,
                    'status' => (string) $task->status,
                ])
                ->values(),
            'can_edit' => $canEdit,
            'details_url' => route('plans.artifacts.index', $plan).'#artifact-'.$artifact->id,
            'updated_at' => $artifact->updated_at,
            'updated_at_ts' => $artifact->updated_at?->timestamp ?? 0,
            'remote_status' => null,
        ];
    }

    /**
     * @return array{valid:bool,repo_full_name:?string,kind:string,kind_label:string,number:?int,branch:?string,reference:string,suggested_title:string}
     */
    private function invalidParse(): array
    {
        return [
            'valid' => false,
            'repo_full_name' => null,
            'kind' => 'invalid',
            'kind_label' => 'GitHub',
            'number' => null,
            'branch' => null,
            'reference' => 'GitHub',
            'suggested_title' => 'GitHub',
        ];
    }
}
