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

        $lanes = collect(PlanArtifact::GITHUB_WORKFLOW_STATES)
            ->map(function (string $label, string $key) use ($items) {
                return [
                    'key' => $key,
                    'label' => $label,
                    'items' => $items
                        ->filter(fn (array $item) => ($item['workflow_state'] ?? null) === $key)
                        ->values(),
                ];
            })
            ->values();

        $unclassified = $items
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
            'lanes' => $lanes,
            'unclassified' => $unclassified,
            'workflow_states' => PlanArtifact::GITHUB_WORKFLOW_STATES,
            'summary' => [
                'total' => $items->count(),
                'unclassified' => $unclassified->count(),
                'now' => $items->where('workflow_state', 'now')->count(),
                'review' => $items->where('workflow_state', 'review')->count(),
                'changes' => $items->where('workflow_state', 'changes')->count(),
                'merge' => $items->where('workflow_state', 'merge')->count(),
                'done' => $items->where('workflow_state', 'done')->count(),
            ],
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
