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
            MapLevel::Domain => $this->projectGraph($context),
            MapLevel::Plan => $this->projectWorkspaceGraph($context),
            default => [
                'nodes' => collect(),
                'edges' => collect(),
                'center_node_id' => null,
            ],
        };
    }

    /**
     * L1 is project-first: show Shared Plans directly, not purpose buckets.
     *
     * @param array<string,mixed> $context
     * @return array{nodes:Collection<int,array<string,mixed>>,edges:Collection<int,array<string,mixed>>,center_node_id:string}
     */
    private function projectGraph(array $context): array
    {
        $centerId = 'collaboration:hub';
        $projects = collect($context['projects'] ?? []);
        $reviewFilter = (string) data_get($context, 'selected_context.key') === 'review';
        $createUrl = route('plans.create.manual', ['collaborative' => 1]);

        $nodes = collect([
            $this->node(
                id: $centerId,
                type: 'collaboration_hub',
                eyebrow: 'L1 · SHARED PROJECTS',
                label: $reviewFilter ? 'レビュー待ちの共同計画' : '共同計画',
                subtitle: $reviewFilter
                    ? 'レビュー待ちがあるProjectを優先表示'
                    : '共同Planを選んでProject Workspaceへ入る',
                action: route('map.index'),
                attentionRole: 'hierarchy-parent',
                navigationKind: 'zoom-out',
                classicSurface: $this->surface(
                    'Collaboration Projects',
                    $reviewFilter ? 'レビュー待ちの共同計画' : '共同計画',
                    '共同作業はPurposeからではなく、まずShared Planを選びます。Projectへ入った後は、制作物・メンバー・更新情報をWorkspace Paletteで確認します。',
                    [
                        $this->action('L0へ戻る', route('map.index'), true, 'zoom-out'),
                        $this->action('共同計画を作る', $createUrl),
                    ],
                    [
                        $projects->count().' Shared Plan',
                        (int) ($context['recent_activity_count'] ?? 0).' recent changes',
                    ],
                ),
            ),
        ]);

        $edges = collect();

        foreach ($projects as $project) {
            $planId = (int) ($project['id'] ?? 0);
            if ($planId <= 0) {
                continue;
            }

            $url = route('map.index', [
                'level' => MapLevel::Plan->value,
                'intent' => 'collaboration',
                'plan' => $planId,
            ]);
            $reviewCount = (int) ($project['review_count'] ?? 0);
            $waitingCount = (int) ($project['waiting_count'] ?? 0);
            $memberCount = (int) ($project['member_count'] ?? 1);
            $roleLabel = $this->roleLabel((string) ($project['role'] ?? ''));

            $nodes->push($this->node(
                id: 'collaboration:project:'.$planId,
                type: 'plan',
                entityId: $planId,
                eyebrow: $reviewCount > 0 ? 'SHARED PROJECT · REVIEW' : 'SHARED PROJECT',
                label: (string) ($project['title'] ?? '共同Plan'),
                subtitle: implode(' · ', array_values(array_filter([
                    $roleLabel,
                    $memberCount.'人',
                    $reviewCount > 0 ? 'レビュー '.$reviewCount.'件' : null,
                    $waitingCount > 0 ? '相手待ち '.$waitingCount.'件' : null,
                ]))),
                action: $url,
                attentionRole: 'hierarchy-child',
                navigationKind: 'zoom-in',
                classicSurface: $this->surface(
                    'Shared Project',
                    (string) ($project['title'] ?? '共同Plan'),
                    'この共同PlanのProject Workspaceを開きます。Map上では制作物・メンバー・最新情報をカードとして確認できます。',
                    [
                        $this->action('Project Workspaceへ入る', $url, true, 'zoom-in'),
                        $this->action('Classic Planを開く', route('plans.show', $planId)),
                    ],
                    array_values(array_filter([
                        $roleLabel,
                        (string) ($project['category'] ?? ''),
                        (int) ($project['artifact_count'] ?? 0).' artifacts',
                        $reviewCount > 0 ? $reviewCount.' review waiting' : null,
                    ])),
                ),
            ));

            $edges->push($this->edge(
                $centerId,
                'collaboration:project:'.$planId,
                'contains_shared_project',
                'hierarchy-child',
            ));
        }

        $nodes->push($this->node(
            id: 'collaboration:create-project',
            type: 'collaboration_item',
            eyebrow: 'NEW PROJECT',
            label: '共同計画を作る',
            subtitle: '新しいShared Planを追加',
            action: $createUrl,
            attentionRole: 'hierarchy-child',
            navigationKind: 'direct',
            classicSurface: $this->surface(
                'Collaboration Action',
                '共同計画を作る',
                '新しいPlanを共同計画プリセットで作成します。',
                [
                    $this->action('共同計画を作る', $createUrl, true, 'direct'),
                ],
                ['Create Shared Plan'],
            ),
        ));

        $edges->push($this->edge(
            $centerId,
            'collaboration:create-project',
            'offers_create_shared_project',
            'hierarchy-child',
        ));

        return [
            'nodes' => $nodes->values(),
            'edges' => $edges->values(),
            'center_node_id' => $centerId,
        ];
    }

    /**
     * L2 keeps only the selected Project as the semantic center.
     * Rich operational information is rendered by the Workspace Palette layer.
     *
     * @param array<string,mixed> $context
     * @return array{nodes:Collection<int,array<string,mixed>>,edges:Collection<int,array<string,mixed>>,center_node_id:string}
     */
    private function projectWorkspaceGraph(array $context): array
    {
        $plan = $context['selected_plan'] ?? null;

        if (! $plan instanceof Plan) {
            return $this->projectGraph($context);
        }

        $centerId = 'collaboration:project:'.$plan->id;
        $parentUrl = route('map.index', [
            'level' => MapLevel::Domain->value,
            'intent' => 'collaboration',
        ]);
        $project = collect($context['projects'] ?? [])
            ->first(fn (array $candidate) => (int) ($candidate['id'] ?? 0) === (int) $plan->id);
        $canManage = is_array($project) && (string) ($project['role'] ?? '') === 'owner';

        $actions = [
            $this->action('共同計画一覧へ戻る', $parentUrl, true, 'zoom-out'),
        ];

        if ($canManage) {
            $actions[] = $this->action('共同設定を開く', route('plans.collaboration.settings', $plan));
        }

        $actions[] = $this->action('Classic Planを開く', route('plans.show', $plan));

        $node = $this->node(
            id: $centerId,
            type: 'plan',
            entityId: (int) $plan->id,
            eyebrow: 'L2 · PROJECT WORKSPACE',
            label: (string) $plan->title,
            subtitle: '制作物・メンバー・最新情報をWorkspace Paletteで確認',
            action: $parentUrl,
            attentionRole: 'hierarchy-parent',
            navigationKind: 'zoom-out',
            classicSurface: $this->surface(
                'Project Workspace',
                (string) $plan->title,
                '共同Planの運用情報をMap上のPaletteとして表示しています。ノードを増やすのではなく、既存Classicのカード情報をWorkspaceとして再配置します。',
                $actions,
                ['Shared Project', 'Workspace Palette'],
            ),
        );

        return [
            'nodes' => collect([$node]),
            'edges' => collect(),
            'center_node_id' => $centerId,
        ];
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            'owner' => 'オーナー',
            'editor' => '編集者',
            'viewer' => '閲覧者',
            default => '共同',
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
