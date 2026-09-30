<?php

namespace App\Services;

use Illuminate\Support\Collection;

final class PersonalizedSatellitePromotionService
{
    private const MIN_PROMOTION_SCORE = 0.55;

    private const MAX_SATELLITES = 2;

    private const WEIGHTS = [
        'importance' => 0.35,
        'usage_frequency' => 0.25,
        'recency' => 0.20,
        'continuity' => 0.20,
    ];

    /**
     * Promote existing contexts into the L0 attention layer.
     *
     * Promotion is intentionally separate from semantic entity existence:
     * removing a satellite never removes its Plan/Tool from Canovia.
     *
     * @param Collection<int,array<string,mixed>> $candidates
     * @return array{
     *     nodes:Collection<int,array<string,mixed>>,
     *     edges:Collection<int,array<string,mixed>>,
     *     promoted:Collection<int,array<string,mixed>>
     * }
     */
    public function promote(
        Collection $candidates,
        int $limit = self::MAX_SATELLITES,
        array $pinnedNodeIds = [],
    ): array {
        $limit = max(0, min(self::MAX_SATELLITES, $limit));

        if ($limit === 0 || $candidates->isEmpty()) {
            return [
                'nodes' => collect(),
                'edges' => collect(),
                'promoted' => collect(),
            ];
        }

        $pinnedOrder = [];

        foreach ($pinnedNodeIds as $index => $rawNodeId) {
            $nodeId = trim((string) $rawNodeId);

            if ($nodeId === '' || isset($pinnedOrder[$nodeId])) {
                continue;
            }

            $pinnedOrder[$nodeId] = (int) $index;
        }

        $ranked = $candidates
            ->map(function (array $candidate) use ($pinnedOrder) {
                $score = $this->score((array) ($candidate['signals'] ?? []));
                $nodeId = (string) ($candidate['id'] ?? '');

                $candidate['promotion_score'] = $score;
                $candidate['is_pinned'] = array_key_exists($nodeId, $pinnedOrder);
                $candidate['pin_order'] = $pinnedOrder[$nodeId] ?? PHP_INT_MAX;

                return $candidate;
            })
            ->filter(fn (array $candidate) => (bool) ($candidate['is_pinned'] ?? false)
                || (float) ($candidate['promotion_score'] ?? 0) >= self::MIN_PROMOTION_SCORE)
            ->sort(function (array $left, array $right) {
                $leftPinned = (bool) ($left['is_pinned'] ?? false);
                $rightPinned = (bool) ($right['is_pinned'] ?? false);

                if ($leftPinned !== $rightPinned) {
                    return $leftPinned ? -1 : 1;
                }

                if ($leftPinned && $rightPinned) {
                    $pinOrder = (int) ($left['pin_order'] ?? PHP_INT_MAX)
                        <=> (int) ($right['pin_order'] ?? PHP_INT_MAX);

                    if ($pinOrder !== 0) {
                        return $pinOrder;
                    }
                }

                $score = (float) ($right['promotion_score'] ?? 0)
                    <=> (float) ($left['promotion_score'] ?? 0);

                if ($score !== 0) {
                    return $score;
                }

                $kind = $this->kindOrder((string) ($left['kind'] ?? ''))
                    <=> $this->kindOrder((string) ($right['kind'] ?? ''));

                if ($kind !== 0) {
                    return $kind;
                }

                return strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
            })
            ->values();

        $seenAnchors = [];
        $ranked = $ranked
            ->filter(function (array $candidate) use (&$seenAnchors) {
                $anchor = (string) ($candidate['anchor_node_id'] ?? '');
                $key = $anchor !== '' ? $anchor : 'kind:'.(string) ($candidate['kind'] ?? 'unknown');

                if (isset($seenAnchors[$key])) {
                    return false;
                }

                $seenAnchors[$key] = true;

                return true;
            })
            ->take($limit)
            ->values();

        $nodes = collect();
        $edges = collect();

        foreach ($ranked as $index => $candidate) {
            $fallbackSlot = $index + 1;
            $score = (float) ($candidate['promotion_score'] ?? 0);
            $isPinned = (bool) ($candidate['is_pinned'] ?? false);
            $visualScore = $isPinned
                ? max(self::MIN_PROMOTION_SCORE, $score)
                : $score;
            $anchor = (string) ($candidate['anchor_node_id'] ?? 'intent:plan');
            $positionRole = $this->positionRole($anchor, $fallbackSlot);
            $position = $this->position($anchor, $score, $fallbackSlot);
            $personalization = $this->personalization(
                $anchor,
                $score,
                (array) ($candidate['signals'] ?? []),
                $isPinned,
                (array) ($candidate['signal_reason_labels'] ?? []),
                (string) ($candidate['explanation_suffix'] ?? ''),
            );

            $nodes->push([
                'id' => (string) ($candidate['id'] ?? ''),
                'type' => (string) ($candidate['node_type'] ?? 'satellite_plan'),
                'entity_id' => $candidate['entity_id'] ?? null,
                'eyebrow' => (string) ($candidate['eyebrow'] ?? 'SATELLITE'),
                'label' => (string) ($candidate['label'] ?? ''),
                'subtitle' => (string) ($candidate['subtitle'] ?? ''),
                'importance' => round(0.58 + ($visualScore * 0.30), 4),
                'state' => 'satellite',
                'position_role' => $positionRole,
                'size_weight' => round(0.62 + ($visualScore * 0.20), 4),
                'position' => $position,
                'personalization' => $personalization,
                'available_action' => $candidate['available_action'] ?? null,
                'classic_surface' => $candidate['classic_surface'] ?? [],
                'navigation_kind' => $candidate['navigation_kind'] ?? 'satellite',
                'promotion_score' => round($score, 4),
                'promotion_signals' => $this->safeSignals((array) ($candidate['signals'] ?? [])),
            ]);

            $edges->push([
                'source' => $anchor,
                'target' => (string) ($candidate['id'] ?? ''),
                'relation' => 'personalized_shortcut',
                'strength' => round(0.30 + ($visualScore * 0.40), 4),
                'secondary' => true,
            ]);
        }

        return [
            'nodes' => $nodes->values(),
            'edges' => $edges->values(),
            'promoted' => $ranked->values(),
        ];
    }

