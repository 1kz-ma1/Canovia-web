<?php

namespace App\Intelligence\Development;

use App\Intelligence\Data\DevelopmentIntelligenceResult;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Services\StateSnapshotStore;
use App\Models\Plan;
use App\Services\PlanCategoryProfileService;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class DevelopmentPlanIntelligenceService
{
    public function __construct(
        private readonly DevelopmentEvidenceCollector $evidenceCollector,
        private readonly DevelopmentStateBuilder $stateBuilder,
        private readonly DevelopmentReleaseReadinessEvaluator $readinessEvaluator,
        private readonly StateSnapshotStore $stateStore,
        private readonly PlanCategoryProfileService $profiles,
    ) {}

    public function evaluate(
        Plan $plan,
        ?DateTimeInterface $capturedAt = null,
        bool $persist = false,
    ): DevelopmentIntelligenceResult {
        if ($this->profiles->forPlan($plan)->key !== 'development') {
            throw new InvalidArgumentException(
                'Development Intelligence requires a development Plan.',
            );
        }

        $evidence = $this->evidenceCollector->collect($plan);

        $state = $this->stateBuilder->build(
            IntelligenceDomain::Development,
            [
                'scope_type' => 'development_plan',
                'scope_id' => (int) $plan->id,
                'captured_at' => $capturedAt
                    ? DateTimeImmutable::createFromInterface($capturedAt)
                    : new DateTimeImmutable(),
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
                    'builder' => DevelopmentStateBuilder::class,
                    'builder_version' => '53.8',
                ],
            );
        }

        return new DevelopmentIntelligenceResult(
            state: $state,
            readiness: $readiness,
            persistedSnapshot: $snapshot,
        );
    }

    public function persistSnapshot(
        Plan $plan,
        ?DateTimeInterface $capturedAt = null,
    ): DevelopmentIntelligenceResult {
        return $this->evaluate($plan, $capturedAt, true);
    }

    public function tryPersistSnapshot(
        Plan $plan,
        ?DateTimeInterface $capturedAt = null,
    ): ?DevelopmentIntelligenceResult {
        try {
            return $this->persistSnapshot($plan, $capturedAt);
        } catch (Throwable $exception) {
            Log::warning('Development Intelligence snapshot projection failed.', [
                'plan_id' => (int) $plan->id,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }
}
