<?php

namespace App\Intelligence\Services;

use App\Intelligence\Data\Decision;
use App\Intelligence\Data\ReasoningProviderSelection;
use App\Intelligence\Data\ReasoningRequest;
use App\Intelligence\Support\IntelligenceFingerprint;
use App\Models\IntelligenceDecisionTrace;
use App\Models\IntelligenceReasoningRun;
use App\Models\NativeAiRun;
use Illuminate\Support\Arr;

final class ReasoningRunStore
{
    public function __construct(
        private readonly ReasoningCostEstimator $costEstimator,
    ) {}

    public function cachedSuccess(string $requestFingerprint): ?IntelligenceReasoningRun
    {
        return IntelligenceReasoningRun::query()
            ->where('request_fingerprint', $requestFingerprint)
            ->where('status', 'succeeded')
            ->latest('id')
            ->first();
    }

    public function record(
        ReasoningRequest $request,
        string $requestFingerprint,
        string $modeRequested,
        string $routeSelected,
        Decision $selectedDecision,
        ?ReasoningProviderSelection $providerSelection,
        int $latencyMs,
        bool $usedFallback = false,
        ?string $fallbackReason = null,
        string $status = 'succeeded',
        array $metadata = [],
    ): IntelligenceReasoningRun {
        $nativeRun = $providerSelection?->nativeAiRunId
            ? NativeAiRun::query()->find($providerSelection->nativeAiRunId)
            : null;

        $estimatedCost = $this->costEstimator->estimateUsd(
            $nativeRun?->input_tokens,
            $nativeRun?->output_tokens,
        );

        return IntelligenceReasoningRun::query()->create([
            'user_id' => $request->userId,
            'plan_id' => $request->planId,
            'native_ai_run_id' => $providerSelection?->nativeAiRunId,
            'domain' => $request->state->domain->value,
            'scope_type' => $request->state->scopeType,
            'scope_id' => $request->state->scopeId !== null
                ? (string) $request->state->scopeId
                : null,
            'state_reference' => IntelligenceFingerprint::stateReference($request->state),
            'readiness_fingerprint' => IntelligenceFingerprint::readiness($request->readiness),
            'input_fingerprint' => $request->baselineDecision->inputFingerprint,
            'request_fingerprint' => $requestFingerprint,
            'mode_requested' => $modeRequested,
            'route_selected' => $routeSelected,
            'provider' => $providerSelection?->provider,
            'model' => $providerSelection?->model,
            'status' => $status,
            'baseline_decision_type' => $request->baselineDecision->type,
            'selected_decision_type' => $selectedDecision->type,
            'baseline_confidence' => $request->baselineDecision->confidence->value,
            'selected_confidence' => $selectedDecision->confidence->value,
            'agrees_with_baseline' => $selectedDecision->type === $request->baselineDecision->type,
            'used_fallback' => $usedFallback,
            'fallback_reason' => $fallbackReason
                ? mb_substr($fallbackReason, 0, 80)
                : null,
            'candidate_count' => count($request->candidates),
            'latency_ms' => max(0, $latencyMs),
            'input_tokens' => $nativeRun?->input_tokens,
            'output_tokens' => $nativeRun?->output_tokens,
            'total_tokens' => $nativeRun?->total_tokens,
            'estimated_cost_usd' => $estimatedCost,
            'metadata' => Arr::only($metadata, [
                'router_version',
                'route_reason',
                'escalation_reason',
                'reused_cached_reasoning',
            ]),
        ]);
    }

    public function attachDecisionTrace(
        IntelligenceReasoningRun $reasoningRun,
        IntelligenceDecisionTrace $trace,
    ): void {
        if ($reasoningRun->intelligence_decision_trace_id === $trace->id) {
            return;
        }

        $reasoningRun->update([
            'intelligence_decision_trace_id' => $trace->id,
        ]);
    }
}
