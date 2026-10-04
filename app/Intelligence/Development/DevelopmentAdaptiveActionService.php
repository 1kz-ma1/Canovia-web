<?php

namespace App\Intelligence\Development;

use App\Intelligence\Data\DevelopmentAdaptiveActionResult;
use App\Intelligence\Services\ActionProjectionStore;
use App\Intelligence\Services\DecisionTraceStore;
use App\Models\Plan;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DevelopmentAdaptiveActionService
{
    public function __construct(
        private readonly DevelopmentPlanIntelligenceService $intelligence,
        private readonly DevelopmentDecisionEngine $decisionEngine,
        private readonly DevelopmentActionGenerator $actionGenerator,
        private readonly DecisionTraceStore $decisionTraceStore,
        private readonly ActionProjectionStore $actionProjectionStore,
    ) {}

    public function evaluate(
        Plan $plan,
        ?DateTimeInterface $capturedAt = null,
        bool $persist = false,
    ): DevelopmentAdaptiveActionResult {
        $intelligence = $this->intelligence->evaluate(
            $plan,
            $capturedAt,
            $persist,
        );

        $decision = $this->decisionEngine->decide(
            $intelligence->state,
            $intelligence->readiness,
        );

        $actions = $this->actionGenerator->generate(
            $intelligence->state,
            $intelligence->readiness,
            $decision,
        );

        $trace = null;
        $projection = null;

        if ($persist) {
            $trace = $this->decisionTraceStore->persist(
                $intelligence->state,
                $intelligence->readiness,
                $decision,
                $plan->user_id ? (int) $plan->user_id : null,
                (int) $plan->id,
                [
                    'engine' => DevelopmentDecisionEngine::class,
                    'engine_version' => '53.8',
                    'policy_version' => 'development_release_action_v1',
                ],
            );

            if (isset($actions[0])) {
                $projection = $this->actionProjectionStore->persist(
                    $intelligence->state,
                    $decision,
                    $actions[0],
                    $trace,
                    $plan->user_id ? (int) $plan->user_id : null,
                    (int) $plan->id,
                );
            }
        }

        return new DevelopmentAdaptiveActionResult(
            intelligence: $intelligence,
            decision: $decision,
            actions: $actions,
            decisionTrace: $trace,
            projection: $projection,
        );
    }

    public function refresh(
        Plan $plan,
        ?DateTimeInterface $capturedAt = null,
    ): DevelopmentAdaptiveActionResult {
        return $this->evaluate($plan, $capturedAt, true);
    }

    public function tryRefresh(
        Plan $plan,
        ?DateTimeInterface $capturedAt = null,
    ): ?DevelopmentAdaptiveActionResult {
        try {
            return $this->refresh($plan, $capturedAt);
        } catch (Throwable $exception) {
            Log::warning('Development Adaptive Action refresh failed.', [
                'plan_id' => (int) $plan->id,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }
}
