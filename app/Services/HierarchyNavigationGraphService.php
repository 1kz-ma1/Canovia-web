<?php

namespace App\Services;

use App\Enums\MapLevel;
use App\Models\Plan;
use Illuminate\Support\Collection;

final class HierarchyNavigationGraphService
{
    /**
     * @param array<string,mixed> $context
     * @return array{
     *     nodes:Collection<int,array<string,mixed>>,
     *     edges:Collection<int,array<string,mixed>>,
     *     center_node_id:string|null
     * }
     */
    public function build(MapLevel $level, array $context): array
    {
        $intent = (string) ($context['intent'] ?? 'plan');
        $planIntent = $intent === 'plan';
        $executionIntent = $intent === 'execution';

        return match ($level) {
            MapLevel::Domain => ($planIntent || $executionIntent)
                ? $this->planIndexGraph($context, $intent)
                : $this->domainGraph($context),
            MapLevel::Plan => $planIntent
                ? $this->planWorkspaceGraph($context)
                : $this->planGraph($context),
            default => [
                'nodes' => collect(),
                'edges' => collect(),
                'center_node_id' => null,
            ],
        };
    }

    /**
     * L1 Plan / Execution intents are plan-first. Categories stay canonical
     * Plan metadata but no longer consume a semantic zoom level.
     *
     * @param array<string,mixed> $context
     * @return array{nodes:Collection<int,array<string,mixed>>,edges:Collection<int,array<string,mixed>>,center_node_id:string}
     */
    private function planIndexGraph(array $context, string $intent = 'plan'): array
    {
        $executionIntent = $intent === 'execution';
        $centerId = 'hierarchy:intent:'.$intent;
        $plans = collect($context['plans'] ?? [])
            ->sort(function (Plan $left, Plan $right) {
                $priority = max(1, min(5, (int) $left->priority))
                    <=> max(1, min(5, (int) $right->priority));
                if ($priority !== 0) {
                    return $priority;
                }

                $deadline = ($left->deadline?->timestamp ?? PHP_INT_MAX)
                    <=> ($right->deadline?->timestamp ?? PHP_INT_MAX);
                if ($deadline !== 0) {
                    return $deadline;
                }

                return (int) $left->id <=> (int) $right->id;
            })
            ->values();

        $nodes = collect([
            $this->node(
                id: $centerId,
                type: 'intent_context',
                eyebrow: $executionIntent ? 'L1 · EXECUTION PLANS' : 'L1 · PLANS',
                label: $executionIntent ? '実行' : '計画',
                subtitle: $executionIntent
                    ? 'Planを直接選んでExecution Contextへ入る'
                    : 'Planを直接選んで詳細Workspaceへ入る',
                action: route('map.index'),
                attentionRole: 'hierarchy-parent',
                navigationKind: 'zoom-out',
                classicSurface: $this->surface(
                    $executionIntent ? 'Execution Plan Index' : 'Plan Index',
                    $executionIntent ? '実行' : '計画',
                    $executionIntent
                        ? '実行したいPlanをカテゴリで分けず直接配置しています。Planを選ぶとそのExecution Contextへ入ります。'
                        : 'カテゴリを1階層として挟まず、アクセスできるPlanを直接配置しています。',
                    [
                        $this->action('L0へ戻る', route('map.index'), true, 'zoom-out'),
                        $executionIntent
                            ? $this->action('今日の実行導線を開く', route('navigation.index'))
                            : $this->action('新しいPlanを作る', route('plans.create')),
                    ],
                    [$plans->count().' Plan'],
                ),
            ),
        ]);

        $edges = collect();

        /** @var Plan $plan */
        foreach ($plans as $plan) {
            $nodeId = 'plan:'.$plan->id;
            $url = $executionIntent
                ? route('map.index', [
                    'level' => MapLevel::Execution->value,
                    'intent' => 'execution',
                    'plan' => $plan->id,
                ])
                : route('map.index', [
                    'level' => MapLevel::Plan->value,
                    'intent' => 'plan',
                    'plan' => $plan->id,
                ]);
            $activeTasks = $plan->tasks
                ->filter(fn ($task) => ! in_array($task->status, ['done', 'cancelled'], true)
                    && (int) $task->progress_percent < 100)
                ->count();

            $nodes->push($this->node(
                id: $nodeId,
                type: 'plan',
                entityId: (int) $plan->id,
                eyebrow: $plan->displayIcon().' PLAN',
                label: (string) $plan->title,
                subtitle: implode(' · ', array_values(array_filter([
                    (string) ($plan->category ?: '未分類'),
                    $activeTasks.' active',
                    $plan->deadline ? $plan->deadline->format('m/d') : null,
                ]))),
                action: $url,
                attentionRole: 'hierarchy-child',
                navigationKind: 'zoom-in',
                classicSurface: $this->surface(
                    'Plan',
                    (string) $plan->title,
                    filled($plan->description)
                        ? mb_substr((string) $plan->description, 0, 260)
                        : ($executionIntent
                            ? 'このPlanのExecution Contextへ入ります。'
                            : 'このPlanの詳細Workspaceへ入ります。'),
                    [
                        $this->action(
                            $executionIntent ? 'Executionへ入る' : 'Plan Workspaceへ入る',
                            $url,
                            true,
                            'zoom-in',
                        ),
                        $this->action('Classic Planを開く', route('plans.show', $plan)),
                    ],
                    array_values(array_filter([
                        (string) ($plan->category ?: '未分類'),
                        $activeTasks.' active tasks',
                        (bool) $plan->is_collaborative ? 'Shared Plan' : 'Personal Plan',
                    ])),
                ),
            ));

            $edges->push($this->edge($centerId, $nodeId, 'contains_plan', 'hierarchy-child'));
        }

        return [
            'nodes' => $nodes->values(),
            'edges' => $edges->values(),
            'center_node_id' => $centerId,
        ];
    }

