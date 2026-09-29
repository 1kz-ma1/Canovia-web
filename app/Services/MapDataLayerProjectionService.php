<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;
use Illuminate\Support\Collection;

final class MapDataLayerProjectionService
{
    public const SCHEMA_VERSION = 1;

    public const LAYERS = [
        'progress',
        'deadline',
        'status',
        'evidence',
        'dependency',
        'priority',
    ];

    /**
     * Decorate the existing Map graph with read-only presentation metadata.
     *
     * Values come directly from canonical Plan / Task / TaskEvidence /
     * task_dependencies data. No human-readable summary strings are parsed and
     * no readiness / blocked / completion state is inferred here.
     *
     * @param array<string,mixed> $projection
     * @return array<string,mixed>
     */
    public function decorate(array $projection): array
    {
        $nodes = collect($projection['nodes'] ?? []);

        $taskIds = $nodes
            ->filter(fn ($node) => is_array($node) && ($node['type'] ?? null) === 'task')
            ->pluck('entity_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $planIds = $nodes
            ->filter(fn ($node) => is_array($node) && in_array(($node['type'] ?? null), ['plan', 'satellite_plan'], true))
            ->pluck('entity_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $tasks = $taskIds->isEmpty()
            ? collect()
            : Task::query()
                ->withCount('evidences')
                ->with('prerequisites')
                ->whereIn('id', $taskIds->all())
                ->get()
                ->keyBy('id');

        $plans = $planIds->isEmpty()
            ? collect()
            : Plan::query()
                ->whereIn('id', $planIds->all())
                ->get()
                ->keyBy('id');

        $decoratedNodes = $nodes
            ->map(function ($node) use ($tasks, $plans) {
                if (! is_array($node)) {
                    return $node;
                }

                $overlay = $this->overlayForNode($node, $tasks, $plans);

                if ($overlay !== []) {
                    $node['overlay'] = $overlay;
                } else {
                    unset($node['overlay']);
                }

                return $node;
            })
            ->values();

        $available = collect(self::LAYERS)
            ->filter(fn (string $layer) => $decoratedNodes->contains(
                fn ($node) => is_array($node) && array_key_exists($layer, (array) ($node['overlay'] ?? []))
            ))
            ->values();

        $hasExplicitDependencyEdge = collect($projection['edges'] ?? [])->contains(
            fn ($edge) => is_array($edge)
                && in_array(($edge['relation'] ?? null), ['blocks_until_done', 'dependency'], true)
        );

        if ($hasExplicitDependencyEdge && ! $available->contains('dependency')) {
            $available->push('dependency');
        }

        $dataLayers = [
            'schema_version' => self::SCHEMA_VERSION,
            'available' => $available->all(),
            // Clean Map first: users explicitly opt into extra data.
            'default_enabled' => [],
        ];

        $projection['nodes'] = $decoratedNodes;
        $projection['data_layers'] = $dataLayers;

        // Overlay values are part of the rendered projection. Include their
        // read-only signature so Living Reevaluation notices an evidence,
        // progress, status, dependency, deadline or priority change even when
        // the underlying navigation topology did not change.
        $overlaySignature = $decoratedNodes
            ->map(fn ($node) => is_array($node)
                ? [
                    'id' => (string) ($node['id'] ?? ''),
                    'overlay' => (array) ($node['overlay'] ?? []),
                ]
                : null)
            ->filter()
            ->values()
            ->all();

        $projection['projection_key'] = hash(
            'sha256',
            (string) json_encode([
                'base' => (string) ($projection['projection_key'] ?? ''),
                'data_layers' => $dataLayers,
                'overlays' => $overlaySignature,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        return $projection;
    }

    /**
     * @param array<string,mixed> $node
     * @param Collection<int,Task> $tasks
     * @param Collection<int,Plan> $plans
     * @return array<string,mixed>
     */
    private function overlayForNode(array $node, Collection $tasks, Collection $plans): array
    {
        $type = (string) ($node['type'] ?? '');
        $entityId = (int) ($node['entity_id'] ?? 0);

        if ($entityId <= 0) {
            return [];
        }

        if ($type === 'task') {
            $task = $tasks->get($entityId);

            return $task instanceof Task ? $this->taskOverlay($task) : [];
        }

        if (in_array($type, ['plan', 'satellite_plan'], true)) {
            $plan = $plans->get($entityId);

            return $plan instanceof Plan ? $this->planOverlay($plan) : [];
        }

        return [];
    }

    /**
     * @return array<string,mixed>
     */
    private function taskOverlay(Task $task): array
    {
        $dependencyIds = $task->dependencyIds();

        return [
            'progress' => [
                'percent' => (int) $task->progress_percent,
            ],
            'status' => [
                'value' => (string) $task->status,
                'label' => $this->statusLabel((string) $task->status),
            ],
            'evidence' => [
                'count' => (int) ($task->evidences_count ?? 0),
            ],
            'dependency' => [
                'count' => count($dependencyIds),
                'task_ids' => $dependencyIds,
            ],
            'priority' => [
                'value' => (int) $task->priority,
                'label' => 'P'.(int) $task->priority,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function planOverlay(Plan $plan): array
    {
        $overlay = [
            'priority' => [
                'value' => (int) $plan->priority,
                'label' => 'P'.(int) $plan->priority,
            ],
        ];

        if ($plan->deadline) {
            $overlay['deadline'] = [
                'date' => $plan->deadline->format('Y-m-d'),
                'label' => $plan->deadline->format('m/d'),
            ];
        }

        return $overlay;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'todo' => '未着手',
            'doing' => '進行中',
            'done' => '完了',
            'cancelled' => '中止',
            default => $status,
        };
    }
}
