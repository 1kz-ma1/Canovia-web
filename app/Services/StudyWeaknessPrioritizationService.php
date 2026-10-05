<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;
use Illuminate\Support\Collection;

class StudyWeaknessPrioritizationService
{
    private const ERROR_WEIGHTS = [
        'concept_gap' => 1.00,
        'knowledge_gap' => 0.90,
        'reasoning_gap' => 0.85,
        'condition_reading' => 0.65,
        'unit_error' => 0.55,
        'unknown' => 0.60,
        'calculation_slip' => 0.30,
        'careless' => 0.20,
        'none' => 0.00,
    ];

    private const RECOVERY_COST = [
        'careless' => 0.55,
        'calculation_slip' => 0.60,
        'unit_error' => 0.75,
        'condition_reading' => 0.80,
        'knowledge_gap' => 0.85,
        'unknown' => 1.00,
        'concept_gap' => 1.15,
        'reasoning_gap' => 1.20,
        'none' => 1.00,
    ];

    /**
     * @param Collection<int,mixed> $recentAttempts newest first
     * @param array<int,string> $guidedTopics
     * @return array<string,mixed>
     */
    public function analyze(
        Plan $plan,
        Task $task,
        Collection $recentAttempts,
        array $guidedTopics = [],
        int $targetQuestionCount = 10,
    ): array {
        $attempts = $recentAttempts->take(8)->values();
        $topics = [];
        $strengthIndexes = [];

        foreach ($attempts as $attemptIndex => $attempt) {
            $ageWeight = max(0.50, 1.0 - ($attemptIndex * 0.08));
            $attemptSignals = [];
            $attemptErrors = [];
            $feedbackItems = collect(data_get(
                $attempt->assessment,
                'question_feedback',
                [],
            ))->filter(fn ($item) => is_array($item))->values();
            $hasStructuredFeedback = $feedbackItems->contains(
                fn (array $item) =>
                    array_key_exists('correctness', $item)
                    || array_key_exists('error_type', $item),
            );

            // Modern per-question classification is authoritative. Summary
            // weaknesses remain only as a compatibility fallback for older
            // attempts that do not have correctness/error_type.
            if (! $hasStructuredFeedback) {
                foreach ($this->strings($attempt->weaknesses ?? []) as $topic) {
                    $this->putSignal($attemptSignals, $topic, 0.30 * $ageWeight);
                    $attemptErrors[$this->key($topic)][] = 'unknown';
                }
            }

            foreach ($feedbackItems as $feedback) {
                if (! is_array($feedback)) {
                    continue;
                }

                $feedbackTopics = $this->strings($feedback['weakness_topics'] ?? []);
                if ($feedbackTopics === []) {
                    $feedbackTopics = $this->strings($feedback['misconceptions'] ?? []);
                }

                $correctness = (string) ($feedback['correctness'] ?? '');
                $correctnessWeight = match ($correctness) {
                    'incorrect' => 1.0,
                    'partial' => 0.60,
                    // V40-era attempts can contain misconceptions without the
                    // newer correctness/error_type fields. Keep those as a
                    // modest historical signal instead of discarding them.
                    '' => $feedbackTopics !== [] ? 0.45 : 0.0,
                    default => 0.0,
                };

                if ($correctnessWeight <= 0 || $feedbackTopics === []) {
                    continue;
                }

                $errorType = $this->normalizeErrorType((string) ($feedback['error_type'] ?? 'unknown'));
                $errorWeight = self::ERROR_WEIGHTS[$errorType] ?? self::ERROR_WEIGHTS['unknown'];

                foreach ($feedbackTopics as $topic) {
                    $this->putSignal(
                        $attemptSignals,
                        $topic,
                        $correctnessWeight * $errorWeight * $ageWeight,
                    );
                    $attemptErrors[$this->key($topic)][] = $errorType;
                }
            }

            $strengthKeys = collect($this->strings($attempt->strengths ?? []))
                ->map(fn (string $topic) => $this->key($topic))
                ->filter()
                ->unique()
                ->values()
                ->all();

            foreach ($attemptSignals as $topicKey => $signal) {
                if ($signal['weight'] <= 0) {
                    continue;
                }

                $topics[$topicKey] ??= [
                    'topic' => $signal['topic'],
                    'weighted_error' => 0.0,
                    'weak_attempts' => [],
                    'recent_attempts' => [],
                    'error_types' => [],
                    'latest_weak_index' => null,
                    'latest_strength_index' => null,
                ];

                $topics[$topicKey]['weighted_error'] += $signal['weight'];
                $topics[$topicKey]['weak_attempts'][$attemptIndex] = true;
                if ($attemptIndex <= 2) {
                    $topics[$topicKey]['recent_attempts'][$attemptIndex] = true;
                }
                $topics[$topicKey]['error_types'] = array_merge(
                    $topics[$topicKey]['error_types'],
                    $attemptErrors[$topicKey] ?? ['unknown'],
                );
                $topics[$topicKey]['latest_weak_index'] = $topics[$topicKey]['latest_weak_index'] === null
                    ? $attemptIndex
                    : min($topics[$topicKey]['latest_weak_index'], $attemptIndex);
            }

            foreach ($strengthKeys as $strengthKey) {
                $strengthIndexes[$strengthKey] = isset($strengthIndexes[$strengthKey])
                    ? min($strengthIndexes[$strengthKey], $attemptIndex)
                    : $attemptIndex;
            }
        }

        foreach ($strengthIndexes as $strengthKey => $strengthIndex) {
            if (isset($topics[$strengthKey])) {
                $topics[$strengthKey]['latest_strength_index'] = $strengthIndex;
            }
        }

        // AI next_step focus is advisory only. It may break a near tie among
        // already observed weaknesses, but it cannot create a new weakness.
        foreach ($this->strings($guidedTopics) as $guidedTopic) {
            $key = $this->key($guidedTopic);
            if ($key === '' || ! isset($topics[$key])) {
                continue;
            }

            $topics[$key]['weighted_error'] += 0.02;
        }

        $scope = mb_strtolower(implode(' ', [
            (string) $task->title,
            (string) ($task->description ?? ''),
            (string) $plan->title,
        ]));

        $ranked = collect($topics)
            ->map(function (array $topic) use ($scope) {
                $weakAttemptCount = count($topic['weak_attempts']);
                $recentCount = count($topic['recent_attempts']);
                $latestWeak = $topic['latest_weak_index'];
                $latestStrength = $topic['latest_strength_index'];

                $resolved = $latestStrength === 0
                    && ($latestWeak === null || $latestWeak > 0)
                    && $weakAttemptCount <= 1;

                $dominantError = $this->dominantErrorType($topic['error_types']);
                $recoveryCost = self::RECOVERY_COST[$dominantError] ?? 1.0;

                $severity = min(1.0, max(0.10, (float) $topic['weighted_error'] / 1.8));
                $confidence = match (true) {
                    $weakAttemptCount >= 4 => 0.95,
                    $weakAttemptCount === 3 => 0.85,
                    $weakAttemptCount === 2 => 0.70,
                    default => 0.42,
                };

                // Saturation is intentionally about recent repetition,
                // not about how large this topic is relative to all weaknesses.
                // A single known weakness must not look "fully saturated" after
                // only one or two attempts.
                $saturation = min(1.0, $recentCount / 3.0);
                $saturationPenalty = 1.0 + (0.70 * $saturation);

                $examRelevance = str_contains($scope, mb_strtolower($topic['topic'])) ? 1.15 : 1.0;
                $expectedGain = min(1.0, (0.60 * $severity) + (0.40 * $confidence));
                $transferValue = min(1.15, 0.90 + (0.08 * max(0, $weakAttemptCount - 1)));
                $valuePerCost = $expectedGain / max(0.45, $recoveryCost);

                $priority = $resolved
                    ? 0.0
                    : ($severity * $confidence * $expectedGain * $examRelevance * $transferValue)
                        / max(0.45, $recoveryCost * $saturationPenalty);

                $state = match (true) {
                    $resolved => 'resolved',
                    $latestStrength === 0
                        && ($latestWeak === null || $latestWeak > 0)
                        && $weakAttemptCount >= 2 => 'stabilizing',
                    $weakAttemptCount >= 2 => 'confirmed',
                    $latestWeak === 0 => 'suspected',
                    default => 'monitoring',
                };

                return [
                    'topic' => $topic['topic'],
                    'state' => $state,
                    'priority_score' => round($priority, 4),
                    'severity' => round($severity, 3),
                    'confidence' => round($confidence, 3),
                    'expected_gain' => round($expectedGain, 3),
                    'recovery_cost' => round($recoveryCost, 3),
                    'value_per_cost' => round($valuePerCost, 3),
                    'saturation' => round($saturation, 3),
                    'exam_relevance' => round($examRelevance, 3),
                    'dominant_error_type' => $dominantError,
                    'weak_attempt_count' => $weakAttemptCount,
                    'latest_weak_attempt_index' => $latestWeak,
                ];
            })
            ->sortByDesc('priority_score')
            ->values();

        $active = $ranked->where('state', '!=', 'resolved')->values();

        // A single mistake is not enough to monopolize the next practice.
        // Primary reinforcement requires repeated evidence.
        $primary = $active
            ->filter(fn (array $item) => $item['state'] === 'confirmed' && $item['confidence'] >= 0.65)
            ->take(2)
            ->values();

        $primaryTopics = $primary->pluck('topic');

        $secondary = $active
            ->reject(fn (array $item) => $primaryTopics->contains($item['topic']))
            ->filter(fn (array $item) => in_array($item['state'], ['confirmed', 'suspected', 'stabilizing'], true))
            ->take(3)
            ->values();

        $monitor = $active
            ->reject(fn (array $item) => $primaryTopics->contains($item['topic']))
            ->reject(fn (array $item) => $secondary->pluck('topic')->contains($item['topic']))
            ->take(4)
            ->values();

        $mix = $this->questionMix(
            max(1, min(20, $targetQuestionCount)),
            $primary,
            $secondary,
        );

        $withTier = $ranked->map(function (array $item) use ($primary, $secondary) {
            $item['tier'] = match (true) {
                $primary->pluck('topic')->contains($item['topic']) => 'primary',
                $secondary->pluck('topic')->contains($item['topic']) => 'secondary',
                $item['state'] === 'resolved' => 'resolved',
                default => 'monitor',
            };

            return $item;
        })->values();

        return [
            'version' => 'v1',
            'ranked' => $withTier->all(),
            'primary_topics' => $primary->pluck('topic')->all(),
            'secondary_topics' => $secondary->pluck('topic')->all(),
            'monitor_topics' => $monitor->pluck('topic')->all(),
            'question_mix' => $mix,
            'has_confirmed_weakness' => $primary->isNotEmpty(),
            'has_any_weakness_signal' => $active->isNotEmpty(),
        ];
    }

