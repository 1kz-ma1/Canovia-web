<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class ExecutionMapProjectionService
{
    public function __construct(
        private readonly MapExecutionContextService $context,
        private readonly ExecutionNavigationGraphService $navigationGraph,
        private readonly MapAttentionStateService $attention,
        private readonly MapHierarchyContextService $hierarchyContext,
    ) {}

    /**
     * Preserve the V43 execution projection contract while composing the new
     * Navigation Graph and Attention State boundaries.
     *
     * @return array{
     *     level:string,
     *     nodes: Collection<int,array<string,mixed>>,
     *     edges: Collection<int,array<string,mixed>>,
     *     center_node_id:?string,
     *     primary_node_id:?string,
     *     primary_launch:?array<string,mixed>,
     *     has_primary_action:bool,
     *     projection_key:string
     * }
     */
    public function build(Request $request): array
    {
        $context = $this->context->resolve($request);
        $graph = $this->navigationGraph->build($context);
        $attention = $this->attention->apply(
            $graph['nodes'],
            $graph['edges'],
            $context,
        );

        $primaryNodeId = $attention['primary_node_id'];
        $plan = $context['plan'] ?? null;
        $centerNodeId = $plan instanceof \App\Models\Plan
            ? 'plan:'.$plan->id
            : $primaryNodeId;

        $nodes = $attention['nodes']
            ->map(fn (array $node) => $this->withDirectNavigation($node, $primaryNodeId))
            ->values();
        $edges = $attention['edges']->values();

        return [
            'level' => 'l3',
            'nodes' => $nodes,
            'edges' => $edges,
            'center_node_id' => $centerNodeId,
            'primary_node_id' => $primaryNodeId,
            'primary_launch' => $attention['primary_launch'],
            'has_primary_action' => $attention['has_primary_action'],
            'hierarchy' => $this->hierarchyMetadata($request, $context),
            'projection_key' => $this->projectionKey($nodes, $edges, $primaryNodeId, $centerNodeId),
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function hierarchyMetadata(Request $request, array $context): array
    {
        $intent = $this->hierarchyContext->intent((string) $request->query('intent', 'execution'));
        $intentLabel = $this->hierarchyContext->intentLabel($intent);
        $plan = $context['plan'] ?? null;

        if (! $plan instanceof \App\Models\Plan) {
            $parentUrl = route('map.index', [
                'level' => \App\Enums\MapLevel::Domain->value,
                'intent' => $intent,
            ]);

            return [
                'depth' => $intent === 'execution' ? 2 : 3,
                'intent' => $intent,
                'intent_label' => $intentLabel,
                'current_label' => 'Execution',
                'parent_url' => $parentUrl,
                'breadcrumbs' => [
                    ['label' => 'Canovia', 'url' => route('map.index')],
                    ['label' => $intentLabel, 'url' => $parentUrl],
                    ['label' => 'Execution', 'url' => null],
                ],
            ];
        }

        $domainKey = $this->hierarchyContext->domainKey($plan->category);
        $domainLabel = $this->hierarchyContext->domainLabel($plan->category);

        $collaborationContextKey = $intent === 'collaboration'
            ? trim((string) $request->query('collab_context', ''))
            : '';
        $collaborationDefinition = array_key_exists(
            $collaborationContextKey,
            CollaborationContextService::CONTEXTS,
        )
            ? CollaborationContextService::CONTEXTS[$collaborationContextKey]
            : null;

        if (is_array($collaborationDefinition)) {
            $collaborationLabel = (string) ($collaborationDefinition['label'] ?? '共同Context');
            $parentUrl = route('map.index', [
                'level' => \App\Enums\MapLevel::Plan->value,
                'intent' => 'collaboration',
                'collab_context' => $collaborationContextKey,
            ]);

            return [
                'depth' => 3,
                'intent' => 'collaboration',
                'intent_label' => '共同',
                'domain_key' => $domainKey,
                'domain_label' => $domainLabel,
                'collaboration_context_key' => $collaborationContextKey,
                'collaboration_context_label' => $collaborationLabel,
                'plan_id' => (int) $plan->id,
                'plan_label' => (string) $plan->title,
                'current_label' => (string) $plan->title,
                'parent_url' => $parentUrl,
                'breadcrumbs' => [
                    ['label' => 'Canovia', 'url' => route('map.index')],
                    [
                        'label' => '共同',
                        'url' => route('map.index', [
                            'level' => \App\Enums\MapLevel::Domain->value,
                            'intent' => 'collaboration',
                        ]),
                    ],
                    [
                        'label' => $collaborationLabel,
                        'url' => $parentUrl,
                    ],
                    ['label' => (string) $plan->title, 'url' => null],
                ],
            ];
        }

        if ($intent === 'execution') {
            $parentUrl = route('map.index', [
                'level' => \App\Enums\MapLevel::Domain->value,
                'intent' => 'execution',
            ]);

            return [
                'depth' => 2,
                'intent' => 'execution',
                'intent_label' => '実行',
                'plan_id' => (int) $plan->id,
                'plan_label' => (string) $plan->title,
                'current_label' => (string) $plan->title,
                'parent_url' => $parentUrl,
                'breadcrumbs' => [
                    ['label' => 'Canovia', 'url' => route('map.index')],
                    ['label' => '実行', 'url' => $parentUrl],
                    ['label' => (string) $plan->title, 'url' => null],
                ],
            ];
        }

        $parentUrl = route('map.index', [
            'level' => \App\Enums\MapLevel::Plan->value,
            'intent' => $intent,
            'domain' => $domainKey,
            'plan' => $plan->id,
        ]);

        return [
            'depth' => 3,
            'intent' => $intent,
            'intent_label' => $intentLabel,
            'domain_key' => $domainKey,
            'domain_label' => $domainLabel,
            'plan_id' => (int) $plan->id,
            'plan_label' => (string) $plan->title,
            'current_label' => (string) $plan->title,
            'parent_url' => $parentUrl,
            'breadcrumbs' => [
                ['label' => 'Canovia', 'url' => route('map.index')],
                [
                    'label' => $intentLabel,
                    'url' => route('map.index', [
                        'level' => \App\Enums\MapLevel::Domain->value,
                        'intent' => $intent,
                    ]),
                ],
                [
                    'label' => $domainLabel,
                    'url' => route('map.index', [
                        'level' => \App\Enums\MapLevel::Plan->value,
                        'intent' => $intent,
                        'domain' => $domainKey,
                        'plan' => $plan->id,
                    ]),
                ],
                ['label' => (string) $plan->title, 'url' => null],
            ],
        ];
    }

    /**
     * Add a direct-open destination only when the node has one clear canonical place.
     *
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private function withDirectNavigation(array $node, ?string $primaryNodeId): array
    {
        $node['direct_navigation'] = null;

        if (($node['id'] ?? null) === $primaryNodeId) {
            return $node;
        }

        if (! in_array(($node['type'] ?? null), ['goal', 'plan', 'tool', 'evidence', 'inbox'], true)) {
            return $node;
        }

        $actions = collect(data_get($node, 'classic_surface.actions', []));
        $action = $actions->first(fn ($candidate) => is_array($candidate) && ($candidate['primary'] ?? false))
            ?? $actions->first();

        if (! is_array($action) || ! filled($action['url'] ?? null)) {
            return $node;
        }

        $node['direct_navigation'] = [
            'label' => (string) ($action['label'] ?? '開く'),
            'url' => (string) $action['url'],
        ];

        return $node;
    }

    private function projectionKey(
        Collection $nodes,
        Collection $edges,
        ?string $primaryNodeId,
        ?string $centerNodeId,
    ): string {
        $payload = [
            'center_node_id' => $centerNodeId,
            'primary_node_id' => $primaryNodeId,
            'nodes' => $nodes->all(),
            'edges' => $edges->all(),
        ];

        return hash(
            'sha256',
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
