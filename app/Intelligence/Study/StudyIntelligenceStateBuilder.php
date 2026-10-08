<?php

namespace App\Intelligence\Study;

use App\Intelligence\Contracts\StateBuilder;
use App\Intelligence\Data\EvidenceObservation;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final class StudyIntelligenceStateBuilder implements StateBuilder
{
    public function __construct(
        private readonly StudyStateBuilder $baseBuilder,
        private readonly StudyScopeEvidenceMatcher $matcher,
        private readonly StudyRemainingWorkEstimator $remainingWork,
    ) {}

    public function build(
        IntelligenceDomain $domain,
        array $context,
        iterable $evidence,
    ): StateSnapshot {
        if ($domain !== IntelligenceDomain::Study) {
            throw new InvalidArgumentException(
                'StudyIntelligenceStateBuilder only supports the study domain.',
            );
        }

        $observations = collect($evidence)
            ->filter(fn ($item) => $item instanceof EvidenceObservation)
            ->values()
            ->all();

        $base = $this->baseBuilder->build(
            $domain,
            [
                ...$context,
                'scope_type' => $context['scope_type'] ?? 'study_plan',
                'scope_id' => $context['scope_id'] ?? null,
            ],
            $observations,
        );

        $scopeItems = collect($context['confirmed_scope_items'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->values()
            ->all();
        $tasks = collect($context['tasks'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->values()
            ->all();

        $scopeStates = $this->matcher->project(
            $scopeItems,
            $tasks,
            $observations,
        );

        $capturedAt = $this->capturedAt(
            $context['captured_at'] ?? $base->capturedAt,
        );
        $examDate = $this->dateStringOrNull($context['exam_date'] ?? null);
        $daysUntilExam = $this->daysUntilExam($capturedAt, $examDate);
        $remaining = $this->remainingWork->estimate(
            $scopeStates,
            $daysUntilExam,
        );

        $remainingByScope = collect($remaining['items'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->keyBy(fn (array $item) => (string) ($item['scope_item_id'] ?? ''));

        $scopeStates = collect($scopeStates)
            ->map(function (array $state) use ($remainingByScope) {
                $remaining = $remainingByScope->get(
                    (string) ($state['scope_item_id'] ?? ''),
                );

                return [
                    ...$state,
                    'remaining_unit' => is_array($remaining)
                        ? ($remaining['remaining_unit'] ?? null)
                        : null,
                ];
            })
            ->values()
            ->all();

        $scopeCount = count($scopeStates);
        $observedCount = collect($scopeStates)
            ->where('observed', true)
            ->count();

        $coverage = $scopeCount > 0
            ? (int) round(($observedCount / $scopeCount) * 100)
            : null;

        $masteryScores = collect($scopeStates)
            ->pluck('mastery_score_percent')
            ->filter(fn ($value) => is_int($value))
            ->values();
        $retentionScores = collect($scopeStates)
            ->pluck('retention_score_percent')
            ->filter(fn ($value) => is_int($value))
            ->values();

        $mastery = $masteryScores->isNotEmpty()
            ? (int) round($masteryScores->average())
            : null;
        $retention = $retentionScores->isNotEmpty()
            ? (int) round($retentionScores->average())
            : null;

        $evidenceLinkCount = (int) collect($scopeStates)
            ->sum(fn (array $item) => (int) ($item['linked_evidence_count'] ?? 0));

        return new StateSnapshot(
            domain: IntelligenceDomain::Study,
            scopeType: trim((string) ($context['scope_type'] ?? 'study_plan'))
                ?: 'study_plan',
            scopeId: $context['scope_id'] ?? null,
            capturedAt: $capturedAt,
            metrics: [
                ...$base->metrics,
                'confirmed_scope_count' => $scopeCount,
                'observed_scope_count' => $observedCount,
                'scope_evidence_link_count' => $evidenceLinkCount,
                'coverage_percent' => $coverage,
                'mastery_score_percent' => $mastery,
                'retention_score_percent' => $retention,
                'speed_score_percent' => null,
                'remaining_effort_units' => $remaining['remaining_units'],
                'remaining_effort_percent' => $remaining['remaining_percent'],
                'remaining_effort_units_per_day' => $remaining['units_per_day'],
                'days_until_exam' => $daysUntilExam,
            ],
            facts: [
                ...$base->facts,
                'study_intelligence_version' => '53.5',
                // Official exam's published syllabus is NOT a user-confirmed
                // subset and does not count as observed mastery or coverage.
                'official_exam_reference' => is_array($context['official_exam_reference'] ?? null)
                    ? $context['official_exam_reference']
                    : null,
                'official_exam_baseline_task_id' => is_numeric($context['official_exam_baseline_task_id'] ?? null)
                    ? (int) $context['official_exam_baseline_task_id']
                    : null,
                'has_confirmed_scope' => $scopeCount > 0,
                'exam_date' => $examDate,
                'exam_date_source' => $context['exam_date_source'] ?? null,
                'exam_date_conflict' => (bool) ($context['exam_date_conflict'] ?? false),
                'speed_status' => 'unmeasured',
                'deadline_pressure' => $remaining['deadline_pressure'],
                'scope_item_states' => $scopeStates,
                'priority_remaining_scope' => collect($remaining['items'] ?? [])
                    ->map(fn (array $item) => [
                        'scope_item_id' => $item['scope_item_id'] ?? null,
                        'subject' => $item['subject'] ?? null,
                        'unit' => $item['unit'] ?? null,
                        'remaining_unit' => $item['remaining_unit'] ?? null,
                        'mastery_score_percent' => $item['mastery_score_percent'] ?? null,
                        'retention_score_percent' => $item['retention_score_percent'] ?? null,
                    ])
                    ->take(12)
                    ->values()
                    ->all(),
            ],
            evidenceReferences: $base->evidenceReferences,
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

    private function dateStringOrNull(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    private function daysUntilExam(
        DateTimeImmutable $capturedAt,
        ?string $examDate,
    ): ?int {
        if ($examDate === null) {
            return null;
        }

        $exam = new DateTimeImmutable(
            $examDate.' 00:00:00',
            $capturedAt->getTimezone(),
        );

        return (int) $capturedAt
            ->setTime(0, 0)
            ->diff($exam)
            ->format('%r%a');
    }
}
