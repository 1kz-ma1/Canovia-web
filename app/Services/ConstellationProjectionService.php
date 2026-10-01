<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Support\Collection;

final class ConstellationProjectionService
{
    /**
     * Convert one Plan Roadmap into a compact constellation.
     *
     * Main Stars are presentation-only Task Groups. They intentionally do not
     * mirror Task 1:1; the projection compresses large Plans so the overview
     * remains readable while preserving dependency direction and current
     * position.
     *
     * @param array<string,mixed> $roadmap
     * @param array<string,mixed> $spatial
     * @return array<string,mixed>
     */
    public function project(
        Plan $plan,
        array $roadmap,
        array $spatial,
        int $universeIndex = 0,
    ): array {
        $nodes = collect($roadmap['nodes'] ?? [])
            ->filter(fn ($node) => is_array($node) && (int) ($node['task_id'] ?? 0) > 0)
            ->reject(fn (array $node) => ($node['status'] ?? null) === 'cancelled')
            ->values();

        $spatialNodes = collect($spatial['nodes'] ?? [])
            ->filter(fn ($node) => is_array($node) && (int) ($node['task_id'] ?? 0) > 0)
            ->keyBy(fn (array $node) => (int) $node['task_id']);

        $taskCount = $nodes->count();
        $doneCount = $nodes->where('status', 'done')->count();
        $estimatedMinutes = (int) $nodes->sum(
            fn (array $node) => max(0, (int) ($node['estimated_minutes'] ?? 0)),
        );
        $completionPercent = $taskCount > 0
            ? (int) round(($doneCount / $taskCount) * 100)
            : 0;

        if ($nodes->isEmpty()) {
            return [
                'schema_version' => 1,
                'plan_id' => (int) $plan->id,
                'title' => (string) $plan->title,
                'accent' => $plan->accentKey(),
                'pattern' => 'empty',
                'richness_tier' => 1,
                'completion_percent' => 0,
                'completed_count' => 0,
                'task_count' => 0,
                'estimated_minutes' => 0,
                'status' => 'empty',
                'status_label' => '未構成',
                'current_star_id' => null,
                'stars' => [],
                'edges' => [],
                'orbit' => $this->orbitPosition($universeIndex),
                'projection_key' => hash('sha256', 'constellation-empty-'.$plan->id),
            ];
        }

        $targetStarCount = $this->targetStarCount($taskCount, $estimatedMinutes);
        $groups = $this->partitionByStableCost($nodes, $targetStarCount);

        $taskToStar = [];
        $stars = collect($groups)
            ->map(function (Collection $group, int $index) use (
                $plan,
                $spatialNodes,
                &$taskToStar,
            ) {
                $starId = 'plan:'.$plan->id.':star:'.($index + 1);
                $taskIds = $group
                    ->pluck('task_id')
                    ->map(fn ($id) => (int) $id)
                    ->values();

                foreach ($taskIds as $taskId) {
                    $taskToStar[$taskId] = $starId;
                }

                $done = $group->where('status', 'done')->count();
                $count = $group->count();
                $estimated = (int) $group->sum(
                    fn (array $node) => max(0, (int) ($node['estimated_minutes'] ?? 0)),
                );
                $remaining = (int) $group->sum(
                    fn (array $node) => max(0, (int) ($node['remaining_minutes'] ?? 0)),
                );

                $spatialMembers = $taskIds
                    ->map(fn (int $taskId) => $spatialNodes->get($taskId))
                    ->filter(fn ($node) => is_array($node))
                    ->values();

                $isCurrent = $group->contains(
                    fn (array $node) => (bool) ($node['is_current'] ?? false),
                ) || $spatialMembers->contains(
                    fn (array $node) => ($node['visual_state'] ?? null) === 'current',
                );
                $blockedCount = $spatialMembers
                    ->where('visual_state', 'blocked')
                    ->count();
                $depth = (int) ($spatialMembers
                    ->pluck('dependency_depth')
                    ->filter(fn ($depth) => $depth !== null)
                    ->min() ?? $index);

                $status = match (true) {
                    $count > 0 && $done === $count => 'complete',
                    $isCurrent => 'current',
                    $blockedCount > 0 && $done < $count => 'blocked',
                    $done > 0 || $group->contains(fn (array $node) => ($node['status'] ?? null) === 'doing') => 'active',
                    default => 'future',
                };

                $completion = $count > 0
                    ? (int) round(($done / $count) * 100)
                    : 0;

                return [
                    'id' => $starId,
                    'index' => $index + 1,
                    'label' => '星域 '.($index + 1),
                    'task_ids' => $taskIds->all(),
                    'task_count' => $count,
                    'completed_count' => $done,
                    'completion_percent' => $completion,
                    'estimated_minutes' => $estimated,
                    'remaining_minutes' => $remaining,
                    'dependency_depth' => $depth,
                    'blocked_count' => $blockedCount,
                    'is_current' => $isCurrent,
                    'is_complete' => $count > 0 && $done === $count,
                    'status' => $status,
                    'satellite_count' => $this->satelliteCount($count, $estimated),
                    'tasks' => $group
                        ->map(fn (array $node) => [
                            'task_id' => (int) $node['task_id'],
                            'title' => (string) ($node['title'] ?? 'Task'),
                            'status' => (string) ($node['status'] ?? 'todo'),
                            'status_label' => (string) ($node['status_label'] ?? 'Task'),
                            'progress_percent' => (int) ($node['progress_percent'] ?? 0),
                            'remaining_minutes' => (int) ($node['remaining_minutes'] ?? 0),
                            'is_current' => (bool) ($node['is_current'] ?? false),
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values();

        $edges = $this->groupEdges(
            $stars,
            $spatial,
            $taskToStar,
        );

        $stars = $this->positionStars(
            $stars,
            collect($edges),
            (int) $plan->id,
        );

        $currentStar = $stars->firstWhere('is_current', true)
            ?? $stars->first(fn (array $star) => ! $star['is_complete']);

        if ($currentStar && ! $stars->contains(fn (array $star) => (bool) $star['is_current'])) {
            $stars = $stars
                ->map(function (array $star) use ($currentStar) {
                    if ($star['id'] === $currentStar['id']) {
                        $star['is_current'] = true;
                        if ($star['status'] === 'future') {
                            $star['status'] = 'current';
                        }
                    }

                    return $star;
                })
                ->values();
            $currentStar = $stars->firstWhere('id', $currentStar['id']);
        }

        $pattern = $this->pattern(collect($edges));
        $richnessTier = $this->richnessTier(
            $taskCount,
            $estimatedMinutes,
            count($edges),
        );

        $status = match (true) {
            $taskCount > 0 && $doneCount === $taskCount => 'complete',
            $stars->contains(fn (array $star) => $star['status'] === 'blocked') => 'attention',
            $doneCount > 0 || $stars->contains(fn (array $star) => $star['status'] === 'current') => 'active',
            default => 'not_started',
        };

        $projection = [
            'schema_version' => 1,
            'plan_id' => (int) $plan->id,
            'title' => (string) $plan->title,
            'accent' => $plan->accentKey(),
            'pattern' => $pattern,
            'richness_tier' => $richnessTier,
            'completion_percent' => $completionPercent,
            'completed_count' => $doneCount,
            'task_count' => $taskCount,
            'estimated_minutes' => $estimatedMinutes,
            'status' => $status,
            'status_label' => match ($status) {
                'complete' => '完成',
                'attention' => '前提待ちあり',
                'active' => '進行中',
                default => '未着手',
            },
            'current_star_id' => $currentStar['id'] ?? null,
            'stars' => $stars->all(),
            'edges' => $edges,
            'orbit' => $this->orbitPosition($universeIndex),
        ];

        $projection['projection_key'] = hash(
            'sha256',
            (string) json_encode(
                collect($projection)->except(['orbit'])->all(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        );

        return $projection;
    }

    private function targetStarCount(int $taskCount, int $estimatedMinutes): int
    {
        if ($taskCount <= 4) {
            return max(1, $taskCount);
        }

        $base = match (true) {
            $taskCount <= 10 => 4,
            $taskCount <= 18 => 5,
            $taskCount <= 30 => 6,
            $taskCount <= 50 => 7,
            default => 8,
        };

        $costBonus = match (true) {
            $estimatedMinutes >= 4800 => 2,
            $estimatedMinutes >= 2400 => 1,
            default => 0,
        };

        return min(10, $taskCount, $base + $costBonus);
    }

    /**
     * @param Collection<int,array<string,mixed>> $nodes
     * @return array<int,Collection<int,array<string,mixed>>>
     */
    private function partitionByStableCost(Collection $nodes, int $targetStarCount): array
    {
        if ($targetStarCount <= 1) {
            return [$nodes->values()];
        }

        $weights = $nodes
            ->map(fn (array $node) => max(30, (int) ($node['estimated_minutes'] ?? 0)));
        $targetWeight = max(1, (int) ceil($weights->sum() / $targetStarCount));

        $groups = [];
        $current = collect();
        $currentWeight = 0;
        $nodeCount = $nodes->count();

        foreach ($nodes as $index => $node) {
            $current->push($node);
            $currentWeight += max(30, (int) ($node['estimated_minutes'] ?? 0));

            $remainingTasks = $nodeCount - ($index + 1);
            $remainingGroups = $targetStarCount - (count($groups) + 1);
            $canClose = $remainingGroups > 0
                && $remainingTasks >= $remainingGroups
                && $currentWeight >= $targetWeight;

            if ($canClose) {
                $groups[] = $current->values();
                $current = collect();
                $currentWeight = 0;
            }
        }

        if ($current->isNotEmpty()) {
            $groups[] = $current->values();
        }

        while (count($groups) < $targetStarCount) {
            $largestIndex = collect($groups)
                ->map(fn (Collection $group) => $group->count())
                ->sortDesc()
                ->keys()
                ->first();

            if ($largestIndex === null || $groups[$largestIndex]->count() <= 1) {
                break;
            }

            $largest = $groups[$largestIndex]->values();
            $splitAt = (int) ceil($largest->count() / 2);
            $groups[$largestIndex] = $largest->take($splitAt)->values();
            array_splice(
                $groups,
                $largestIndex + 1,
                0,
                [$largest->slice($splitAt)->values()],
            );
        }

        return array_values($groups);
    }

    /**
     * @param Collection<int,array<string,mixed>> $stars
     * @param array<string,mixed> $spatial
     * @param array<int,string> $taskToStar
     * @return array<int,array<string,mixed>>
     */
    private function groupEdges(
        Collection $stars,
        array $spatial,
        array $taskToStar,
    ): array {
        $pairs = [];

        foreach (collect($spatial['edges'] ?? []) as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $sourceTaskId = $this->taskIdFromNodeId((string) ($edge['source'] ?? ''));
            $targetTaskId = $this->taskIdFromNodeId((string) ($edge['target'] ?? ''));
            $sourceStar = $taskToStar[$sourceTaskId] ?? null;
            $targetStar = $taskToStar[$targetTaskId] ?? null;

            if (! $sourceStar || ! $targetStar || $sourceStar === $targetStar) {
                continue;
            }

            $key = $sourceStar.'>'.$targetStar;
            $pairs[$key] = [
                'source' => $sourceStar,
                'target' => $targetStar,
                'relation' => 'dependency',
            ];
        }

        $starIds = $stars->pluck('id')->values();
        for ($index = 0; $index < $starIds->count() - 1; $index++) {
            $source = (string) $starIds[$index];
            $target = (string) $starIds[$index + 1];
            $key = $source.'>'.$target;
            $reverse = $target.'>'.$source;

            if (! isset($pairs[$key]) && ! isset($pairs[$reverse])) {
                $pairs[$key] = [
                    'source' => $source,
                    'target' => $target,
                    'relation' => 'flow',
                ];
            }
        }

        $starsById = $stars->keyBy('id');

        return collect($pairs)
            ->values()
            ->map(function (array $edge) use ($starsById) {
                $source = $starsById->get($edge['source']);
                $target = $starsById->get($edge['target']);

                return [
                    ...$edge,
                    'is_lit' => (bool) data_get($source, 'is_complete', false)
                        && (bool) data_get($target, 'is_complete', false),
                ];
            })
            ->all();
    }

    /**
     * @param Collection<int,array<string,mixed>> $stars
     * @param Collection<int,array<string,mixed>> $edges
     * @return Collection<int,array<string,mixed>>
     */
    private function positionStars(
        Collection $stars,
        Collection $edges,
        int $planId,
    ): Collection {
        $depths = $stars
            ->pluck('dependency_depth')
            ->map(fn ($depth) => (int) $depth)
            ->unique()
            ->sort()
            ->values();

        $depthIndex = $depths
            ->mapWithKeys(fn (int $depth, int $index) => [$depth => $index]);

        $maxDepthIndex = max(0, $depths->count() - 1);

        return $stars
            ->groupBy('dependency_depth')
            ->flatMap(function (Collection $depthStars, $depth) use (
                $depthIndex,
                $maxDepthIndex,
                $planId,
            ) {
                $depthStars = $depthStars->values();
                $column = (int) ($depthIndex[(int) $depth] ?? 0);
                $x = $maxDepthIndex === 0
                    ? 50
                    : 12 + (($column / $maxDepthIndex) * 76);

                $count = $depthStars->count();

                return $depthStars->map(function (array $star, int $index) use (
                    $x,
                    $count,
                    $planId,
                ) {
                    $y = $count === 1
                        ? 50
                        : 20 + (($index / max(1, $count - 1)) * 60);

                    $hash = abs(crc32($planId.':'.$star['id']));
                    $jitterX = (($hash % 9) - 4) * 0.7;
                    $jitterY = (((int) floor($hash / 10) % 9) - 4) * 0.7;

                    return [
                        ...$star,
                        'x' => round(max(8, min(92, $x + $jitterX)), 2),
                        'y' => round(max(12, min(88, $y + $jitterY)), 2),
                    ];
                });
            })
            ->sortBy('index')
            ->values();
    }

    /**
     * @param Collection<int,array<string,mixed>> $edges
     */
    private function pattern(Collection $edges): string
    {
        $out = $edges
            ->groupBy('source')
            ->map(fn (Collection $items) => $items->count());
        $in = $edges
            ->groupBy('target')
            ->map(fn (Collection $items) => $items->count());

        $hasBranch = $out->contains(fn (int $count) => $count > 1);
        $hasConverge = $in->contains(fn (int $count) => $count > 1);

        return match (true) {
            $hasBranch && $hasConverge => 'web',
            $hasBranch => 'branch',
            $hasConverge => 'converge',
            default => 'chain',
        };
    }

    private function richnessTier(
        int $taskCount,
        int $estimatedMinutes,
        int $edgeCount,
    ): int {
        $taskFactor = min(24, $taskCount);
        $costFactor = min(24, (int) ceil($estimatedMinutes / 120));
        $structureFactor = min(8, $edgeCount);
        $combined = $taskFactor + $costFactor + $structureFactor;

        return match (true) {
            $combined >= 42 => 4,
            $combined >= 26 => 3,
            $combined >= 14 => 2,
            default => 1,
        };
    }

    private function satelliteCount(int $taskCount, int $estimatedMinutes): int
    {
        $count = 0;
        if ($taskCount >= 2 || $estimatedMinutes >= 180) {
            $count++;
        }
        if ($taskCount >= 4 || $estimatedMinutes >= 480) {
            $count++;
        }
        if ($taskCount >= 6 || $estimatedMinutes >= 900) {
            $count++;
        }

        return min(3, $count);
    }

    /**
     * Stable outer-space position. Existing Plan positions do not move when a
     * new Plan is appended to the user's collection.
     *
     * @return array{x:float,y:float,ring:int}
     */
    private function orbitPosition(int $index): array
    {
        $slots = [
            [22, 20], [50, 13], [78, 20], [86, 48],
            [76, 77], [50, 86], [24, 77], [14, 48],
            [34, 30], [66, 30], [67, 68], [33, 68],
        ];

        if (isset($slots[$index])) {
            return [
                'x' => (float) $slots[$index][0],
                'y' => (float) $slots[$index][1],
                'ring' => $index < 8 ? 1 : 2,
            ];
        }

        $extra = $index - count($slots);
        $angle = deg2rad(($extra * 137.508) - 90);
        $radiusX = 38;
        $radiusY = 40;

        return [
            'x' => round(50 + (cos($angle) * $radiusX), 2),
            'y' => round(50 + (sin($angle) * $radiusY), 2),
            'ring' => 3,
        ];
    }

    private function taskIdFromNodeId(string $nodeId): int
    {
        if (preg_match('/^task:(\d+)$/', $nodeId, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}
