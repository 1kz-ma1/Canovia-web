<?php

namespace App\Intelligence\Services;

use App\Intelligence\Contracts\CandidateDecisionEngine;
use App\Intelligence\Contracts\ReadinessEvaluator;
use App\Intelligence\Data\ReasonedDecisionEvaluationResult;
use App\Intelligence\Data\ReasoningRequest;
use App\Intelligence\Data\StateSnapshot;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

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

        $request = new ReasoningRequest(
            state: $state,
            readiness: $readiness,
            candidates: $candidates,
            baselineDecision: $baseline,
            userId: $userId,
            planId: $planId,
        );
        $routed = $this->router->route($request, $context);

        // Router and Native AI Gateway guard their own disclosure paths,
        // but grant/Plan revocation may occur AFTER router returns and BEFORE
        // the materialized Decision is committed. Recheck at the final write.
        // Lock the Plan across the authorization read and trace association.
        $trace = DB::transaction(function () use (
            $state, $readiness, $baseline, $routed, $request,
            $userId, $planId, $decisionEngine, $metadata,
        ) {
            if ($routed->reasoningRun->route_selected === 'openai') {
                if ($planId !== null) {
                    $livePlan = Plan::query()->whereKey($planId)
                        ->lockForUpdate()->first();
                    abort_unless(
                        $livePlan !== null
                            && $userId !== null
                            && (int) $livePlan->user_id === $userId
                            && ! $livePlan->is_collaborative,
                        409,
                        'Decision保存前にPlanのアクセス条件が変更されました。',
                    );
                }

                abort_unless(
                    $this->router->aiAuthorizationFailure($request) === null,
                    409,
                    'Decision保存前にNative AIの利用権限が変更されました。',
                );
            }

            $stored = $this->decisionTraceStore->persist(
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
                $stored,
            );

            return $stored;
        }, 3);

        return new ReasonedDecisionEvaluationResult(
            readiness: $readiness,
            baselineDecision: $baseline,
            decision: $routed->decision,
            trace: $trace,
            reasoningRun: $routed->reasoningRun->fresh(),
        );
    }
}