    /**
     * @param array<string,mixed> $signals
     */
    public function score(array $signals): float
    {
        $score = 0.0;

        foreach (self::WEIGHTS as $key => $weight) {
            $value = max(0, min(1, (float) ($signals[$key] ?? 0)));
            $score += $value * $weight;
        }

        return round(max(0, min(1, $score)), 4);
    }

    private function positionRole(string $anchor, int $fallbackSlot): string
    {
        return match ($anchor) {
            'intent:plan' => 'satellite-1',
            'intent:execution' => 'satellite-2',
            'intent:reflection' => 'satellite-3',
            'intent:collaboration' => 'satellite-4',
            default => 'satellite-'.max(1, min(4, $fallbackSlot)),
        };
    }

    /**
     * Personalization can change visual distance, but never saves an absolute
     * position. Stronger evidence pulls the shortcut slightly toward the center.
     *
     * @return array{x:float|int,y:float|int}
     */
    private function position(string $anchor, float $score, int $fallbackSlot): array
    {
        $strength = max(0.0, min(1.0, ($score - self::MIN_PROMOTION_SCORE) / (1 - self::MIN_PROMOTION_SCORE)));
        $inset = round(6 * $strength, 2);

        return match ($anchor) {
            'intent:plan' => ['x' => 50, 'y' => round(12 + $inset, 2)],
            'intent:execution' => ['x' => round(88 - $inset, 2), 'y' => 50],
            'intent:reflection' => ['x' => 50, 'y' => round(88 - $inset, 2)],
            'intent:collaboration' => ['x' => round(12 + $inset, 2), 'y' => 50],
            default => match (max(1, min(4, $fallbackSlot))) {
                1 => ['x' => 50, 'y' => 12],
                2 => ['x' => 88, 'y' => 50],
                3 => ['x' => 50, 'y' => 88],
                default => ['x' => 12, 'y' => 50],
            },
        };
    }

