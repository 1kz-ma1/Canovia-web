<?php

namespace App\Services;

use App\Enums\MapLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class HierarchyMapProjectionService
{
    public function __construct(
        private readonly MapHierarchyContextService $context,
        private readonly HierarchyNavigationGraphService $navigationGraph,
        private readonly HierarchyMapAttentionStateService $attention,
        private readonly PlanProgressService $progress,
        private readonly RoadmapService $roadmap,
        private readonly RoadmapSpatialProjectionService $roadmapSpatial,
        private readonly PlanOwnershipService $ownership,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(Request $request, MapLevel $level): array
    {
        $context = $this->context->resolve($request);
        $graph = $this->navigationGraph->build($level, $context);
        $attention = $this->attention->apply(
            $graph['nodes'],
            $graph['edges'],
            $graph['center_node_id'],
        );

        $nodes = $attention['nodes']
            ->map(fn (array $node) => $this->withDirectNavigation($node))
            ->values();
        $edges = $attention['edges']->values();
        $workspace = $level === MapLevel::Plan
            && (string) ($context['intent'] ?? '') === 'plan'
            ? $this->planWorkspacePayload($request, $context)
            : null;

        return [
            'level' => $level->value,
            'nodes' => $nodes,
            'edges' => $edges,
            'center_node_id' => $attention['center_node_id'],
            'primary_node_id' => null,
            'primary_launch' => null,
            'has_primary_action' => false,
            'plan_workspace_mode' => is_array($workspace),
            'plan_workspace' => $workspace,
            'hierarchy' => $this->hierarchyMetadata($level, $context),
            'projection_key' => $this->projectionKey(
                $level,
                $nodes,
                $edges,
                $attention['center_node_id'],
                $context,
                $workspace,
            ),
        ];
    }

    /**
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private function withDirectNavigation(array $node): array
    {
        $actions = collect(data_get($node, 'classic_surface.actions', []));
        $action = $actions->first(fn ($candidate) => is_array($candidate) && ($candidate['primary'] ?? false))
            ?? $actions->first();

        $node['direct_navigation'] = is_array($action) && filled($action['url'] ?? null)
            ? [
                'label' => (string) ($action['label'] ?? '開く'),
                'url' => (string) $action['url'],
                'kind' => (string) ($action['navigation_kind'] ?? $node['navigation_kind'] ?? 'classic'),
            ]
            : null;

        return $node;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function hierarchyMetadata(MapLevel $level, array $context): array
    {
        $intent = (string) ($context['intent'] ?? 'plan');
        $intentLabel = (string) ($context['intent_label'] ?? '計画');
        $domain = $context['selected_domain'] ?? null;
        $plan = $context['selected_plan'] ?? null;

        if ($level === MapLevel::Domain) {
            return [
                'depth' => 1,
                'intent' => $intent,
                'intent_label' => $intentLabel,
                'current_label' => $intentLabel,
                'parent_url' => route('map.index'),
                'breadcrumbs' => [
                    ['label' => 'Canovia', 'url' => route('map.index')],
                    ['label' => $intentLabel, 'url' => null],
                ],
            ];
        }

        if ($level === MapLevel::Plan && $intent === 'plan' && $plan instanceof \App\Models\Plan) {
            $parentUrl = route('map.index', [
                'level' => MapLevel::Domain->value,
                'intent' => 'plan',
            ]);

            return [
                'depth' => 2,
                'intent' => 'plan',
                'intent_label' => '計画',
                'plan_id' => (int) $plan->id,
                'plan_label' => (string) $plan->title,
                'current_label' => (string) $plan->title,
                'parent_url' => $parentUrl,
                'breadcrumbs' => [
                    ['label' => 'Canovia', 'url' => route('map.index')],
                    ['label' => '計画', 'url' => $parentUrl],
                    ['label' => (string) $plan->title, 'url' => null],
                ],
            ];
        }

        $domainKey = is_array($domain) ? (string) ($domain['key'] ?? '') : '';
        $domainLabel = is_array($domain) ? (string) ($domain['label'] ?? '未分類') : '未分類';

        return [
            'depth' => 2,
            'intent' => $intent,
            'intent_label' => $intentLabel,
            'domain_key' => $domainKey,
            'domain_label' => $domainLabel,
            'plan_id' => $plan?->id,
            'current_label' => $domainLabel,
            'parent_url' => route('map.index', [
                'level' => MapLevel::Domain->value,
                'intent' => $intent,
            ]),
            'breadcrumbs' => [
                ['label' => 'Canovia', 'url' => route('map.index')],
                [
                    'label' => $intentLabel,
                    'url' => route('map.index', [
                        'level' => MapLevel::Domain->value,
                        'intent' => $intent,
                    ]),
                ],
                ['label' => $domainLabel, 'url' => null],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private function planWorkspacePayload(Request $request, array $context): ?array
    {
        $plan = $context['selected_plan'] ?? null;
        if (! $plan instanceof \App\Models\Plan) {
            return null;
        }

        $plan->loadMissing([
            'tasks.prerequisite',
            'tasks.prerequisites',
            'tasks.resources',
            'workLogs' => fn ($query) => $query
                ->with('task')
                ->latest('worked_on')
                ->latest('id'),
            'availabilityRules',
            'availabilityOverrides',
        ]);

        $progress = $this->progress->calculate($plan);
        $roadmap = $this->roadmap->build($plan);
        $roadmapSpatial = $this->roadmapSpatial->build($roadmap);

        return [
            'plan' => $plan,
            'progress' => $progress,
            'roadmap' => $roadmap,
            'roadmap_spatial' => $roadmapSpatial,
            'can_edit' => $this->ownership->canEdit($request, $plan),
            'can_manage' => $this->ownership->owns($request, $plan),
            'execution_url' => route('map.index', [
                'level' => MapLevel::Execution->value,
                'intent' => 'execution',
                'plan' => $plan->id,
            ]),
        ];
    }

    /**
     * @param array<string,mixed>|null $workspace
     * @return array<string,mixed>|null
     */
    private function planWorkspaceDigest(?array $workspace): ?array
    {
        if (! is_array($workspace)) {
            return null;
        }

        $plan = $workspace['plan'] ?? null;
        if (! $plan instanceof \App\Models\Plan) {
            return null;
        }

        return [
            'plan_id' => (int) $plan->id,
            'plan_updated_at' => (int) ($plan->updated_at?->timestamp ?? 0),
            'task_count' => $plan->tasks->count(),
            'latest_task' => (int) ($plan->tasks->max(fn ($task) => $task->updated_at?->timestamp ?? 0) ?? 0),
            'work_log_count' => $plan->workLogs->count(),
            'latest_work_log' => (int) ($plan->workLogs->max(fn ($log) => $log->updated_at?->timestamp ?? 0) ?? 0),
            'progress' => [
                'weighted' => data_get($workspace, 'progress.weighted_progress_percent'),
                'remaining' => data_get($workspace, 'progress.remaining_minutes_by_progress'),
                'status' => data_get($workspace, 'progress.status'),
            ],
            'roadmap_spatial_key' => data_get($workspace, 'roadmap_spatial.projection_key'),
        ];
    }

    /**
     * @param array<string,mixed> $context
     */
    private function projectionKey(
        MapLevel $level,
        Collection $nodes,
        Collection $edges,
        ?string $centerNodeId,
        array $context,
        ?array $workspace = null,
    ): string {
        return hash('sha256', (string) json_encode([
            'level' => $level->value,
            'center_node_id' => $centerNodeId,
            'intent' => $context['intent'] ?? null,
            'domain_key' => data_get($context, 'selected_domain.key'),
            'selected_plan_id' => data_get($context, 'selected_plan.id'),
            'plan_workspace_digest' => $this->planWorkspaceDigest($workspace),
            'nodes' => $nodes->all(),
            'edges' => $edges->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
