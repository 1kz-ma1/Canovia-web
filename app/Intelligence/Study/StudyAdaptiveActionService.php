<?php

namespace App\Intelligence\Study;

use App\Intelligence\Data\StudyAdaptiveActionResult;
use App\Intelligence\Services\ActionProjectionStore;
use App\Intelligence\Services\DecisionTraceStore;
use App\Models\Plan;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

final class StudyAdaptiveActionService
{
    public function __construct(
        private readonly StudyPlanIntelligenceService $studyIntelligence,
        private readonly StudyAdaptiveDecisionEngine $decisionEngine,
        private readonly StudyAdaptiveActionGenerator $actionGenerator,
        private readonly DecisionTraceStore $decisionTraceStore,
        private readonly ActionProjectionStore $actionProjectionStore,
    ) {}

    public function evaluate(
        Plan $plan,
        ?DateTimeInterface $capturedAt = null,
        bool $persist = false,
    ): StudyAdaptiveActionResult {
        $intelligence = $this->studyIntelligence->evaluate(
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
                    'engine' => StudyAdaptiveDecisionEngine::class,
                    'engine_version' => '53.6',
                    'policy_version' => 'study_adaptive_action_v1',
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

        return new StudyAdaptiveActionResult(
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
    ): StudyAdaptiveActionResult {
        return $this->evaluate($plan, $capturedAt, true);
    }

    public function tryRefresh(
        Plan $plan,
        ?DateTimeInterface $capturedAt = null,
    ): ?StudyAdaptiveActionResult {
        try {
            return $this->refresh($plan, $capturedAt);
        } catch (Throwable $exception) {
            Log::warning('Study Adaptive Action refresh failed.', [
                'plan_id' => (int) $plan->id,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }
}
