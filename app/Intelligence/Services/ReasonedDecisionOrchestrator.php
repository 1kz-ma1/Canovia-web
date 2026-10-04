<?php

namespace App\Intelligence\Services;

use App\Intelligence\Contracts\CandidateDecisionEngine;
use App\Intelligence\Contracts\ReadinessEvaluator;
use App\Intelligence\Data\ReasonedDecisionEvaluationResult;
use App\Intelligence\Data\ReasoningRequest;
use App\Intelligence\Data\StateSnapshot;

final class ReasonedDecisionOrchestrator
{
    public function __construct(
        private readonly ReasoningRouter $router,
        private readonly ReasoningRunStore $reasoningRunStore,
        private readonly DecisionTraceStore $decisionTraceStore,
    ) {}

    public function evaluateAndPersist(
        StateSnapshot $state,
        ReadinessEvaluator $readinessEvaluator,
        CandidateDecisionEngine $decisionEngine,
        ?int $userId = null,
        ?int $planId = null,
        array $context = [],
        array $metadata = [],
    ): ReasonedDecisionEvaluationResult {
        $readiness = $readinessEvaluator->evaluate($state);
        $candidates = $decisionEngine->candidates(
            $state,
            $readiness,
            $context,
        );
        $baseline = $decisionEngine->decide(
            $state,
            $readiness,
            $context,
        );

        $routed = $this->router->route(
            new ReasoningRequest(
                state: $state,
                readiness: $readiness,
                candidates: $candidates,
                baselineDecision: $baseline,
                userId: $userId,
                planId: $planId,
            ),
            $context,
        );

        $trace = $this->decisionTraceStore->persist(
            $state,
            $readiness,
            $routed->decision,
            $userId,
            $planId,
            [
                'engine' => $decisionEngine::class,
                'engine_version' => $metadata['engine_version'] ?? null,
                'policy_version' => $routed->decision->metadata['policy_version']
                    ?? $baseline->metadata['policy_version']
                    ?? null,
            ],
        );

        $this->reasoningRunStore->attachDecisionTrace(
            $routed->reasoningRun,
            $trace,
        );

        return new ReasonedDecisionEvaluationResult(
            readiness: $readiness,
            baselineDecision: $baseline,
            decision: $routed->decision,
            trace: $trace,
            reasoningRun: $routed->reasoningRun->fresh(),
        );
    }
}
