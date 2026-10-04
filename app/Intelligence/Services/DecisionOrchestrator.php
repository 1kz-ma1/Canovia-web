<?php

namespace App\Intelligence\Services;

use App\Intelligence\Contracts\DecisionEngine;
use App\Intelligence\Contracts\ReadinessEvaluator;
use App\Intelligence\Data\DecisionEvaluationResult;
use App\Intelligence\Data\StateSnapshot;

final class DecisionOrchestrator
{
    public function __construct(
        private readonly DecisionTraceStore $traceStore,
    ) {}

    public function evaluateAndPersist(
        StateSnapshot $state,
        ReadinessEvaluator $readinessEvaluator,
        DecisionEngine $decisionEngine,
        ?int $userId = null,
        ?int $planId = null,
        array $context = [],
        array $metadata = [],
    ): DecisionEvaluationResult {
        $readiness = $readinessEvaluator->evaluate($state);
        $decision = $decisionEngine->decide($state, $readiness, $context);

        $trace = $this->traceStore->persist(
            $state,
            $readiness,
            $decision,
            $userId,
            $planId,
            [
                'engine' => $decisionEngine::class,
                'engine_version' => $metadata['engine_version'] ?? null,
                'policy_version' => $decision->metadata['policy_version'] ?? null,
            ],
        );

        return new DecisionEvaluationResult(
            readiness: $readiness,
            decision: $decision,
            trace: $trace,
        );
    }
}