    /**
     * @param Collection<int,array<string,mixed>> $primary
     * @param Collection<int,array<string,mixed>> $secondary
     * @return array<string,int>
     */
    private function questionMix(int $target, Collection $primary, Collection $secondary): array
    {
        if ($primary->isEmpty() && $secondary->isEmpty()) {
            return [
                'primary' => 0,
                'secondary' => 0,
                'diagnostic' => $target,
            ];
        }

        if ($primary->isEmpty()) {
            $secondaryCount = max(1, (int) round($target * 0.40));

            return [
                'primary' => 0,
                'secondary' => min($target, $secondaryCount),
                'diagnostic' => max(0, $target - $secondaryCount),
            ];
        }

        $highSaturation = (float) ($primary->max('saturation') ?? 0.0) >= 0.75;
        $primaryRatio = $highSaturation ? 0.40 : 0.50;
        $secondaryRatio = $secondary->isNotEmpty() ? 0.30 : 0.0;

        $primaryCount = max(1, (int) round($target * $primaryRatio));
        $secondaryCount = $secondaryRatio > 0
            ? max(1, (int) round($target * $secondaryRatio))
            : 0;

        if ($primaryCount + $secondaryCount > $target - 1 && $target >= 2) {
            $secondaryCount = max(0, $target - $primaryCount - 1);
        }

        return [
            'primary' => $primaryCount,
            'secondary' => $secondaryCount,
            'diagnostic' => max(0, $target - $primaryCount - $secondaryCount),
        ];
    }

