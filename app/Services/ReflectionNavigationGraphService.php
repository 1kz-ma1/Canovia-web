<?php

namespace App\Services;

use App\Enums\MapLevel;
use Illuminate\Support\Collection;

final class ReflectionNavigationGraphService
{
    /**
     * @param array<string,mixed> $context
     * @return array{nodes:Collection<int,array<string,mixed>>,edges:Collection<int,array<string,mixed>>,center_node_id:string|null}
     */
    public function build(MapLevel $level, array $context): array
    {
        return match ($level) {
            MapLevel::Domain => $this->lensGraph($context),
            MapLevel::Plan => $this->itemGraph($context),
            default => [
                'nodes' => collect(),
                'edges' => collect(),
                'center_node_id' => null,
            ],
        };
    }

    /**
     * L1: reflection is organized by what the user wants to look back on,
     * not by Plan category.
     *
     * @param array<string,mixed> $context
     * @return array{nodes:Collection<int,array<string,mixed>>,edges:Collection<int,array<string,mixed>>,center_node_id:string}
     */
    private function lensGraph(array $context): array
    {
        $centerId = 'reflection:hub';
        $timelineUrl = (string) ($context['timeline_url'] ?? route('timeline.index'));
        $achievementUrl = (string) ($context['achievements_url'] ?? route('achievements.index'));

        $nodes = collect([
            $this->node(
                id: $centerId,
                type: 'intent_context',
                eyebrow: 'L1 · REFLECTION',
                label: '振り返り',
                subtitle: '過去の事実を、見返したい意味から辿る',
                action: route('map.index'),
                attentionRole: 'hierarchy-parent',
                navigationKind: 'zoom-out',
                classicSurface: $this->surface(
                    'Reflection Hub',
                    '振り返り',
                    'Plan分類ではなく、最近の実績・Evidence・完了Task・明示的な振り返り記録というLensから過去の事実を辿ります。',
                    [
                        $this->action('L0へ戻る', route('map.index'), true, 'zoom-out'),
                        $this->action('Timelineを開く', $timelineUrl),
                        $this->action('達成した計画を見る', $achievementUrl),
                    ],
                    ['Evidence based', 'No inferred history'],
                ),
            ),
        ]);

        $edges = collect();

        foreach (collect($context['contexts'] ?? []) as $definition) {
            $key = (string) ($definition['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $url = route('map.index', [
                'level' => MapLevel::Plan->value,
                'intent' => 'reflection',
                'reflection_context' => $key,
            ]);
            $nodeId = 'reflection:context:'.$key;

            $nodes->push($this->node(
                id: $nodeId,
                type: 'intent_context',
                eyebrow: (string) ($definition['eyebrow'] ?? 'REFLECTION'),
                label: (string) ($definition['label'] ?? '振り返り'),
                subtitle: (int) ($definition['count'] ?? 0).' records',
                action: $url,
                attentionRole: 'hierarchy-child',
                navigationKind: 'zoom-in',
                classicSurface: $this->surface(
                    'Reflection Lens',
                    (string) ($definition['label'] ?? '振り返り'),
                    (string) ($definition['summary'] ?? ''),
                    [
                        $this->action('このLensへ入る', $url, true, 'zoom-in'),
                        $this->action('Timelineを開く', $timelineUrl),
                    ],
                    [
                        (int) ($definition['count'] ?? 0).' records',
                        'Canonical history only',
                    ],
                ),
            ));

            $edges->push($this->edge(
                $centerId,
                $nodeId,
                'groups_reflection_lens',
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
     * L2: concrete records from the selected reflection lens.
     *
     * @param array<string,mixed> $context
     * @return array{nodes:Collection<int,array<string,mixed>>,edges:Collection<int,array<string,mixed>>,center_node_id:string}
     */
    private function itemGraph(array $context): array
    {
        $selected = is_array($context['selected_context'] ?? null)
            ? $context['selected_context']
            : [];
        $key = (string) ($selected['key'] ?? 'recent');
        $label = (string) ($selected['label'] ?? '最近の実績');
        $centerId = 'reflection:context:'.$key;
        $parentUrl = route('map.index', [
            'level' => MapLevel::Domain->value,
            'intent' => 'reflection',
        ]);
        $timelineUrl = (string) ($context['timeline_url'] ?? route('timeline.index'));
        $achievementUrl = (string) ($context['achievements_url'] ?? route('achievements.index'));

        $nodes = collect([
            $this->node(
                id: $centerId,
                type: 'intent_context',
                eyebrow: 'L2 · REFLECTION LENS',
                label: $label,
                subtitle: '記録を選んで元のContextへ戻る',
                action: $parentUrl,
                attentionRole: 'hierarchy-parent',
                navigationKind: 'zoom-out',
                classicSurface: $this->surface(
                    'Reflection Lens',
                    $label,
                    (string) ($selected['summary'] ?? ''),
                    [
                        $this->action('振り返りLensへ戻る', $parentUrl, true, 'zoom-out'),
                        $this->action('Timelineを開く', $timelineUrl),
                    ],
                    [
                        (int) ($selected['count'] ?? 0).' records',
                        'L2',
                    ],
                ),
            ),
        ]);

        $edges = collect();

        foreach (collect($selected['items'] ?? []) as $item) {
            $rawId = (string) ($item['id'] ?? '');
            if ($rawId === '') {
                continue;
            }

            $itemId = 'reflection:item:'.str_replace(':', '-', $rawId);
            $kind = (string) ($item['kind'] ?? 'evidence');
            $type = $kind === 'completed_task' ? 'task' : 'evidence';
            $url = filled($item['url'] ?? null)
                ? (string) $item['url']
                : $timelineUrl;
            $entityId = $kind === 'completed_task' && ! empty($item['task_id'])
                ? (int) $item['task_id']
                : null;

            $nodes->push($this->node(
                id: $itemId,
                type: $type,
                entityId: $entityId,
                eyebrow: $this->itemEyebrow($kind),
                label: (string) ($item['label'] ?? '記録'),
                subtitle: (string) ($item['subtitle'] ?? ''),
                action: $url,
                attentionRole: 'hierarchy-child',
                navigationKind: 'direct',
                classicSurface: $this->surface(
                    'Reflection Record',
                    (string) ($item['label'] ?? '記録'),
                    (string) ($item['summary'] ?? ''),
                    [
                        $this->action('元のContextで確認', $url, true, 'direct'),
                        $this->action('Timelineを開く', $timelineUrl),
                    ],
                    array_values(array_filter([
                        (string) ($item['plan_title'] ?? ''),
                        (string) ($item['occurred_at'] ?? ''),
                        $this->kindLabel($kind),
                    ])),
                ),
            ));

            $edges->push($this->edge(
                $centerId,
                $itemId,
                'contains_reflection_record',
                'hierarchy-child',
            ));
        }

        if ($nodes->count() === 1) {
            $nodes->push($this->node(
                id: 'reflection:empty:timeline',
                type: 'evidence',
                eyebrow: 'NEXT OPTION',
                label: 'Timelineを確認',
                subtitle: 'このLensに記録がないため、全実績を時系列で確認',
                action: $timelineUrl,
                attentionRole: 'hierarchy-child',
                navigationKind: 'direct',
                classicSurface: $this->surface(
                    'Reflection Action',
                    'Timelineを確認',
                    'このLensに該当する記録はまだありません。既存Timelineから全体の実績を確認できます。',
                    [
                        $this->action('Timelineを開く', $timelineUrl, true, 'direct'),
                    ],
                    ['Empty-state action'],
                ),
            ));
            $nodes->push($this->node(
                id: 'reflection:empty:achievements',
                type: 'plan',
                eyebrow: 'NEXT OPTION',
                label: '達成した計画を見る',
                subtitle: '完了したPlanと到達までの流れを確認',
                action: $achievementUrl,
                attentionRole: 'hierarchy-child',
                navigationKind: 'direct',
                classicSurface: $this->surface(
                    'Reflection Action',
                    '達成した計画を見る',
                    '完了済みPlanがある場合はAchievement画面から到達までの経緯を振り返れます。',
                    [
                        $this->action('達成した計画を開く', $achievementUrl, true, 'direct'),
                    ],
                    ['Empty-state action'],
                ),
            ));

            $edges->push($this->edge(
                $centerId,
                'reflection:empty:timeline',
                'offers_empty_state_action',
                'hierarchy-child',
            ));
            $edges->push($this->edge(
                $centerId,
                'reflection:empty:achievements',
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

    private function itemEyebrow(string $kind): string
    {
        return match ($kind) {
            'work_log' => 'WORK LOG',
            'completed_task' => 'COMPLETED TASK',
            'reflection' => 'REFLECTION',
            default => 'EVIDENCE',
        };
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            'work_log' => 'WorkLog',
            'completed_task' => '完了Task',
            'reflection' => '振り返りEvidence',
            default => 'Evidence',
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
    ): array {
        return array_filter([
            'label' => $label,
            'url' => $url,
            'primary' => $primary,
            'navigation_kind' => $navigationKind,
        ], fn ($value) => $value !== null);
    }
}
