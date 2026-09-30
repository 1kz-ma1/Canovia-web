<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class IntentMapProjectionService
{
    public function __construct(
        private readonly IntentNavigationGraphService $navigationGraph,
        private readonly IntentMapAttentionStateService $attention,
        private readonly SpaceStationContextService $spaceStation,
        private readonly PersonalizedSatelliteCandidateService $satelliteCandidates,
        private readonly PersonalizedSatellitePromotionService $satellitePromotion,
        private readonly MapPersonalizationPreferenceService $personalizationPreferences,
    ) {}

    /**
     * Build the L0 Intent Hub projection.
     *
     * Fixed Intent nodes come from the semantic graph. Personalized Satellites
     * are an Attention-layer promotion of contexts that already exist elsewhere
     * in Canovia and never replace/remove those canonical contexts.
     *
     * @return array<string,mixed>
     */
    public function build(Request $request): array
    {
        $graph = $this->navigationGraph->build();
        $attention = $this->attention->apply(
            $graph['nodes'],
            $graph['edges'],
        );
        $spaceStation = $this->spaceStation->build($request);
        $pinnedNodeIds = $this->personalizationPreferences->pinnedNodeIds($request->user());
        $satellites = $this->satellitePromotion->promote(
            $this->satelliteCandidates->candidates($request),
            2,
            $pinnedNodeIds,
        );

        $nodes = $attention['nodes']
            ->concat($satellites['nodes'])
            ->map(fn (array $node) => $this->withDirectNavigation($node))
            ->values();
        $edges = $attention['edges']
            ->concat($satellites['edges'])
            ->values();
        $centerNodeId = $attention['center_node_id'];

        return [
            'level' => 'l0',
            'nodes' => $nodes,
            'edges' => $edges,
            'center_node_id' => $centerNodeId,
            'primary_node_id' => null,
            'primary_launch' => null,
            'has_primary_action' => false,
            'hierarchy' => [
                'depth' => 0,
                'current_label' => 'Canovia',
                'parent_url' => null,
                'breadcrumbs' => [
                    ['label' => 'Canovia', 'url' => null],
                ],
            ],
            'space_station' => $spaceStation,
            'personalized_satellites' => [
                'count' => $satellites['nodes']->count(),
                'max' => 2,
                'pinned_count' => $satellites['nodes']->filter(
                    fn (array $node) => (bool) data_get($node, 'personalization.pinned', false)
                )->count(),
                'weights' => [
                    'importance' => 0.35,
                    'usage_frequency' => 0.25,
                    'recency' => 0.20,
                    'continuity' => 0.20,
                ],
            ],
            'projection_key' => $this->projectionKey(
                $nodes,
                $edges,
                $centerNodeId,
                (string) ($spaceStation['state_key'] ?? ''),
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

    private function projectionKey(
        Collection $nodes,
        Collection $edges,
        string $centerNodeId,
        string $spaceStationStateKey,
    ): string {
        return hash(
            'sha256',
            (string) json_encode([
                'level' => 'l0',
                'center_node_id' => $centerNodeId,
                'nodes' => $nodes->all(),
                'edges' => $edges->all(),
                'space_station_state_key' => $spaceStationStateKey,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
