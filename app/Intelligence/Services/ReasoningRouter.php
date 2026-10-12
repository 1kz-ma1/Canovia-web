<?php

namespace App\Intelligence\Services;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Intelligence\Data\Confidence;
use App\Intelligence\Data\Decision;
use App\Intelligence\Data\DecisionCandidate;
use App\Intelligence\Data\ReasoningProviderSelection;
use App\Intelligence\Data\ReasoningRequest;
use App\Intelligence\Data\RoutedDecisionResult;
use App\Intelligence\Providers\OpenAiDecisionReasoningProvider;
use App\Intelligence\Support\CanonicalJson;
use App\Models\IntelligenceReasoningRun;
use App\Models\Plan;
use App\Models\User;
use App\Services\FeatureAccessService;
use Throwable;

final class ReasoningRouter
{
    public function __construct(
        private readonly FeatureAccessService $featureAccess,
        private readonly OpenAiDecisionReasoningProvider $openAi,
        private readonly ReasoningRunStore $runStore,
    ) {}

    public function route(
        ReasoningRequest $request,
        array $context = [],
    ): RoutedDecisionResult {
        $mode = $this->mode($context['mode'] ?? null);
        $routerVersion = (string) config(
            'intelligence.reasoning.router_version',
            '53.3',
        );

        $requestFingerprint = $this->requestFingerprint(
            $request,
            $mode,
            $routerVersion,
        );

        $cached = $this->runStore->cachedSuccess($requestFingerprint);
        if ($cached) {
            // A cached OpenAI selection is still derived from an access-
            // controlled source. Do not bypass CURRENT actor/Plan/entitlement
            // just because this exact request succeeded in the past.
            if ($cached->route_selected === 'openai') {
                $denyReason = $this->aiAuthorizationFailure($request);
                if ($denyReason !== null) {
                    return $this->recordFallback(
                        request: $request,
                        requestFingerprint: $requestFingerprint,
                        mode: $mode,
                        started: hrtime(true),
                        reason: $denyReason,
                        routerVersion: $routerVersion,
                    );
                }
            }

            $decision = $this->decisionFromCachedRun($request, $cached);

            return new RoutedDecisionResult(
                decision: $decision,
                baselineDecision: $request->baselineDecision,
                reasoningRun: $cached,
                agreesWithBaseline: $decision->type === $request->baselineDecision->type,
                usedFallback: (bool) $cached->used_fallback,
            );
        }

        $started = hrtime(true);

        if ($mode === 'deterministic') {
            return $this->recordDeterministic(
                request: $request,
                requestFingerprint: $requestFingerprint,
                mode: $mode,
                started: $started,
                routeReason: 'mode_deterministic',
                routerVersion: $routerVersion,
            );
        }

        if ($mode === 'auto' && ! $this->shouldEscalate($request)) {
            return $this->recordDeterministic(
                request: $request,
                requestFingerprint: $requestFingerprint,
                mode: $mode,
                started: $started,
                routeReason: 'baseline_sufficient',
                routerVersion: $routerVersion,
            );
        }

        $denyReason = $this->aiAuthorizationFailure($request);
        if ($denyReason !== null) {
            return $this->recordFallback(
                request: $request,
                requestFingerprint: $requestFingerprint,
                mode: $mode,
                started: $started,
                reason: $denyReason,
                routerVersion: $routerVersion,
            );
        }

        if (! $this->openAi->isAvailable()) {
            return $this->recordFallback(
                request: $request,
                requestFingerprint: $requestFingerprint,
                mode: $mode,
                started: $started,
                reason: 'provider_unavailable',
                routerVersion: $routerVersion,
            );
        }

        try {
            $selection = $this->openAi->select($request);

            // A remote response may arrive AFTER an admin revokes a product
            // grant or the Plan is transferred. Never materialize that old AI
            // selection as a current V53 Decision. The remote request itself
            // is already completed and cannot be retroactively unsent.
            $denyReason = $this->aiAuthorizationFailure($request);
            if ($denyReason !== null) {
                return $this->recordFallback(
                    request: $request,
                    requestFingerprint: $requestFingerprint,
                    mode: $mode,
                    started: $started,
                    reason: 'authorization_changed_during_reasoning',
                    routerVersion: $routerVersion,
                );
            }

            $candidate = $this->candidateByType(
                $request->candidates,
                $selection->selectedType,
            );

            if (! $candidate) {
                throw new NativeAiExecutionException(
                    'Native AI selected an unknown Decision candidate.',
                    'reasoning_candidate_mismatch',
                    $selection->nativeAiRunId,
                );
            }

            $decision = $this->decisionFromSelection(
                $request,
                $candidate,
                $selection,
            );

            $run = $this->runStore->record(
                request: $request,
                requestFingerprint: $requestFingerprint,
                modeRequested: $mode,
                routeSelected: 'openai',
                selectedDecision: $decision,
                providerSelection: $selection,
                latencyMs: $this->latencyMs($started),
                metadata: [
                    'router_version' => $routerVersion,
                    'route_reason' => 'provider_selected',
                    'escalation_reason' => $mode === 'auto'
                        ? 'baseline_uncertain'
                        : 'mode_openai',
                ],
            );

            return new RoutedDecisionResult(
                decision: $decision,
                baselineDecision: $request->baselineDecision,
                reasoningRun: $run,
                agreesWithBaseline: $decision->type === $request->baselineDecision->type,
                usedFallback: false,
            );
        } catch (NativeAiExecutionException $exception) {
            // The central Native AI fence can observe the same mid-request
            // revoke earlier than this Router's post-response check. Preserve
            // V53's stable authorization-change fallback contract.
            $changedPermission = in_array($exception->errorCode, [
                'native_ai_actor_unverified',
                'native_ai_target_changed',
                'native_ai_purpose_not_entitled',
                'native_ai_authorization_unavailable',
            ], true);

            return $this->recordFallback(
                request: $request,
                requestFingerprint: $requestFingerprint,
                mode: $mode,
                started: $started,
                reason: $changedPermission
                    ? 'authorization_changed_during_reasoning'
                    : $exception->errorCode,
                routerVersion: $routerVersion,
            );
        } catch (Throwable) {
            return $this->recordFallback(
                request: $request,
                requestFingerprint: $requestFingerprint,
                mode: $mode,
                started: $started,
                reason: 'reasoning_provider_unexpected_error',
                routerVersion: $routerVersion,
            );
        }
    }

