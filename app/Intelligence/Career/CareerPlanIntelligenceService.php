<?php

namespace App\Intelligence\Career;

use App\Intelligence\Data\CareerIntelligenceResult;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Services\StateSnapshotStore;
use App\Models\CareerApplication;
use App\Models\CareerCapture;
use App\Models\CareerSelectionEvent;
use App\Models\Plan;
use App\Services\PlanCategoryProfileService;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class CareerPlanIntelligenceService
{
    public function __construct(
        private readonly CareerEvidenceCollector $evidenceCollector,
        private readonly CareerStateBuilder $stateBuilder,
        private readonly CareerProcessReadinessEvaluator $readinessEvaluator,
        private readonly StateSnapshotStore $stateStore,
        private readonly PlanCategoryProfileService $profiles,
    ) {}

    public function evaluate(
        Plan $plan,
        ?DateTimeInterface $capturedAt = null,
        bool $persist = false,
    ): CareerIntelligenceResult {
        if ($this->profiles->forPlan($plan)->key !== 'career') {
            throw new InvalidArgumentException(
                'Career Intelligence requires a career Plan.',
            );
        }

        $captures = CareerCapture::query()
            ->select([
                'id',
                'plan_id',
                'career_application_id',
                'status',
                'captured_at',
            ])
            ->where('plan_id', $plan->id)
            ->orderBy('captured_at')
            ->orderBy('id')
            ->get();

        $applications = CareerApplication::query()
            ->select([
                'id',
                'plan_id',
                'stage',
                'status',
                'result',
                'next_event_at',
            ])
            ->where('plan_id', $plan->id)
            ->orderBy('id')
            ->get();

        $events = CareerSelectionEvent::query()
            ->select([
                'id',
                'career_application_id',
                'task_id',
                'type',
                'stage',
                'status',
                'scheduled_at',
                'completed_at',
                'result',
            ])
            ->whereHas(
                'application',
                fn ($query) =>
                    $query->where('plan_id', $plan->id),
            )
            ->with([
                'interviewReview:id,career_selection_event_id,status',
            ])
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();

        $evidence = $this->evidenceCollector->collect($plan);
        $captured = $capturedAt
            ? DateTimeImmutable::createFromInterface($capturedAt)
            : new DateTimeImmutable();

        $state = $this->stateBuilder->build(
            IntelligenceDomain::Career,
            [
                'scope_type' => 'career_plan',
                'scope_id' => (int) $plan->id,
                'captured_at' => $captured,
                'captures' => $captures
                    ->map(fn (CareerCapture $capture) => [
                        'id' => (int) $capture->id,
                        'application_id' =>
                            $capture->career_application_id
                                ? (int) $capture->career_application_id
                                : null,
                        'status' => in_array(
                            (string) $capture->status,
                            CareerCapture::STATUSES,
                            true,
                        )
                            ? (string) $capture->status
                            : null,
                        'captured_at' =>
                            $capture->captured_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
                'applications' => $applications
                    ->map(fn (CareerApplication $application) => [
                        'id' => (int) $application->id,
                        'stage' => in_array(
                            (string) $application->stage,
                            CareerApplication::STAGES,
                            true,
                        )
                            ? (string) $application->stage
                            : null,
                        'status' => in_array(
                            (string) $application->status,
                            CareerApplication::STATUSES,
                            true,
                        )
                            ? (string) $application->status
                            : null,
                        'result' => in_array(
                            (string) $application->result,
                            [
                                'passed',
                                'rejected',
                                'offer',
                                'withdrawn',
                            ],
                            true,
                        )
                            ? (string) $application->result
                            : null,
                        'next_event_at' =>
                            $application->next_event_at
                                ?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
                'selection_events' => $events
                    ->map(fn (CareerSelectionEvent $event) => [
                        'id' => (int) $event->id,
                        'application_id' =>
                            (int) $event->career_application_id,
                        'task_id' => $event->task_id
                            ? (int) $event->task_id
                            : null,
                        'type' => in_array(
                            (string) $event->type,
                            CareerSelectionEvent::TYPES,
                            true,
                        )
                            ? (string) $event->type
                            : null,
                        'stage' => in_array(
                            (string) $event->stage,
                            [
                                'interview',
                                'final_interview',
                                'screening',
                            ],
                            true,
                        )
                            ? (string) $event->stage
                            : null,
                        'status' => in_array(
                            (string) $event->status,
                            CareerSelectionEvent::STATUSES,
                            true,
                        )
                            ? (string) $event->status
                            : null,
                        'scheduled_at' =>
                            $event->scheduled_at?->toIso8601String(),
                        'completed_at' =>
                            $event->completed_at?->toIso8601String(),
                        'result' => in_array(
                            (string) $event->result,
                            [
                                'passed',
                                'rejected',
                                'offer',
                                'withdrawn',
                            ],
                            true,
                        )
                            ? (string) $event->result
                            : null,
                        'review_id' => $event->interviewReview?->id
                            ? (int) $event->interviewReview->id
                            : null,
                        'review_status' =>
                            $event->interviewReview?->status === 'completed'
                                ? 'completed'
                                : (
                                    $event->interviewReview?->status === 'draft'
                                        ? 'draft'
                                        : null
                                ),
                    ])
                    ->values()
                    ->all(),
            ],
            $evidence,
        );

        $readiness = $this->readinessEvaluator->evaluate($state);

        $snapshot = null;
        if ($persist) {
            $snapshot = $this->stateStore->persist(
                $state,
                $plan->user_id ? (int) $plan->user_id : null,
                (int) $plan->id,
                [
                    'schema_version' => '1.0',
                    'builder' => CareerStateBuilder::class,
                    'builder_version' => '55.0',
                ],
            );
        }

        return new CareerIntelligenceResult(
            state: $state,
            readiness: $readiness,
            persistedSnapshot: $snapshot,
        );
    }

    public function persistSnapshot(
        Plan $plan,
        ?DateTimeInterface $capturedAt = null,
    ): CareerIntelligenceResult {
        return $this->evaluate($plan, $capturedAt, true);
    }

    public function tryPersistSnapshot(
        Plan $plan,
        ?DateTimeInterface $capturedAt = null,
    ): ?CareerIntelligenceResult {
        try {
            return $this->persistSnapshot($plan, $capturedAt);
        } catch (Throwable $exception) {
            Log::warning(
                'Career Intelligence snapshot projection failed.',
                [
                    'plan_id' => (int) $plan->id,
                    'exception' => $exception::class,
                ],
            );

            return null;
        }
    }
}
