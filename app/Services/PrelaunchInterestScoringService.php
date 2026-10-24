<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Pure, versioned, UNVALIDATED exploratory interest scoring.
 *
 * This class has no auth, DB, queue, provider or network effects. The
 * controller must supply a reviewed bank, handle per-user authorization,
 * ensure privacy consent, and label results as self-reported estimates.
 */
final class PrelaunchInterestScoringService
{
    /**
     * @param array<string, int|string> $answers Q01 => 0..3 or literal 'unknown'
     * @param array<string, mixed> $bank Reviewed, versioned question bank
     * @return array<string, mixed>
     */
    public function calculate(array $answers, array $bank): array
    {
        $items = $bank['items'] ?? null;
        $categories = $bank['categories'] ?? null;
        if (! is_array($items) || count($items) !== 30
            || ! is_array($categories) || count($categories) !== 8) {
            throw new InvalidArgumentException('INVALID_INTEREST_BANK');
        }

        foreach ($answers as $id => $value) {
            if (! is_string($id) || ! array_key_exists($id, $items)) {
                throw new InvalidArgumentException('UNKNOWN_INTEREST_ITEM');
            }
            if ($value !== 'unknown' && (! is_int($value) || $value < 0 || $value > 3)) {
                throw new InvalidArgumentException('INVALID_INTEREST_ANSWER');
            }
        }

        $totals = [];
        foreach ($categories as $category => $label) {
            $totals[$category] = [
                'label' => $label,
                'points' => 0.0,
                'answered_weight' => 0.0,
                'answered_primary' => 0,
            ];
        }

        $answered = 0;
        $unknown = 0;
        foreach ($items as $id => $item) {
            $primary = $item['primary'] ?? null;
            $secondary = $item['secondary'] ?? null;
            if (! is_string($primary) || ! array_key_exists($primary, $totals)
                || ($secondary !== null
                    && (! is_string($secondary) || $secondary === $primary
                        || ! array_key_exists($secondary, $totals)))) {
                throw new InvalidArgumentException('INVALID_INTEREST_ITEM_MAPPING');
            }

            if (! array_key_exists($id, $answers)) {
                continue; // Not answered; no implied negative.
            }
            $value = $answers[$id];
            if ($value === 'unknown') {
                $unknown++;
                continue; // Unknown is NOT neutral and NOT zero.
            }

            $answered++;
            $weight = $secondary === null ? 1.0 : 0.5;
            $totals[$primary]['points'] += $weight * $value;
            $totals[$primary]['answered_weight'] += $weight;
            if ($secondary === null) {
                $totals[$primary]['answered_primary']++;
            } else {
                $totals[$secondary]['points'] += 0.5 * $value;
                $totals[$secondary]['answered_weight'] += 0.5;
            }
        }

        $results = [];
        $sum = 0.0;
        foreach ($totals as $category => $total) {
            $sufficient = $total['answered_primary'] >= 2;
            $score = $sufficient && $total['answered_weight'] > 0
                ? $total['points'] / $total['answered_weight']
                : null;
            if ($score !== null) {
                $sum += $score;
            }
            $results[$category] = [
                'label' => $total['label'],
                'status' => $sufficient ? 'ready' : 'insufficient_evidence',
                'answered_primary' => $total['answered_primary'],
                'score' => $score,
                'share_percent' => null,
            ];
        }

        $status = $sum > 0.0 ? 'ready'
            : (count(array_filter($results, fn (array $r) => $r['score'] !== null)) > 0
                ? 'no_relative_signal' : 'insufficient_evidence');

        if ($status === 'ready') {
            // Allocate integer percentage points deterministically, with a
            // stable bank-order tie-break. Never round each bar separately.
            $remaining = 100;
            $fractions = [];
            $position = 0;
            foreach ($results as $category => &$result) {
                if ($result['score'] !== null) {
                    $exact = 100.0 * $result['score'] / $sum;
                    $floor = (int) floor($exact);
                    $result['share_percent'] = $floor;
                    $remaining -= $floor;
                    $fractions[] = [
                        'category' => $category,
                        'fraction' => $exact - $floor,
                        'position' => $position,
                    ];
                }
                $position++;
            }
            unset($result);
            usort($fractions, static fn (array $a, array $b): int =>
                ($b['fraction'] <=> $a['fraction']) ?: ($a['position'] <=> $b['position']));
            for ($i = 0; $i < $remaining; $i++) {
                $results[$fractions[$i]['category']]['share_percent']++;
            }
        }

        return [
            'item_bank_version' => $bank['item_bank_version'] ?? null,
            'scoring_version' => $bank['scoring_version'] ?? null,
            'category_version' => $bank['category_version'] ?? null,
            'status' => $status,
            'answered_count' => $answered,
            'unknown_count' => $unknown,
            'unanswered_count' => count($items) - count($answers),
            'categories' => $results,
        ];
    }
}