    /**
     * @param array<string,mixed> $signals
     * @return array{
     *   mode:string,
     *   strength:string,
     *   anchor_node_id:string,
     *   reason_keys:array<int,string>,
     *   reason_labels:array<int,string>,
     *   explanation:string,
     *   pinned:bool
     * }
     */
    private function personalization(
        string $anchor,
        float $score,
        array $signals,
        bool $isPinned = false,
        array $signalReasonLabels = [],
        string $explanationSuffix = '',
    ): array {
        if ($isPinned) {
            return [
                'mode' => 'manual_pin',
                'strength' => 'pinned',
                'anchor_node_id' => $anchor,
                'reason_keys' => ['manual_pin'],
                'reason_labels' => ['固定中'],
                'explanation' => 'このPlanを固定しているため、利用状況に関係なく近道として表示しています。',
                'pinned' => true,
            ];
        }

        $safe = $this->safeSignals($signals);
        $weights = self::WEIGHTS;

        $defaultLabels = [
            'importance' => '優先度が高い',
            'usage_frequency' => '最近よく使っている',
            'recency' => '最近開いている',
            'continuity' => '継続して進めている',
        ];

        $labelsBySignal = collect($defaultLabels)
            ->map(function (string $default, string $key) use ($signalReasonLabels) {
                $custom = trim((string) ($signalReasonLabels[$key] ?? ''));

                return $custom !== ''
                    ? mb_substr($custom, 0, 80)
                    : $default;
            })
            ->all();

        $reasons = collect([
            'importance' => [
                'value' => $safe['importance'],
                'label' => $labelsBySignal['importance'],
            ],
            'usage_frequency' => [
                'value' => $safe['usage_frequency'],
                'label' => $labelsBySignal['usage_frequency'],
            ],
            'recency' => [
                'value' => $safe['recency'],
                'label' => $labelsBySignal['recency'],
            ],
            'continuity' => [
                'value' => $safe['continuity'],
                'label' => $labelsBySignal['continuity'],
            ],
        ])
            ->map(function (array $reason, string $key) use ($weights) {
                $reason['key'] = $key;
                $reason['contribution'] = (float) $reason['value'] * (float) ($weights[$key] ?? 0);

                return $reason;
            })
            ->filter(fn (array $reason) => (float) $reason['value'] >= 0.45)
            ->sortByDesc('contribution')
            ->take(2)
            ->values();

        if ($reasons->isEmpty()) {
            $reasons = collect([
                [
                    'key' => 'continuity',
                    'label' => '継続中のContext',
                    'value' => $safe['continuity'],
                    'contribution' => $safe['continuity'] * self::WEIGHTS['continuity'],
                ],
            ]);
        }

        $labels = $reasons->pluck('label')->values()->all();
        $lead = count($labels) === 1
            ? $labels[0]
            : implode('・', $labels);

        $suffix = trim($explanationSuffix) !== ''
            ? mb_substr(trim($explanationSuffix), 0, 180)
            : 'ため、よく使うContextへの近道として表示しています。';

        return [
            'mode' => 'behavioral_attention',
            'strength' => $score >= 0.78 ? 'strong' : 'active',
            'anchor_node_id' => $anchor,
            'reason_keys' => $reasons->pluck('key')->values()->all(),
            'reason_labels' => $labels,
            'explanation' => $lead.$suffix,
            'pinned' => false,
        ];
    }

    private function kindOrder(string $kind): int
    {
        return match ($kind) {
            'tool' => 0,
            'plan' => 1,
            'reflection' => 2,
            default => 9,
        };
    }

    /**
     * @param array<string,mixed> $signals
     * @return array{importance:float,usage_frequency:float,recency:float,continuity:float}
     */
    private function safeSignals(array $signals): array
    {
        return [
            'importance' => round(max(0, min(1, (float) ($signals['importance'] ?? 0))), 4),
            'usage_frequency' => round(max(0, min(1, (float) ($signals['usage_frequency'] ?? 0))), 4),
            'recency' => round(max(0, min(1, (float) ($signals['recency'] ?? 0))), 4),
            'continuity' => round(max(0, min(1, (float) ($signals['continuity'] ?? 0))), 4),
        ];
    }
}
