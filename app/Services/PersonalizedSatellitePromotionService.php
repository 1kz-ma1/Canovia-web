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
    public function promote(Collection $candidates, int $limit = self::MAX_SATELLITES): array
    {
        $limit = max(0, min(self::MAX_SATELLITES, $limit));

        if ($limit === 0 || $candidates->isEmpty()) {
            return [
                'nodes' => collect(),
                'edges' => collect(),
                'promoted' => collect(),
            ];
        }

        $ranked = $candidates
            ->map(function (array $candidate) {
                $score = $this->score((array) ($candidate['signals'] ?? []));
                $candidate['promotion_score'] = $score;

                return $candidate;
            })
            ->filter(fn (array $candidate) => (float) ($candidate['promotion_score'] ?? 0) >= self::MIN_PROMOTION_SCORE)
            ->sort(function (array $left, array $right) {
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
            $slot = $index + 1;
            $score = (float) ($candidate['promotion_score'] ?? 0);
            $position = $this->slotPosition($slot);

            $nodes->push([
                'id' => (string) ($candidate['id'] ?? ''),
                'type' => (string) ($candidate['node_type'] ?? 'satellite_plan'),
                'entity_id' => $candidate['entity_id'] ?? null,
                'eyebrow' => (string) ($candidate['eyebrow'] ?? 'SATELLITE'),
                'label' => (string) ($candidate['label'] ?? ''),
                'subtitle' => (string) ($candidate['subtitle'] ?? ''),
                'importance' => round(0.58 + ($score * 0.30), 4),
                'state' => 'satellite',
                'position_role' => 'satellite-'.$slot,
                'size_weight' => round(0.62 + ($score * 0.20), 4),
                'position' => $position,
                'available_action' => $candidate['available_action'] ?? null,
                'classic_surface' => $candidate['classic_surface'] ?? [],
                'navigation_kind' => $candidate['navigation_kind'] ?? 'satellite',
                'promotion_score' => round($score, 4),
                'promotion_signals' => $this->safeSignals((array) ($candidate['signals'] ?? [])),
            ]);

            $anchor = (string) ($candidate['anchor_node_id'] ?? 'intent:plan');
            $edges->push([
                'source' => $anchor,
                'target' => (string) ($candidate['id'] ?? ''),
                'relation' => 'personalized_shortcut',
                'strength' => round(0.30 + ($score * 0.40), 4),
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

    /**
     * @return array{x:int,y:int}
     */
    private function slotPosition(int $slot): array
    {
        return match ($slot) {
            1 => ['x' => 50, 'y' => 12],
            2 => ['x' => 88, 'y' => 50],
            3 => ['x' => 50, 'y' => 88],
            default => ['x' => 12, 'y' => 50],
        };
    }

    private function kindOrder(string $kind): int
    {
        return match ($kind) {
            'tool' => 0,
            'plan' => 1,
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
