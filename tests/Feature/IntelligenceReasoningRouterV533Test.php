<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Intelligence\Data\ReasoningRequest;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Providers\OpenAiDecisionReasoningProvider;
use App\Intelligence\Services\ReasonedDecisionOrchestrator;
use App\Intelligence\Study\StudyDecisionEngine;
use App\Intelligence\Study\StudyReadinessEvaluator;
use App\Models\IntelligenceReasoningRun;
use App\Models\NativeAiRun;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserProductGrant;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntelligenceReasoningRouterV533Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'openai',
            'native_ai.providers.openai.base_url' => 'https://api.openai.com/v1',
            'native_ai.providers.openai.api_key' => 'test-key',
            'native_ai.providers.openai.model' => 'gpt-5.6-luna',
            'intelligence.reasoning.mode' => 'deterministic',
            'intelligence.reasoning.router_version' => '53.3',
            'intelligence.reasoning.openai.max_output_tokens' => 700,
            'intelligence.reasoning.auto.max_baseline_confidence' => 0.70,
            'intelligence.reasoning.auto.min_candidates' => 2,
            'intelligence.reasoning.cost.input_usd_per_million_tokens' => 2,
            'intelligence.reasoning.cost.output_usd_per_million_tokens' => 8,
        ]);
    }

    public function test_deterministic_mode_never_calls_openai_and_persists_observability(): void
    {
        [$user, $plan] = $this->context();
        Http::fake();

        $result = $this->evaluate(
            $this->uncertainStudyState(),
            $user,
            $plan,
            ['mode' => 'deterministic'],
        );

        $this->assertSame(
            $result->baselineDecision->type,
            $result->decision->type,
        );
        $this->assertSame('deterministic', $result->reasoningRun->route_selected);
        $this->assertSame('succeeded', $result->reasoningRun->status);
        $this->assertFalse($result->reasoningRun->used_fallback);
        $this->assertTrue($result->reasoningRun->agrees_with_baseline);
        $this->assertSame($result->trace->id, $result->reasoningRun->intelligence_decision_trace_id);

        Http::assertNothingSent();
        $this->assertDatabaseCount('native_ai_runs', 0);
        $this->assertDatabaseCount('intelligence_reasoning_runs', 1);
    }

    public function test_auto_mode_keeps_high_confidence_baseline_without_model_cost(): void
    {
        [$user, $plan] = $this->context();
        $this->grantPremium($user);
        Http::fake();

        $state = new StateSnapshot(
            domain: IntelligenceDomain::Study,
            scopeType: 'plan',
            scopeId: $plan->id,
            capturedAt: new DateTimeImmutable('2026-10-04T12:00:00+09:00'),
            metrics: [
                'evidence_count' => 4,
                'practice_attempt_count' => 3,
                'recall_review_count' => 1,
                'latest_score_percent' => 88,
                'average_score_percent' => 85,
                'best_score_percent' => 92,
            ],
            facts: [
                'has_practice_evidence' => true,
                'has_recall_evidence' => true,
                'observed_strengths' => ['network'],
                'observed_weaknesses' => [],
            ],
        );

        $result = $this->evaluate(
            $state,
            $user,
            $plan,
            ['mode' => 'auto'],
        );

        $this->assertSame('advance_scope', $result->decision->type);
        $this->assertSame('deterministic', $result->reasoningRun->route_selected);
        $this->assertSame('baseline_sufficient', data_get($result->reasoningRun->metadata, 'route_reason'));

        Http::assertNothingSent();
        $this->assertDatabaseCount('native_ai_runs', 0);
    }

    public function test_free_user_cannot_spend_native_ai_reasoning_cost(): void
    {
        [$user, $plan] = $this->context();
        Http::fake();

        $result = $this->evaluate(
            $this->uncertainStudyState($plan->id),
            $user,
            $plan,
            ['mode' => 'openai'],
        );

        $this->assertSame(
            $result->baselineDecision->type,
            $result->decision->type,
        );
        $this->assertTrue($result->reasoningRun->used_fallback);
        $this->assertSame('feature_not_entitled', $result->reasoningRun->fallback_reason);
        $this->assertSame('fallback', $result->reasoningRun->status);

        Http::assertNothingSent();
        $this->assertDatabaseCount('native_ai_runs', 0);
    }

    public function test_openai_provider_selects_from_the_canovia_candidate_contract_directly(): void
    {
        [$user, $plan] = $this->context();

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'selected_type' => 'verify_retention',
                    'confidence' => 0.90,
                    'reason_codes' => ['retention_unverified'],
                ]),
                200,
            ),
        ]);

        $state = $this->uncertainStudyState($plan->id);
        $evaluator = app(StudyReadinessEvaluator::class);
        $engine = app(StudyDecisionEngine::class);
        $readiness = $evaluator->evaluate($state);
        $candidates = $engine->candidates($state, $readiness);
        $baseline = $engine->decide($state, $readiness);

        $selection = app(OpenAiDecisionReasoningProvider::class)->select(
            new ReasoningRequest(
                state: $state,
                readiness: $readiness,
                candidates: $candidates,
                baselineDecision: $baseline,
                userId: $user->id,
                planId: $plan->id,
            ),
        );

        $this->assertSame('verify_retention', $selection->selectedType);
        $this->assertSame(0.9, $selection->confidence->value);
        $this->assertSame('openai', $selection->provider);
        $this->assertSame('gpt-5.6-luna', $selection->model);
        $this->assertNotNull($selection->nativeAiRunId);
        $this->assertSame(['retention_unverified'], $selection->reasonCodes);
    }

    public function test_premium_openai_route_selects_only_existing_candidate_and_records_cost_latency_and_disagreement(): void
    {
        [$user, $plan] = $this->context();
        $this->grantPremium($user);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'selected_type' => 'verify_retention',
                    'confidence' => 0.90,
                    'reason_codes' => ['retention_unverified'],
                ], inputTokens: 100, outputTokens: 50),
                200,
            ),
        ]);

        $result = $this->evaluate(
            $this->uncertainStudyState($plan->id),
            $user,
            $plan,
            ['mode' => 'openai'],
        );

        $this->assertSame('reinforce_observed_gap', $result->baselineDecision->type);
        $this->assertSame('verify_retention', $result->decision->type);
        $this->assertSame(0.82, $result->decision->confidence->value);

        $run = $result->reasoningRun;
        $nativeRun = NativeAiRun::firstOrFail();

        $this->assertSame('openai', $run->route_selected);
        $this->assertSame('openai', $run->provider);
        $this->assertSame('gpt-5.6-luna', $run->model);
        $this->assertSame('succeeded', $run->status);
        $this->assertFalse($run->used_fallback);
        $this->assertFalse($run->agrees_with_baseline);
        $this->assertSame(100, $run->input_tokens);
        $this->assertSame(50, $run->output_tokens);
        $this->assertSame(150, $run->total_tokens);
        $this->assertSame('0.00060000', $run->estimated_cost_usd);
        $this->assertGreaterThanOrEqual(0, $run->latency_ms);
        $this->assertSame($nativeRun->id, $run->native_ai_run_id);
        $this->assertSame($result->trace->id, $run->intelligence_decision_trace_id);

        $this->assertSame('intelligence_decision_reasoning', $nativeRun->purpose);
        $this->assertSame('automatic_ai_execution', $nativeRun->feature_key);
        $this->assertSame(100, $nativeRun->input_tokens);
        $this->assertSame(50, $nativeRun->output_tokens);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['store'] ?? null) === false
                && data_get($body, 'text.format.name') === 'canovia_decision_reasoning'
                && data_get($body, 'text.format.strict') === true;
        });
    }

    public function test_successful_openai_reasoning_is_reused_for_exact_same_request(): void
    {
        [$user, $plan] = $this->context();
        $this->grantPremium($user);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'selected_type' => 'verify_retention',
                    'confidence' => 0.80,
                    'reason_codes' => ['retention_unverified'],
                ]),
                200,
            ),
        ]);

        $state = $this->uncertainStudyState($plan->id);

        $first = $this->evaluate($state, $user, $plan, ['mode' => 'openai']);
        $second = $this->evaluate($state, $user, $plan, ['mode' => 'openai']);

        $this->assertSame($first->reasoningRun->id, $second->reasoningRun->id);
        $this->assertSame($first->trace->id, $second->trace->id);
        $this->assertSame($first->decision->type, $second->decision->type);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('native_ai_runs', 1);
        $this->assertDatabaseCount('intelligence_reasoning_runs', 1);
        $this->assertDatabaseCount('intelligence_decision_traces', 1);
    }

    public function test_openai_failure_falls_back_to_deterministic_and_does_not_cache_failure(): void
    {
        [$user, $plan] = $this->context();
        $this->grantPremium($user);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'error' => ['code' => 'rate_limit_exceeded'],
            ], 429),
        ]);

        $state = $this->uncertainStudyState($plan->id);

        $first = $this->evaluate($state, $user, $plan, ['mode' => 'openai']);
        $second = $this->evaluate($state, $user, $plan, ['mode' => 'openai']);

        $this->assertSame('reinforce_observed_gap', $first->decision->type);
        $this->assertTrue($first->reasoningRun->used_fallback);
        $this->assertSame('openai_rate_limit_exceeded', $first->reasoningRun->fallback_reason);
        $this->assertSame('fallback', $first->reasoningRun->status);

        $this->assertNotSame($first->reasoningRun->id, $second->reasoningRun->id);

        Http::assertSentCount(2);
        $this->assertDatabaseCount('native_ai_runs', 2);
        $this->assertDatabaseCount('intelligence_reasoning_runs', 2);
    }

    private function evaluate(
        StateSnapshot $state,
        User $user,
        Plan $plan,
        array $context,
    ) {
        return app(ReasonedDecisionOrchestrator::class)->evaluateAndPersist(
            state: $state,
            readinessEvaluator: app(StudyReadinessEvaluator::class),
            decisionEngine: app(StudyDecisionEngine::class),
            userId: $user->id,
            planId: $plan->id,
            context: $context,
            metadata: [
                'engine_version' => '53.3',
            ],
        );
    }

    private function uncertainStudyState(?int $planId = null): StateSnapshot
    {
        return new StateSnapshot(
            domain: IntelligenceDomain::Study,
            scopeType: 'plan',
            scopeId: $planId ?? 10,
            capturedAt: new DateTimeImmutable('2026-10-04T11:30:00+09:00'),
            metrics: [
                'evidence_count' => 2,
                'practice_attempt_count' => 2,
                'recall_review_count' => 0,
                'latest_score_percent' => 55,
                'average_score_percent' => 60,
                'best_score_percent' => 65,
            ],
            facts: [
                'has_practice_evidence' => true,
                'has_recall_evidence' => false,
                'observed_strengths' => ['database'],
                'observed_weaknesses' => ['network'],
            ],
            evidenceReferences: [[
                'trace' => 'evidence:native:'.str_repeat('a', 64),
                'origin' => 'task_evidence:1',
            ]],
        );
    }

    private function context(): array
    {
        $user = User::factory()->create();

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'V53 Reasoning Test',
            'category' => '資格学習',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        return [$user, $plan];
    }

    private function grantPremium(User $user): UserProductGrant
    {
        return UserProductGrant::query()->create([
            'user_id' => $user->id,
            'product_key' => ProductKey::PremiumCore,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
            'expires_at' => null,
            'metadata' => ['test' => true],
        ]);
    }

    private function responseBody(
        array $payload,
        int $inputTokens = 120,
        int $outputTokens = 40,
    ): array {
        return [
            'id' => 'resp_reasoning',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode(
                        $payload,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    ),
                ]],
            ]],
            'usage' => [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
            ],
        ];
    }
}