    private function recordDeterministic(
        ReasoningRequest $request,
        string $requestFingerprint,
        string $mode,
        int $started,
        string $routeReason,
        string $routerVersion,
    ): RoutedDecisionResult {
        $run = $this->runStore->record(
            request: $request,
            requestFingerprint: $requestFingerprint,
            modeRequested: $mode,
            routeSelected: 'deterministic',
            selectedDecision: $request->baselineDecision,
            providerSelection: null,
            latencyMs: $this->latencyMs($started),
            metadata: [
                'router_version' => $routerVersion,
                'route_reason' => $routeReason,
            ],
        );

        return new RoutedDecisionResult(
            decision: $request->baselineDecision,
            baselineDecision: $request->baselineDecision,
            reasoningRun: $run,
            agreesWithBaseline: true,
            usedFallback: false,
        );
    }

    private function recordFallback(
        ReasoningRequest $request,
        string $requestFingerprint,
        string $mode,
        int $started,
        string $reason,
        string $routerVersion,
    ): RoutedDecisionResult {
        $run = $this->runStore->record(
            request: $request,
            requestFingerprint: $requestFingerprint,
            modeRequested: $mode,
            routeSelected: 'deterministic',
            selectedDecision: $request->baselineDecision,
            providerSelection: null,
            latencyMs: $this->latencyMs($started),
            usedFallback: true,
            fallbackReason: $reason,
            status: 'fallback',
            metadata: [
                'router_version' => $routerVersion,
                'route_reason' => 'provider_fallback',
                'escalation_reason' => $mode === 'auto'
                    ? 'baseline_uncertain'
                    : 'mode_openai',
            ],
        );

        return new RoutedDecisionResult(
            decision: $request->baselineDecision,
            baselineDecision: $request->baselineDecision,
            reasoningRun: $run,
            agreesWithBaseline: true,
            usedFallback: true,
        );
    }

    private function decisionFromSelection(
        ReasoningRequest $request,
        DecisionCandidate $candidate,
        ReasoningProviderSelection $selection,
    ): Decision {
        $confidence = new Confidence(
            round(
                min(
                    $candidate->confidence->value,
                    $selection->confidence->value,
                ),
                4,
            ),
        );

        return new Decision(
            type: $candidate->type,
            reasonCode: $candidate->reasonCode,
            summary: $candidate->summary,
            confidence: $confidence,
            inputFingerprint: $request->baselineDecision->inputFingerprint,
            reasons: $candidate->reasons,
            metadata: [
                ...$candidate->metadata,
                'policy_version' => 'reasoning_router_v1',
            ],
        );
    }

