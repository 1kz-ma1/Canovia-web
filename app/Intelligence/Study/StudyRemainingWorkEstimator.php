<?php

namespace App\Intelligence\Study;

final class StudyRemainingWorkEstimator
{
    /**
     * @param array<int,array<string,mixed>> $scopeStates
     * @return array<string,mixed>
     */
    public function estimate(array $scopeStates, ?int $daysUntilExam): array
    {
        if ($scopeStates === []) {
            return [
                'remaining_units' => null,
                'remaining_percent' => null,
                'units_per_day' => null,
                'deadline_pressure' => 'unknown',
                'items' => [],
            ];
        }

        $items = collect($scopeStates)
            ->map(function (array $item) {
                $unit = $this->remainingUnit($item);

                return [
                    ...$item,
                    'remaining_unit' => $unit,
                ];
            })
            ->values();

        $remainingUnits = round(
            (float) $items->sum('remaining_unit'),
            2,
        );
        $remainingPercent = (int) round(
            ($remainingUnits / max(1, $items->count())) * 100,
        );

        $unitsPerDay = null;
        $pressure = 'unknown';

        if ($daysUntilExam !== null) {
            if ($daysUntilExam < 0) {
                $pressure = $remainingUnits > 0.05 ? 'overdue' : 'low';
            } else {
                $unitsPerDay = round(
                    $remainingUnits / max(1, $daysUntilExam + 1),
                    2,
                );

                $pressure = match (true) {
                    $unitsPerDay > 1.50 => 'high',
                    $unitsPerDay > 0.75 => 'medium',
                    default => 'low',
                };
            }
        }

        $prioritized = $items
            ->sort(function (array $left, array $right) {
                $remaining = ($right['remaining_unit'] ?? 0)
                    <=> ($left['remaining_unit'] ?? 0);

                if ($remaining !== 0) {
                    return $remaining;
                }

                return ($left['mastery_score_percent'] ?? -1)
                    <=> ($right['mastery_score_percent'] ?? -1);
            })
            ->take(20)
            ->values()
            ->all();

        return [
            'remaining_units' => $remainingUnits,
            'remaining_percent' => max(0, min(100, $remainingPercent)),
            'units_per_day' => $unitsPerDay,
            'deadline_pressure' => $pressure,
            'items' => $prioritized,
        ];
    }

    /**
     * One confirmed scope item is one normalized effort unit.
     *
     * This is deliberately not converted to minutes in V53.5.
     */
    private function remainingUnit(array $item): float
    {
        $observed = (bool) ($item['observed'] ?? false);
        $mastery = $this->percentOrNull($item['mastery_score_percent'] ?? null);
        $retention = $this->percentOrNull($item['retention_score_percent'] ?? null);
        $weaknessCount = count((array) ($item['matched_weaknesses'] ?? []));

        if (! $observed) {
            return 1.0;
        }

        if ($mastery === null) {
            $unit = $retention !== null ? 0.65 : 0.90;
        } else {
            $unit = match (true) {
                $mastery < 50 => 1.00,
                $mastery < 70 => 0.75,
                $mastery < 80 => 0.50,
                $retention === null => 0.35,
                $retention < 70 => 0.25,
                default => 0.10,
            };
        }

        if ($weaknessCount > 0) {
            $unit = max($unit, 0.75);
        }

        return round(max(0.0, min(1.0, $unit)), 2);
    }

    private function percentOrNull(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return $value === false
            ? null
            : max(0, min(100, (int) $value));
    }
}
