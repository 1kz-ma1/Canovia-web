<?php

namespace App\Services;

use App\Enums\MapLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class CollaborationMapProjectionService
{
    public function __construct(
        private readonly CollaborationContextService $context,
        private readonly CollaborationNavigationGraphService $navigationGraph,
        private readonly HierarchyMapAttentionStateService $attention,
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
            ? $this->workspacePayload($request, $context)
            : null;

        return [
            'level' => $level->value,
            'nodes' => $nodes,
            'edges' => $edges,
            'center_node_id' => $attention['center_node_id'],
            'primary_node_id' => null,
            'primary_launch' => null,
            'has_primary_action' => false,
            'collaboration_mode' => true,
            'collaboration_workspace_mode' => is_array($workspace),
            'collaboration_workspace' => $workspace,
            'hierarchy' => $this->hierarchyMetadata($level, $context),
            'projection_key' => $this->projectionKey(
                $level,
                $nodes,
                $edges,
                $attention['center_node_id'],
                $context,
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
                'external' => (bool) ($action['external'] ?? false),
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
        if ($level === MapLevel::Domain) {
            return [
                'depth' => 1,
                'intent' => 'collaboration',
                'intent_label' => '共同',
                'current_label' => '共同計画',
                'parent_url' => route('map.index'),
                'collaboration_mode' => true,
                'breadcrumbs' => [
                    ['label' => 'Canovia', 'url' => route('map.index')],
                    ['label' => '共同計画', 'url' => null],
                ],
            ];
        }

        $plan = $context['selected_plan'] ?? null;
        $label = $plan?->title ?: '共同計画を選択';

        return [
            'depth' => 2,
            'intent' => 'collaboration',
            'intent_label' => '共同',
            'collaboration_context_key' => null,
            'collaboration_context_label' => null,
            'collaboration_project_id' => $plan?->id,
            'current_label' => (string) $label,
            'parent_url' => route('map.index', [
                'level' => MapLevel::Domain->value,
                'intent' => 'collaboration',
            ]),
            'collaboration_mode' => true,
            'collaboration_workspace_mode' => $plan !== null,
            'breadcrumbs' => [
                ['label' => 'Canovia', 'url' => route('map.index')],
                [
                    'label' => '共同計画',
                    'url' => route('map.index', [
                        'level' => MapLevel::Domain->value,
                        'intent' => 'collaboration',
                    ]),
                ],
                ['label' => (string) $label, 'url' => null],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private function workspacePayload(Request $request, array $context): ?array
    {
        $plan = $context['selected_plan'] ?? null;
        if (! $plan instanceof \App\Models\Plan) {
            return null;
        }

        return [
            'plan' => $plan,
            'recent_artifacts' => $plan->artifacts->take(5)->values(),
            'recent_activities' => $plan->activityLogs->take(12)->values(),
            'can_manage' => $this->ownership->owns($request, $plan),
            'can_edit' => $this->ownership->canEdit($request, $plan),
            'role' => $this->ownership->role($request, $plan),
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private function workspaceDigest(array $context): ?array
    {
        $plan = $context['selected_plan'] ?? null;
        if (! $plan instanceof \App\Models\Plan) {
            return null;
        }

        return [
            'plan_id' => (int) $plan->id,
            'member_count' => 1 + $plan->memberships->count(),
            'artifact_count' => $plan->artifacts->count(),
            'activity_count' => $plan->activityLogs->count(),
            'latest_artifact' => (int) ($plan->artifacts->max(fn ($artifact) => $artifact->updated_at?->timestamp ?? 0) ?? 0),
            'latest_activity' => (int) ($plan->activityLogs->max(fn ($activity) => $activity->created_at?->timestamp ?? 0) ?? 0),
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
    ): string {
        return hash('sha256', (string) json_encode([
            'level' => $level->value,
            'intent' => 'collaboration',
            'center_node_id' => $centerNodeId,
            'selected_context' => data_get($context, 'selected_context.key'),
            'selected_plan_id' => data_get($context, 'selected_plan.id'),
            'workspace_digest' => $this->workspaceDigest($context),
            'nodes' => $nodes->all(),
            'edges' => $edges->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