    /**
     * L2 Plan intent is a detail workspace. Rich Plan information is projected
     * as a palette, so the graph keeps only the selected Plan as spatial context.
     *
     * @param array<string,mixed> $context
     * @return array{nodes:Collection<int,array<string,mixed>>,edges:Collection<int,array<string,mixed>>,center_node_id:string|null}
     */
    private function planWorkspaceGraph(array $context): array
    {
        $plan = $context['selected_plan'] ?? null;
        if (! $plan instanceof Plan) {
            return [
                'nodes' => collect(),
                'edges' => collect(),
                'center_node_id' => null,
            ];
        }

        $parentUrl = route('map.index', [
            'level' => MapLevel::Domain->value,
            'intent' => 'plan',
        ]);
        $centerId = 'plan:'.$plan->id;

        $node = $this->node(
            id: $centerId,
            type: 'plan',
            entityId: (int) $plan->id,
            eyebrow: 'L2 · PLAN WORKSPACE',
            label: (string) $plan->title,
            subtitle: '進捗・Roadmap・Plan情報をPaletteで確認',
            action: $parentUrl,
            attentionRole: 'hierarchy-parent',
            navigationKind: 'zoom-out',
            classicSurface: $this->surface(
                'Plan Workspace',
                (string) $plan->title,
                'Planの詳細情報はNodeを増やさず、Classicで使っているカードをWorkspace Paletteとして表示します。',
                [
                    $this->action('Plan一覧へ戻る', $parentUrl, true, 'zoom-out'),
                    $this->action('Classic Planを開く', route('plans.show', $plan)),
                ],
                array_values(array_filter([
                    (string) ($plan->category ?: '未分類'),
                    'Plan Workspace',
                ])),
            ),
        );

        return [
            'nodes' => collect([$node]),
            'edges' => collect(),
            'center_node_id' => $centerId,
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array{nodes:Collection<int,array<string,mixed>>,edges:Collection<int,array<string,mixed>>,center_node_id:string}
     */
    private function domainGraph(array $context): array
    {
        $intent = (string) ($context['intent'] ?? 'plan');
        $intentLabel = (string) ($context['intent_label'] ?? '計画');
        $centerId = 'hierarchy:intent:'.$intent;

        $nodes = collect([
            $this->node(
                id: $centerId,
                type: 'intent_context',
                eyebrow: 'L1 · INTENT',
                label: $intentLabel,
                subtitle: '領域を選んでCanoviaの内側へ進む',
                action: route('map.index'),
                attentionRole: 'hierarchy-parent',
                navigationKind: 'zoom-out',
                classicSurface: $this->surface(
                    'Intent Context',
                    $intentLabel,
                    'このIntentに属するPlan領域を見渡しています。Nodeの存在はおすすめ結果ではなく、現在アクセスできるCanoviaの構造から投影されます。',
                    [
                        $this->action('L0へ戻る', route('map.index'), true, 'zoom-out'),
                    ],
                    ['L1', 'Domain / Goal'],
                ),
            ),
        ]);

        $edges = collect();

        foreach (collect($context['domains'] ?? []) as $domain) {
            $key = (string) ($domain['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $nodeId = 'domain:'.$key;
            $url = route('map.index', [
                'level' => MapLevel::Plan->value,
                'intent' => $intent,
                'domain' => $key,
            ]);

            $planCount = (int) ($domain['plan_count'] ?? 0);
            $activeTaskCount = (int) ($domain['active_task_count'] ?? 0);
            $collaborativeCount = (int) ($domain['collaborative_count'] ?? 0);

            $nodes->push($this->node(
                id: $nodeId,
                type: 'domain',
                eyebrow: 'DOMAIN',
                label: (string) ($domain['label'] ?? '未分類'),
                subtitle: $planCount.' Plan · active '.$activeTaskCount,
                action: $url,
                attentionRole: 'hierarchy-child',
                navigationKind: 'zoom-in',
                classicSurface: $this->surface(
                    'Domain',
                    (string) ($domain['label'] ?? '未分類'),
                    'この領域には'.$planCount.'件のPlanがあります。'.($collaborativeCount > 0 ? '共同Plan '.$collaborativeCount.'件を含みます。' : ''),
                    [
                        $this->action('この領域へ入る', $url, true, 'zoom-in'),
                        $this->action('計画一覧で見る', route('my_plans.index')),
                    ],
                    [
                        $planCount.' Plan',
                        $activeTaskCount.' active tasks',
                        $collaborativeCount.' shared',
                    ],
                ),
            ));

            $edges->push($this->edge($centerId, $nodeId, 'contains_domain', 'hierarchy-child'));
        }

        return [
            'nodes' => $nodes->values(),
            'edges' => $edges->values(),
            'center_node_id' => $centerId,
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array{nodes:Collection<int,array<string,mixed>>,edges:Collection<int,array<string,mixed>>,center_node_id:string|null}
     */
    private function planGraph(array $context): array
    {
        $intent = (string) ($context['intent'] ?? 'plan');
        $domain = $context['selected_domain'] ?? null;
        if (! is_array($domain)) {
            return [
                'nodes' => collect(),
                'edges' => collect(),
                'center_node_id' => null,
            ];
        }

        $domainKey = (string) ($domain['key'] ?? '');
        $centerId = 'domain:'.$domainKey;
        $parentUrl = route('map.index', [
            'level' => MapLevel::Domain->value,
            'intent' => $intent,
        ]);

        $nodes = collect([
            $this->node(
                id: $centerId,
                type: 'domain',
                eyebrow: 'L2 · DOMAIN',
                label: (string) ($domain['label'] ?? '未分類'),
                subtitle: 'Planを選んでExecution Contextへ進む',
                action: $parentUrl,
                attentionRole: 'hierarchy-parent',
                navigationKind: 'zoom-out',
                classicSurface: $this->surface(
                    'Domain',
                    (string) ($domain['label'] ?? '未分類'),
                    'この領域のPlanを見渡しています。Planを選ぶと、そのPlanに属するExecution ContextへSemantic Zoomします。',
                    [
                        $this->action('領域一覧へ戻る', $parentUrl, true, 'zoom-out'),
                    ],
                    ['L2', (int) ($domain['plan_count'] ?? 0).' Plan'],
                ),
            ),
        ]);

        $edges = collect();

        /** @var Plan $plan */
        foreach (collect($domain['plans'] ?? []) as $plan) {
            $nodeId = 'plan:'.$plan->id;
            $zoomUrl = route('map.index', [
                'level' => MapLevel::Execution->value,
                'intent' => $intent,
                'domain' => $domainKey,
                'plan' => $plan->id,
            ]);

            $activeTasks = $plan->tasks
                ->filter(fn ($task) => ! in_array($task->status, ['done', 'cancelled'], true)
                    && (int) $task->progress_percent < 100)
                ->count();

            $nodes->push($this->node(
                id: $nodeId,
                type: 'plan',
                entityId: (int) $plan->id,
                eyebrow: $plan->displayIcon().' PLAN',
                label: (string) $plan->title,
                subtitle: $activeTasks.' active tasks'.($plan->deadline ? ' · '.$plan->deadline->format('m/d') : ''),
                action: $zoomUrl,
                attentionRole: 'hierarchy-child',
                navigationKind: 'zoom-in',
                classicSurface: $this->surface(
                    'Plan',
                    (string) $plan->title,
                    filled($plan->description)
                        ? mb_substr((string) $plan->description, 0, 260)
                        : 'このPlanのExecution Contextへ進めます。',
                    [
                        $this->action('Executionへ入る', $zoomUrl, true, 'zoom-in'),
                        $this->action('Plan詳細を開く', route('plans.show', $plan)),
                    ],
                    [
                        (string) ($plan->category ?: '未分類'),
                        $activeTasks.' active tasks',
                        (bool) $plan->is_collaborative ? 'Shared Plan' : 'Personal Plan',
                    ],
                ),
            ));

            $edges->push($this->edge($centerId, $nodeId, 'contains_plan', 'hierarchy-child'));
        }

        return [
            'nodes' => $nodes->values(),
            'edges' => $edges->values(),
            'center_node_id' => $centerId,
        ];
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
    private function action(string $label, string $url, bool $primary = false, ?string $navigationKind = null): array
    {
        return array_filter([
            'label' => $label,
            'url' => $url,
            'primary' => $primary,
            'navigation_kind' => $navigationKind,
        ], fn ($value) => $value !== null);
    }
}
