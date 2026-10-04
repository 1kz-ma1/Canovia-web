<?php

namespace App\Intelligence\Study;

use App\Intelligence\Data\EvidenceObservation;

final class StudyScopeEvidenceMatcher
{
    /**
     * @param array<int,array<string,mixed>> $scopeItems
     * @param array<int,array<string,mixed>> $tasks
     * @param array<int,EvidenceObservation> $evidence
     * @return array<int,array<string,mixed>>
     */
    public function project(
        array $scopeItems,
        array $tasks,
        array $evidence,
    ): array {
        $taskMap = collect($tasks)
            ->filter(fn ($task) => is_array($task) && isset($task['id']))
            ->keyBy(fn (array $task) => (int) $task['id']);

        $subjectCounts = collect($scopeItems)
            ->map(fn (array $item) => $this->normalize((string) ($item['subject'] ?? '')))
            ->filter()
            ->countBy();

        $orderedEvidence = collect($evidence)
            ->filter(fn ($item) => $item instanceof EvidenceObservation)
            ->filter(fn (EvidenceObservation $item) => in_array(
                $item->type,
                ['study_practice_assessed', 'study_recall_reviewed'],
                true,
            ))
            ->sortBy(fn (EvidenceObservation $item) => $item->occurredAt->format('U.u'))
            ->values();

        return collect($scopeItems)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $scope) use (
                $taskMap,
                $subjectCounts,
                $orderedEvidence,
            ) {
                $subject = $this->normalize((string) ($scope['subject'] ?? ''));
                $unit = $this->normalize((string) ($scope['unit'] ?? ''));
                $rangeTokens = $this->rangeTokens((string) ($scope['range_text'] ?? ''));
                $subjectUnique = $subject !== ''
                    && (int) ($subjectCounts[$subject] ?? 0) === 1;

                $practiceScores = [];
                $recallScores = [];
                $taskIds = [];
                $matchedWeaknesses = [];
                $matchConfidence = 0.0;
                $linkedEvidenceCount = 0;

                foreach ($orderedEvidence as $observation) {
                    $taskId = (int) data_get($observation->facts, 'task_id', 0);
                    if ($taskId <= 0) {
                        continue;
                    }

                    $task = $taskMap->get($taskId);
                    if (! is_array($task)) {
                        continue;
                    }

                    $taskText = $this->normalize(implode(' ', array_filter([
                        $task['title'] ?? null,
                        $task['description'] ?? null,
                        $task['next_action_note'] ?? null,
                    ], fn ($value) => is_scalar($value) && trim((string) $value) !== '')));

                    $labels = $this->evidenceLabels($observation);
                    $match = $this->matchConfidence(
                        subject: $subject,
                        unit: $unit,
                        rangeTokens: $rangeTokens,
                        subjectUnique: $subjectUnique,
                        taskText: $taskText,
                        evidenceLabels: $labels,
                    );

                    if ($match < 0.70) {
                        continue;
                    }

                    $linkedEvidenceCount++;
                    $taskIds[] = $taskId;
                    $matchConfidence = max($matchConfidence, $match);

                    if ($observation->type === 'study_practice_assessed') {
                        $score = $this->percentOrNull(
                            data_get($observation->facts, 'score_percent'),
                        );
                        if ($score !== null) {
                            $practiceScores[] = $score;
                        }

                        foreach ($this->weaknessLabels($observation) as $weakness) {
                            if ($this->labelMatchesScope(
                                $weakness,
                                $subject,
                                $unit,
                                $rangeTokens,
                                $subjectUnique,
                            )) {
                                $matchedWeaknesses[] = $weakness;
                            }
                        }
                    }

                    if ($observation->type === 'study_recall_reviewed') {
                        $recallScore = $this->recallScore($observation);
                        if ($recallScore !== null) {
                            $recallScores[] = $recallScore;
                        }
                    }
                }

                $practiceScores = array_values($practiceScores);
                $recallScores = array_values($recallScores);
                $matchedWeaknesses = array_values(array_unique($matchedWeaknesses));

                $mastery = $this->masteryScore(
                    $practiceScores,
                    count($matchedWeaknesses),
                );
                $retention = $this->retentionScore($recallScores);

                return [
                    'scope_item_id' => isset($scope['id']) ? (int) $scope['id'] : null,
                    'subject' => trim((string) ($scope['subject'] ?? '')) ?: null,
                    'unit' => trim((string) ($scope['unit'] ?? '')) ?: null,
                    'range_text' => trim((string) ($scope['range_text'] ?? '')) ?: null,
                    'page_start' => isset($scope['page_start']) ? (int) $scope['page_start'] : null,
                    'page_end' => isset($scope['page_end']) ? (int) $scope['page_end'] : null,
                    'observed' => $linkedEvidenceCount > 0,
                    'linked_evidence_count' => $linkedEvidenceCount,
                    'task_ids' => array_values(array_unique($taskIds)),
                    'match_confidence' => round($matchConfidence, 4),
                    'practice_count' => count($practiceScores),
                    'recall_count' => count($recallScores),
                    'mastery_score_percent' => $mastery,
                    'retention_score_percent' => $retention,
                    'speed_score_percent' => null,
                    'matched_weaknesses' => $matchedWeaknesses,
                ];
            })
            ->values()
            ->all();
    }

    private function matchConfidence(
        string $subject,
        string $unit,
        array $rangeTokens,
        bool $subjectUnique,
        string $taskText,
        array $evidenceLabels,
    ): float {
        $labelsText = $this->normalize(implode(' ', $evidenceLabels));

        if ($unit !== '') {
            if (
                $this->containsPhrase($taskText, $unit)
                || $this->containsPhrase($labelsText, $unit)
            ) {
                return 0.98;
            }
        }

        foreach ($rangeTokens as $token) {
            if (
                $this->containsPhrase($taskText, $token)
                || $this->containsPhrase($labelsText, $token)
            ) {
                return 0.88;
            }
        }

        if (
            $unit === ''
            && $subjectUnique
            && $subject !== ''
            && (
                $this->containsPhrase($taskText, $subject)
                || $this->containsPhrase($labelsText, $subject)
            )
        ) {
            return 0.74;
        }

        return 0.0;
    }

    private function labelMatchesScope(
        string $label,
        string $subject,
        string $unit,
        array $rangeTokens,
        bool $subjectUnique,
    ): bool {
        $label = $this->normalize($label);
        if ($label === '') {
            return false;
        }

        if ($unit !== '' && (
            $this->containsPhrase($label, $unit)
            || $this->containsPhrase($unit, $label)
        )) {
            return true;
        }

        foreach ($rangeTokens as $token) {
            if (
                $this->containsPhrase($label, $token)
                || $this->containsPhrase($token, $label)
            ) {
                return true;
            }
        }

        return $unit === ''
            && $subjectUnique
            && $subject !== ''
            && (
                $this->containsPhrase($label, $subject)
                || $this->containsPhrase($subject, $label)
            );
    }

    /**
     * @return array<int,string>
     */
    private function evidenceLabels(EvidenceObservation $observation): array
    {
        return collect([
            ...(array) data_get($observation->facts, 'strengths', []),
            ...(array) data_get($observation->facts, 'weaknesses', []),
            ...(array) data_get($observation->facts, 'weakness_topics', []),
        ])
            ->filter(fn ($item) => is_scalar($item))
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function weaknessLabels(EvidenceObservation $observation): array
    {
        return collect([
            ...(array) data_get($observation->facts, 'weaknesses', []),
            ...(array) data_get($observation->facts, 'weakness_topics', []),
        ])
            ->filter(fn ($item) => is_scalar($item))
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function masteryScore(array $scores, int $weaknessCount): ?int
    {
        if ($scores === []) {
            return null;
        }

        $latest = (int) end($scores);
        $average = array_sum($scores) / count($scores);
        $performance = ($latest * 0.65) + ($average * 0.35);
        $penalty = min(12, max(0, $weaknessCount) * 4);

        return (int) round(max(0, min(100, $performance - $penalty)));
    }

    private function retentionScore(array $scores): ?int
    {
        if ($scores === []) {
            return null;
        }

        $latest = (int) end($scores);
        $average = array_sum($scores) / count($scores);

        return (int) round(max(0, min(
            100,
            ($latest * 0.65) + ($average * 0.35),
        )));
    }

    private function recallScore(EvidenceObservation $observation): ?int
    {
        if ((bool) data_get($observation->facts, 'mastered', false)) {
            return 100;
        }

        return match ((string) data_get($observation->facts, 'rating', '')) {
            'again' => 20,
            'hard' => 50,
            'good' => 80,
            'easy' => 95,
            default => null,
        };
    }

    private function percentOrNull(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return $value === false
            ? null
            : max(0, min(100, (int) $value));
    }

    /**
     * @return array<int,string>
     */
    private function rangeTokens(string $range): array
    {
        preg_match_all(
            '/[A-Za-z]+\s*\d+|[A-Za-z0-9]{3,}|[\p{Han}\p{Hiragana}\p{Katakana}ー]{2,}/u',
            $range,
            $matches,
        );

        $stop = [
            '教科書', 'ワーク', '問題集', 'プリント', 'ページ',
            '範囲', '問題', '章', '単元', 'page', 'pages',
        ];

        return collect($matches[0] ?? [])
            ->map(fn ($token) => $this->normalize((string) $token))
            ->filter(fn ($token) => mb_strlen($token) >= 2)
            ->reject(fn ($token) => in_array($token, $stop, true))
            ->unique()
            ->take(12)
            ->values()
            ->all();
    }

    private function containsPhrase(string $haystack, string $needle): bool
    {
        return $haystack !== ''
            && $needle !== ''
            && mb_strpos($haystack, $needle) !== false;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[\s　]+/u', ' ', $value) ?? $value;
        $value = str_replace(
            ['「', '」', '『', '』', '【', '】', '[', ']', '(', ')', '（', '）', ',', ':', '：', ';', '；', '・', '/', '\\'],
            ' ',
            $value,
        );

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
