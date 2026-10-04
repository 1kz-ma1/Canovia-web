<?php

namespace App\Intelligence\Career;

use App\Intelligence\Contracts\StateBuilder;
use App\Intelligence\Data\EvidenceObservation;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final class CareerStateBuilder implements StateBuilder
{
    public function build(
        IntelligenceDomain $domain,
        array $context,
        iterable $evidence,
    ): StateSnapshot {
        if ($domain !== IntelligenceDomain::Career) {
            throw new InvalidArgumentException(
                'CareerStateBuilder only supports the career domain.',
            );
        }

        $capturedAt = $this->capturedAt(
            $context['captured_at'] ?? null,
        );

        $captures = collect($context['captures'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->values();

        $applications = collect($context['applications'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->values();

        $events = collect($context['selection_events'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->values();

        $observations = collect($evidence)
            ->filter(fn ($item) => $item instanceof EvidenceObservation)
            ->sort(function (
                EvidenceObservation $left,
                EvidenceObservation $right,
            ): int {
                $time = $left->occurredAt <=> $right->occurredAt;

                return $time !== 0
                    ? $time
                    : strcmp($left->reference, $right->reference);
            })
            ->values();

        $pendingCaptures = $captures
            ->filter(fn (array $capture) =>
                ($capture['status'] ?? null) === 'pending'
            )
            ->values();

        $activeApplications = $applications
            ->filter(fn (array $application) =>
                ($application['status'] ?? null) === 'active'
            )
            ->values();

        $waitingApplications = $applications
            ->filter(fn (array $application) =>
                ($application['status'] ?? null) === 'waiting'
            )
            ->values();

        $offers = $applications
            ->filter(fn (array $application) =>
                ($application['stage'] ?? null) === 'offer'
                && in_array(
                    ($application['status'] ?? null),
                    ['active', 'waiting'],
                    true,
                )
            )
            ->values();

        $scheduledInterviews = $events
            ->filter(fn (array $event) =>
                ($event['type'] ?? null) === 'interview'
                && ($event['status'] ?? null) === 'scheduled'
                && $this->dateTime($event['scheduled_at'] ?? null)
                    instanceof DateTimeImmutable
            )
            ->values();

        $nextInterview = $scheduledInterviews
            ->filter(function (array $event) use ($capturedAt): bool {
                $scheduled = $this->dateTime(
                    $event['scheduled_at'] ?? null,
                );

                return $scheduled instanceof DateTimeImmutable
                    && $scheduled > $capturedAt;
            })
            ->sortBy(fn (array $event) =>
                $this->dateTime($event['scheduled_at'] ?? null)
                    ?->getTimestamp() ?? PHP_INT_MAX
            )
            ->first();

        $reviewDue = $events
            ->filter(function (array $event) use ($capturedAt): bool {
                if (
                    ($event['type'] ?? null) !== 'interview'
                    || ($event['status'] ?? null) === 'cancelled'
                    || ($event['review_status'] ?? null) === 'completed'
                ) {
                    return false;
                }

                if (in_array(
                    ($event['status'] ?? null),
                    ['completed', 'result_waiting'],
                    true,
                )) {
                    return true;
                }

                $scheduled = $this->dateTime(
                    $event['scheduled_at'] ?? null,
                );

                return ($event['status'] ?? null) === 'scheduled'
                    && $scheduled instanceof DateTimeImmutable
                    && $scheduled <= $capturedAt;
            })
            ->sortBy(fn (array $event) =>
                $this->dateTime($event['scheduled_at'] ?? null)
                    ?->getTimestamp() ?? PHP_INT_MAX
            )
            ->first();

        $resultWaiting = $events
            ->filter(fn (array $event) =>
                ($event['status'] ?? null) === 'result_waiting'
            )
            ->values();

        $completedReviews = $events
            ->filter(fn (array $event) =>
                ($event['review_status'] ?? null) === 'completed'
            )
            ->values();

        $stageCounts = $applications
            ->groupBy(fn (array $application) =>
                (string) ($application['stage'] ?? 'unknown')
            )
            ->map(fn ($items) => $items->count())
            ->sortKeys()
            ->all();

        $hoursUntilInterview = null;
        if (is_array($nextInterview)) {
            $scheduled = $this->dateTime(
                $nextInterview['scheduled_at'] ?? null,
            );

            if ($scheduled instanceof DateTimeImmutable) {
                $hoursUntilInterview = max(
                    0,
                    (int) ceil(
                        ($scheduled->getTimestamp()
                            - $capturedAt->getTimestamp()) / 3600,
                    ),
                );
            }
        }

        $hasSignal = $captures->isNotEmpty()
            || $applications->isNotEmpty()
            || $events->isNotEmpty()
            || $observations->isNotEmpty();

        $references = collect()
            ->concat(
                $captures
                    ->pluck('id')
                    ->filter()
                    ->map(fn ($id) => 'career_capture:'.(int) $id),
            )
            ->concat(
                $applications
                    ->pluck('id')
                    ->filter()
                    ->map(fn ($id) => 'career_application:'.(int) $id),
            )
            ->concat(
                $events
                    ->pluck('id')
                    ->filter()
                    ->map(fn ($id) => 'career_selection_event:'.(int) $id),
            )
            ->concat(
                $events
                    ->pluck('review_id')
                    ->filter()
                    ->map(fn ($id) => 'interview_review:'.(int) $id),
            )
            ->concat(
                $observations->flatMap(
                    fn (EvidenceObservation $item) => [
                        $item->reference,
                        ...$item->references,
                    ],
                ),
            )
            ->filter()
            ->unique()
            ->sort()
            ->take(160)
            ->values()
            ->all();

        return new StateSnapshot(
            domain: IntelligenceDomain::Career,
            scopeType: trim((string) (
                $context['scope_type'] ?? 'career_plan'
            )) ?: 'career_plan',
            scopeId: $context['scope_id'] ?? null,
            capturedAt: $capturedAt,
            metrics: [
                'capture_count' => $captures->count(),
                'pending_capture_count' => $pendingCaptures->count(),
                'application_count' => $applications->count(),
                'active_application_count' => $activeApplications->count(),
                'waiting_application_count' => $waitingApplications->count(),
                'offer_application_count' => $offers->count(),
                'scheduled_interview_count' =>
                    $scheduledInterviews->count(),
                'review_due_count' => is_array($reviewDue) ? 1 : 0,
                'result_waiting_count' => $resultWaiting->count(),
                'completed_review_count' => $completedReviews->count(),
                'career_task_evidence_count' => $observations->count(),
                'hours_until_next_interview' => $hoursUntilInterview,
            ],
            facts: [
                'career_intelligence_version' => '55.0',
                'has_career_signal' => $hasSignal,
                'pipeline_stage_counts' => $stageCounts,
                'pending_capture_ids' => $pendingCaptures
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->take(24)
                    ->values()
                    ->all(),
                'active_application_ids' => $activeApplications
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->take(24)
                    ->values()
                    ->all(),
                'candidate_application_ids' => $applications
                    ->filter(fn (array $application) =>
                        in_array(
                            ($application['stage'] ?? null),
                            ['candidate', 'preparing'],
                            true,
                        )
                        && ($application['status'] ?? null) === 'active'
                    )
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->take(24)
                    ->values()
                    ->all(),
                'offer_application_ids' => $offers
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->take(24)
                    ->values()
                    ->all(),
                'next_interview' => is_array($nextInterview)
                    ? $this->eventFact($nextInterview)
                    : null,
                'review_due' => is_array($reviewDue)
                    ? $this->eventFact($reviewDue)
                    : null,
                'result_waiting_event_ids' => $resultWaiting
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->take(24)
                    ->values()
                    ->all(),
            ],
            evidenceReferences: $references,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function eventFact(array $event): array
    {
        return [
            'event_id' => $this->intOrNull($event['id'] ?? null),
            'application_id' => $this->intOrNull(
                $event['application_id'] ?? null,
            ),
            'task_id' => $this->intOrNull($event['task_id'] ?? null),
            'stage' => $this->stringOrNull($event['stage'] ?? null),
            'scheduled_at' => $this->stringOrNull(
                $event['scheduled_at'] ?? null,
            ),
        ];
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

    private function dateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function intOrNull(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return $value === false ? null : (int) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 191);
    }
}