    /**
     * @param array<string,array{topic:string,weight:float}> $attemptSignals
     */
    private function putSignal(array &$attemptSignals, string $topic, float $weight): void
    {
        $key = $this->key($topic);
        if ($key === '') {
            return;
        }

        $current = $attemptSignals[$key]['weight'] ?? 0.0;
        if ($weight > $current) {
            $attemptSignals[$key] = [
                'topic' => trim($topic),
                'weight' => $weight,
            ];
        }
    }

    /**
     * @param array<int,string> $types
     */
    private function dominantErrorType(array $types): string
    {
        if ($types === []) {
            return 'unknown';
        }

        $counts = collect($types)
            ->map(fn (string $type) => $this->normalizeErrorType($type))
            ->countBy();

        return (string) $counts
            ->map(fn ($count, $type) => [
                'type' => (string) $type,
                'count' => (int) $count,
                'specific' => $type === 'unknown' ? 0 : 1,
                'weight' => (float) (self::ERROR_WEIGHTS[$type] ?? 0.0),
            ])
            ->sort(function (array $left, array $right) {
                return [
                    -1 * $left['count'],
                    -1 * $left['specific'],
                    -1 * $left['weight'],
                    $left['type'],
                ] <=> [
                    -1 * $right['count'],
                    -1 * $right['specific'],
                    -1 * $right['weight'],
                    $right['type'],
                ];
            })
            ->first()['type'];
    }

    private function normalizeErrorType(string $type): string
    {
        $type = trim($type);

        return array_key_exists($type, self::ERROR_WEIGHTS)
            ? $type
            : 'unknown';
    }

    /**
     * @return array<int,string>
     */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($item) => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn ($item) => trim((string) $item))
            ->unique()
            ->values()
            ->all();
    }

    private function key(string $topic): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $topic) ?? $topic));
    }
}
