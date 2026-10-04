<?php

namespace App\Intelligence\Study;

use App\Intelligence\Contracts\StateBuilder;
use App\Intelligence\Data\EvidenceObservation;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Support\IntelligenceFingerprint;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final class StudyStateBuilder implements StateBuilder
{
    public function build(
        IntelligenceDomain $domain,
        array $context,
        iterable $evidence,
    ): StateSnapshot {
        if ($domain !== IntelligenceDomain::Study) {
            throw new InvalidArgumentException('StudyStateBuilder only supports the study domain.');
        }

        $observations = collect($evidence)
            ->filter(fn ($item) => $item instanceof EvidenceObservation)
            ->sortBy(fn (EvidenceObservation $item) => $item->occurredAt->format('U.u'))
            ->values();

        $practice = $observations
            ->where('type', 'study_practice_assessed')
            ->values();

        $recall = $observations
            ->where('type', 'study_recall_reviewed')
            ->values();

        $scores = $practice
            ->map(fn (EvidenceObservation $item) => data_get($item->facts, 'score_percent'))
            ->filter(fn ($score) => is_int($score))
            ->values();

        $latestPractice = $practice->last();

        $weaknesses = $practice
            ->flatMap(fn (EvidenceObservation $item) => (array) data_get($item->facts, 'weaknesses', []))
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn (string $item) => trim($item))
            ->unique()
            ->values()
            ->all();

        $strengths = $practice
            ->flatMap(fn (EvidenceObservation $item) => (array) data_get($item->facts, 'strengths', []))
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn (string $item) => trim($item))
            ->unique()
            ->values()
            ->all();

        $capturedAt = $this->capturedAt($context['captured_at'] ?? null);

        return new StateSnapshot(
            domain: IntelligenceDomain::Study,
            scopeType: trim((string) ($context['scope_type'] ?? 'study_scope')) ?: 'study_scope',
            scopeId: $context['scope_id'] ?? null,
            capturedAt: $capturedAt,
            metrics: [
                'evidence_count' => $observations->count(),
                'practice_attempt_count' => $practice->count(),
                'recall_review_count' => $recall->count(),
                'latest_score_percent' => $latestPractice
                    ? data_get($latestPractice->facts, 'score_percent')
                    : null,
                'average_score_percent' => $scores->isNotEmpty()
                    ? (int) round($scores->average())
                    : null,
                'best_score_percent' => $scores->isNotEmpty()
                    ? (int) $scores->max()
                    : null,
            ],
            facts: [
                'has_practice_evidence' => $practice->isNotEmpty(),
                'has_recall_evidence' => $recall->isNotEmpty(),
                'observed_strengths' => $strengths,
                'observed_weaknesses' => $weaknesses,
            ],
            evidenceReferences: $observations
                ->map(fn (EvidenceObservation $item) => [
                    'trace' => IntelligenceFingerprint::evidenceReference($item),
                    'origin' => $item->reference,
                ])
                ->values()
                ->all(),
        );
    }

    private function capturedAt(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value) && trim($value) !== '') {
            return new DateTimeImmutable($value);
        }

        return new DateTimeImmutable();
    }
}
