<?php

namespace App\Services;

use App\Enums\MapLevel;
use App\Models\Plan;
use Illuminate\Support\Collection;

final class CollaborationNavigationGraphService
{
    /**
     * @param array<string,mixed> $context
     * @return array{nodes:Collection<int,array<string,mixed>>,edges:Collection<int,array<string,mixed>>,center_node_id:string|null}
     */
    public function build(MapLevel $level, array $context): array
    {
        return match ($level) {
            MapLevel::Domain => $this->contextGraph($context),
            MapLevel::Plan => $this->itemGraph($context),
            default => [
                'nodes' => collect(),
                'edges' => collect(),
                'center_node_id' => null,
            ],
        };
    }

    /**
     * L1: Shared Plan categories are replaced by stable purpose contexts.
     *
     * @param array<string,mixed> $context
     * @return array{nodes:Collection<int,array<string,mixed>>,edges:Collection<int,array<string,mixed>>,center_node_id:string}
     */
    private function contextGraph(array $context): array
    {
        $centerId = 'collaboration:hub';
        $sharedPlanCount = (int) ($context['shared_plan_count'] ?? 0);
        $memberCount = (int) ($context['member_count'] ?? 0);
        $recentActivityCount = (int) ($context['recent_activity_count'] ?? 0);

        $nodes = collect([
            $this->node(
                id: $centerId,
                type: 'collaboration_hub',
                eyebrow: 'L1 · COLLABORATION',
                label: '共同',
                subtitle: 'サービスではなく、共同作業の状態から辿る',
                action: route('map.index'),
                attentionRole: 'hierarchy-parent',
                navigationKind: 'zoom-out',
                classicSurface: $this->surface(
                    'Collaboration Hub',
                    '共同',
                    'Shared Planや外部Toolを、Canovia内の「次に何をするか」という目的で見渡します。外部サービスの状態は推測せず、Canoviaで確認できる事実だけを使います。',
                    [
                        $this->action('L0へ戻る', route('map.index'), true, 'zoom-out'),
                        $this->action('共同Plan一覧を開く', route('my_plans.index')),
                    ],
                    [
                        $sharedPlanCount.' Shared Plan',
                        $memberCount.' participants',
                        $recentActivityCount.' recent changes',
                    ],
                ),
            ),
        ]);

        $edges = collect();

        foreach (collect($context['contexts'] ?? []) as $index => $definition) {
            $key = (string) ($definition['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $nodeId = 'collaboration:context:'.$key;
            $url = route('map.index', [
                'level' => MapLevel::Plan->value,
                'intent' => 'collaboration',
                'collab_context' => $key,
            ]);

            $nodes->push($this->node(
                id: $nodeId,
                type: 'collaboration_context',
                eyebrow: (string) ($definition['eyebrow'] ?? 'COLLABORATION'),
                label: (string) ($definition['label'] ?? '共同Context'),
                subtitle: (int) ($definition['count'] ?? 0).' Context',
                action: $url,
                attentionRole: 'hierarchy-child',
                navigationKind: 'zoom-in',
                classicSurface: $this->surface(
                    'Collaboration Context',
                    (string) ($definition['label'] ?? '共同Context'),
                    (string) ($definition['summary'] ?? ''),
                    [
                        $this->action('このContextへ入る', $url, true, 'zoom-in'),
                        $this->action('共同Plan一覧を開く', route('my_plans.index')),
                    ],
                    [
                        (int) ($definition['count'] ?? 0).' items',
                        'Human-reviewed state',
                    ],
                ),
            ));

            $edges->push($this->edge(
                $centerId,
                $nodeId,
                'groups_collaboration_context',
                'hierarchy-child',
            ));
        }

        return [
            'nodes' => $nodes->values(),
            'edges' => $edges->values(),
            'center_node_id' => $centerId,
        ];
    }

    /**
     * L2: show concrete Artifact/Task contexts, not provider buckets.
     *
     * @param array<string,mixed> $context
     * @return array{nodes:Collection<int,array<string,mixed>>,edges:Collection<int,array<string,mixed>>,center_node_id:string}
     */
    private function itemGraph(array $context): array
    {
        $selected = is_array($context['selected_context'] ?? null)
            ? $context['selected_context']
            : [];
        $key = (string) ($selected['key'] ?? 'my_action');
        $label = (string) ($selected['label'] ?? '自分が進める');
        $centerId = 'collaboration:context:'.$key;
        $parentUrl = route('map.index', [
            'level' => MapLevel::Domain->value,
            'intent' => 'collaboration',
        ]);

        $nodes = collect([
            $this->node(
                id: $centerId,
                type: 'collaboration_context',
                eyebrow: 'L2 · PURPOSE',
                label: $label,
                subtitle: 'Shared Plan / Artifactを目的ベースで確認',
                action: $parentUrl,
                attentionRole: 'hierarchy-parent',
                navigationKind: 'zoom-out',
                classicSurface: $this->surface(
                    'Collaboration Context',
                    $label,
                    (string) ($selected['summary'] ?? ''),
                    [
                        $this->action('共同Context一覧へ戻る', $parentUrl, true, 'zoom-out'),
                        $this->action('共同Plan一覧を開く', route('my_plans.index')),
                    ],
                    [
                        (int) ($selected['count'] ?? 0).' items',
                        'L2',
                    ],
                ),
            ),
        ]);

        $edges = collect();

        foreach (collect($selected['items'] ?? []) as $item) {
            $itemId = 'collaboration:item:'.str_replace(':', '-', (string) ($item['id'] ?? ''));
            if ($itemId === 'collaboration:item:') {
                continue;
            }

            $planId = (int) ($item['plan_id'] ?? 0);
            if ($planId <= 0) {
                continue;
            }

            $executionUrl = route('map.index', array_filter([
                'level' => MapLevel::Execution->value,
                'intent' => 'collaboration',
                'collab_context' => $key,
                'domain' => (string) ($item['domain_key'] ?? ''),
                'plan' => $planId,
            ], fn ($value) => $value !== ''));

            $externalUrl = filled($item['url'] ?? null)
                ? (string) $item['url']
                : null;
            $isExternalPurpose = $key === 'external' && $externalUrl !== null;
            $primaryUrl = $isExternalPurpose ? $externalUrl : $executionUrl;
            $primaryKind = $isExternalPurpose ? 'external' : 'zoom-in';
            $providerLabel = (string) ($item['external_kind'] ?? $item['provider_label'] ?? 'Canovia');
            $stateLabel = (string) ($item['collaboration_state_label'] ?? '');
            $assigned = filled($item['assigned_user_name'] ?? null)
                ? '担当 '.(string) $item['assigned_user_name']
                : null;

            $actions = [
                $this->action(
                    $isExternalPurpose ? $providerLabel.'で確認' : 'Executionへ入る',
                    $primaryUrl,
                    true,
                    $primaryKind,
                    $isExternalPurpose,
                ),
            ];

            if (! $isExternalPurpose) {
                $actions[] = $this->action('共同Planを開く', route('plans.show', $planId));
            }

            if ($externalUrl && ! $isExternalPurpose) {
                $actions[] = $this->action(
                    $providerLabel.'を開く',
                    $externalUrl,
                    false,
                    'external',
                    true,
                );
            }

            $nodes->push($this->node(
                id: $itemId,
                type: 'collaboration_item',
                entityId: isset($item['entity_id']) ? (int) $item['entity_id'] : null,
                eyebrow: $this->itemEyebrow($key, (string) ($item['kind'] ?? 'artifact')),
                label: (string) ($item['label'] ?? '共同Context'),
                subtitle: (string) ($item['plan_title'] ?? 'Shared Plan').' · '.$providerLabel,
                action: $primaryUrl,
                attentionRole: 'hierarchy-child',
                navigationKind: $primaryKind,
                classicSurface: $this->surface(
                    'Collaboration Item',
                    (string) ($item['label'] ?? '共同Context'),
                    $this->itemSummary($key, $item),
                    $actions,
                    array_values(array_filter([
                        (string) ($item['plan_title'] ?? ''),
                        $providerLabel,
                        $stateLabel !== '' ? $stateLabel : null,
                        $assigned,
                        filled($item['task_title'] ?? null) ? 'Task '.(string) $item['task_title'] : null,
                    ])),
                ),
            ));

            $edges->push($this->edge(
                $centerId,
                $itemId,
                'contains_collaboration_item',
                'hierarchy-child',
            ));
        }

        if ($nodes->count() === 1) {
            $sharedPlansUrl = route('my_plans.index');
            $spaceStationUrl = route('map.index').'#dock=space-station';

            $nodes->push($this->node(
                id: 'collaboration:empty:plans',
                type: 'collaboration_item',
                eyebrow: 'NEXT OPTION',
                label: '共同Planを確認',
                subtitle: '該当Itemがまだないため、Shared Planの状態を確認',
                action: $sharedPlansUrl,
                attentionRole: 'hierarchy-child',
                navigationKind: 'direct',
                classicSurface: $this->surface(
                    'Collaboration Action',
                    '共同Planを確認',
                    'このContextに該当するItemはまだありません。Shared Plan側の担当・Artifact・状態を確認できます。',
                    [
                        $this->action('共同Plan一覧を開く', $sharedPlansUrl, true, 'direct'),
                    ],
                    ['Empty-state action'],
                ),
            ));
            $nodes->push($this->node(
                id: 'collaboration:empty:station',
                type: 'collaboration_item',
                eyebrow: 'NEXT OPTION',
                label: 'Space Stationで整理',
                subtitle: '状況を入力・相談して次の接続先を整理',
                action: $spaceStationUrl,
                attentionRole: 'hierarchy-child',
                navigationKind: 'direct',
                classicSurface: $this->surface(
                    'Collaboration Action',
                    'Space Stationで整理',
                    '対象がまだない、または次の接続先が不明なときは、Space Stationへ状況を持ち込んで整理できます。',
                    [
                        $this->action('Space Stationを開く', $spaceStationUrl, true, 'direct'),
                    ],
                    ['Empty-state action'],
                ),
            ));

            $edges->push($this->edge(
                $centerId,
                'collaboration:empty:plans',
                'offers_empty_state_action',
                'hierarchy-child',
            ));
            $edges->push($this->edge(
                $centerId,
                'collaboration:empty:station',
                'offers_empty_state_action',
                'hierarchy-child',
            ));
        }

        return [
            'nodes' => $nodes->values(),
            'edges' => $edges->values(),
            'center_node_id' => $centerId,
        ];
    }

    /**
     * @param array<string,mixed> $item
     */
    private function itemSummary(string $contextKey, array $item): string
    {
        if ($contextKey === 'review') {
            return 'この制作物はCanovia上で「レビュー待ち」と明示されています。レビュー完了を自動推測せず、状態変更は人が行います。';
        }

        if ($contextKey === 'waiting') {
            return 'この制作物はCanovia上で「相手待ち」と明示されています。相手の作業状況を外部サービスから推測しません。';
        }

        if ($contextKey === 'external') {
            $kind = (string) ($item['external_kind'] ?? $item['provider_label'] ?? '外部Tool');

            return $kind.'への確認導線です。Canoviaはリンク先のreview / merge状態を推測せず、確認先としてだけ再投影します。';
        }

        return ($item['kind'] ?? null) === 'task'
            ? '編集可能なShared Planから、現在進められる次Actionとして投影しています。'
            : '自分が担当者として設定されている共同制作物です。';
    }

    private function itemEyebrow(string $contextKey, string $kind): string
    {
        return match ($contextKey) {
            'review' => 'REVIEW WAITING',
            'waiting' => 'WAITING',
            'external' => 'EXTERNAL TOOL',
            default => $kind === 'task' ? 'NEXT ACTION' : 'MY ASSIGNMENT',
        };
    }

    /**
     * @param array<string,mixed> $classicSurface
     * @return array<string,mixed>
     */
    private function node(
        string $id,
        string $type,
        string $eyebrow,
        string $label,
        string $subtitle,
        string $action,
        string $attentionRole,
        string $navigationKind,
        array $classicSurface,
        ?int $entityId = null,
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'entity_id' => $entityId,
            'eyebrow' => $eyebrow,
            'label' => $label,
            'subtitle' => $subtitle,
            'available_action' => $action,
            'classic_surface' => $classicSurface,
            'attention_role' => $attentionRole,
            'navigation_kind' => $navigationKind,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function edge(string $source, string $target, string $relation, string $attentionRole): array
    {
        return [
            'source' => $source,
            'target' => $target,
            'relation' => $relation,
            'secondary' => false,
            'attention_role' => $attentionRole,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $actions
     * @param array<int,string> $meta
     * @return array<string,mixed>
     */
    private function surface(
        string $kind,
        string $title,
        string $summary,
        array $actions,
        array $meta = [],
    ): array {
        return [
            'kind' => $kind,
            'title' => $title,
            'summary' => $summary,
            'actions' => $actions,
            'meta' => $meta,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function action(
        string $label,
        string $url,
        bool $primary = false,
        ?string $navigationKind = null,
        bool $external = false,
    ): array {
        return array_filter([
            'label' => $label,
            'url' => $url,
            'primary' => $primary,
            'navigation_kind' => $navigationKind,
            'external' => $external ?: null,
        ], fn ($value) => $value !== null);
    }
}
