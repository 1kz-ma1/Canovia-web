<?php

namespace App\Services;

use App\Enums\MapLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class ReflectionMapProjectionService
{
    public function __construct(
        private readonly ReflectionContextService $context,
        private readonly ReflectionNavigationGraphService $navigationGraph,
        private readonly HierarchyMapAttentionStateService $attention,
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

        return [
            'level' => $level->value,
            'nodes' => $nodes,
            'edges' => $edges,
            'center_node_id' => $attention['center_node_id'],
            'primary_node_id' => null,
            'primary_launch' => null,
            'has_primary_action' => false,
            'reflection_mode' => true,
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
                'intent' => 'reflection',
                'intent_label' => '振り返り',
                'current_label' => '振り返り',
                'parent_url' => route('map.index'),
                'reflection_mode' => true,
                'breadcrumbs' => [
                    ['label' => 'Canovia', 'url' => route('map.index')],
                    ['label' => '振り返り', 'url' => null],
                ],
            ];
        }

        $selected = is_array($context['selected_context'] ?? null)
            ? $context['selected_context']
            : [];
        $key = (string) ($selected['key'] ?? 'recent');
        $label = (string) ($selected['label'] ?? '最近の実績');

        return [
            'depth' => 2,
            'intent' => 'reflection',
            'intent_label' => '振り返り',
            'reflection_context_key' => $key,
            'reflection_context_label' => $label,
            'current_label' => $label,
            'parent_url' => route('map.index', [
                'level' => MapLevel::Domain->value,
                'intent' => 'reflection',
            ]),
            'reflection_mode' => true,
            'breadcrumbs' => [
                ['label' => 'Canovia', 'url' => route('map.index')],
                [
                    'label' => '振り返り',
                    'url' => route('map.index', [
                        'level' => MapLevel::Domain->value,
                        'intent' => 'reflection',
                    ]),
                ],
                ['label' => $label, 'url' => null],
            ],
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
            'intent' => 'reflection',
            'center_node_id' => $centerNodeId,
            'selected_context' => data_get($context, 'selected_context.key'),
            'nodes' => $nodes->all(),
            'edges' => $edges->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