    private function decisionFromCachedRun(
        ReasoningRequest $request,
        IntelligenceReasoningRun $cached,
    ): Decision {
        $candidate = $this->candidateByType(
            $request->candidates,
            $cached->selected_decision_type,
        );

        if (! $candidate) {
            return $request->baselineDecision;
        }

        return new Decision(
            type: $candidate->type,
            reasonCode: $candidate->reasonCode,
            summary: $candidate->summary,
            confidence: new Confidence(
                max(0, min(1, (float) $cached->selected_confidence)),
            ),
            inputFingerprint: $request->baselineDecision->inputFingerprint,
            reasons: $candidate->reasons,
            metadata: [
                ...$candidate->metadata,
                'policy_version' => 'reasoning_router_v1',
            ],
        );
    }

    /**
     * Re-read actor, Plan scope and native AI entitlement at each point where
     * an AI decision could become current (including cached success).
     * A queued evaluation has no authorization to use a former owner's Plan.
     *
     * This is not ChatGPT OAuth consent: MCP external disclosure is governed
     * separately by McpDelegatedPlanAccessPolicy and its locked read path.
     */
    private function aiAuthorizationFailure(ReasoningRequest $request): ?string
    {
        try {
            $actor = $request->userId !== null && $request->userId > 0
                ? User::query()->find($request->userId)
                : null;

            if ($actor === null) {
                return 'feature_not_entitled';
            }

            if ($request->planId !== null) {
                $plan = Plan::query()->find($request->planId);
                if ($plan === null
                    || (int) $plan->user_id !== (int) $actor->id
                    || (bool) $plan->is_collaborative) {
                    return 'plan_access_changed';
                }
            }

            return $this->featureAccess->canUse(
                $actor,
                FeatureKey::AutomaticAiExecution,
            ) ? null : 'feature_not_entitled';
        } catch (Throwable) {
            // A failed policy read cannot release a cached private AI choice.
            return 'reasoning_authorization_unavailable';
        }
    }

    private function shouldEscalate(ReasoningRequest $request): bool
    {
        $maxConfidence = max(
            0,
            min(
                1,
                (float) config(
                    'intelligence.reasoning.auto.max_baseline_confidence',
                    0.70,
                ),
            ),
        );

        $minCandidates = max(
            2,
            (int) config(
                'intelligence.reasoning.auto.min_candidates',
                2,
            ),
        );

        return count($request->candidates) >= $minCandidates
            && $request->baselineDecision->confidence->value <= $maxConfidence;
    }

    /**
     * @param array<int,DecisionCandidate> $candidates
     */
    private function candidateByType(array $candidates, string $type): ?DecisionCandidate
    {
        foreach ($candidates as $candidate) {
            if ($candidate instanceof DecisionCandidate && $candidate->type === $type) {
                return $candidate;
            }
        }

        return null;
    }

    private function requestFingerprint(
        ReasoningRequest $request,
        string $mode,
        string $routerVersion,
    ): string {
        return hash('sha256', CanonicalJson::encode([
            'user_id' => $request->userId,
            'plan_id' => $request->planId,
            'input_fingerprint' => $request->baselineDecision->inputFingerprint,
            'baseline' => [
                'type' => $request->baselineDecision->type,
                'reason_code' => $request->baselineDecision->reasonCode,
                'confidence' => $request->baselineDecision->confidence->value,
            ],
            'candidates' => collect($request->candidates)
                ->map(fn (DecisionCandidate $candidate) => [
                    'type' => $candidate->type,
                    'reason_code' => $candidate->reasonCode,
                    'priority' => $candidate->priority,
                    'confidence' => $candidate->confidence->value,
                    'reasons' => $candidate->reasons,
                    'metadata' => $candidate->metadata,
                ])
                ->sortBy('type')
                ->values()
                ->all(),
            'mode' => $mode,
            'router_version' => $routerVersion,
            'native_ai_driver' => config('native_ai.driver'),
            'native_ai_model' => config('native_ai.providers.openai.model'),
        ]));
    }

    private function mode(mixed $value): string
    {
        $mode = trim((string) ($value ?: config(
            'intelligence.reasoning.mode',
            'deterministic',
        )));

        return in_array($mode, ['deterministic', 'auto', 'openai'], true)
            ? $mode
            : 'deterministic';
    }

    private function latencyMs(int $started): int
    {
        return (int) max(
            0,
            round((hrtime(true) - $started) / 1_000_000),
        );
    }
}
