<?php

namespace Tests\Unit;

use App\Enums\EvidenceSource;
use App\Intelligence\Contracts\ActionGenerator;
use App\Intelligence\Contracts\DecisionEngine;
use App\Intelligence\Contracts\OutcomeInterpreter;
use App\Intelligence\Contracts\ReadinessEvaluator;
use App\Intelligence\Contracts\StateBuilder;
use App\Intelligence\Data\ActionProposal;
use App\Intelligence\Data\Confidence;
use App\Intelligence\Data\Decision;
use App\Intelligence\Data\EvidenceObservation;
use App\Intelligence\Data\OutcomeObservation;
use App\Intelligence\Data\ReadinessAssessment;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Enums\ReadinessLevel;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class IntelligenceContractV530Test extends TestCase
{
    public function test_confidence_is_normalized_and_bounded(): void
    {
        $this->assertSame(100, Confidence::deterministic()->percent());
        $this->assertSame(63, (new Confidence(0.625))->percent());

        $this->expectException(InvalidArgumentException::class);
        new Confidence(1.01);
    }

    public function test_readiness_score_is_bounded_without_forcing_unknown_state_to_have_a_score(): void
    {
        $unknown = new ReadinessAssessment(
            score: null,
            level: ReadinessLevel::Unknown,
            confidence: new Confidence(0.2),
        );

        $this->assertNull($unknown->score);
        $this->assertSame(ReadinessLevel::Unknown, $unknown->level);

        $this->expectException(InvalidArgumentException::class);
        new ReadinessAssessment(
            score: 101,
            level: ReadinessLevel::Ready,
            confidence: Confidence::deterministic(),
        );
    }

    public function test_contract_supports_state_to_decision_to_action_to_outcome_without_task_dependency(): void
    {
        $evidence = new EvidenceObservation(
            reference: 'task_evidence:42',
            source: EvidenceSource::GitHub,
            type: 'pull_request_ci_observed',
            occurredAt: new DateTimeImmutable('2026-10-04T00:00:00+09:00'),
            confidence: Confidence::deterministic(),
            facts: ['ci_state' => 'success'],
        );

        $stateBuilder = new class implements StateBuilder {
            public function build(
                IntelligenceDomain $domain,
                array $context,
                iterable $evidence,
            ): StateSnapshot {
                $references = [];

                foreach ($evidence as $observation) {
                    $references[] = $observation->reference;
                }

                return new StateSnapshot(
                    domain: $domain,
                    scopeType: 'repository_release',
                    scopeId: 'canovia',
                    capturedAt: new DateTimeImmutable('2026-10-04T00:01:00+09:00'),
                    metrics: ['verification' => 0],
                    facts: ['ci_success' => true],
                    evidenceReferences: $references,
                );
            }
        };

        $readinessEvaluator = new class implements ReadinessEvaluator {
            public function evaluate(StateSnapshot $state): ReadinessAssessment
            {
                return new ReadinessAssessment(
                    score: 80,
                    level: ReadinessLevel::Developing,
                    confidence: new Confidence(0.9),
                    gaps: ['real_device_verification'],
                );
            }
        };

        $decisionEngine = new class implements DecisionEngine {
            public function decide(
                StateSnapshot $state,
                ReadinessAssessment $readiness,
                array $context = [],
            ): Decision {
                return new Decision(
                    type: 'verify',
                    reasonCode: 'verification_gap',
                    summary: '実機確認を優先する',
                    confidence: new Confidence(0.9),
                    inputFingerprint: hash('sha256', $state->domain->value.':'.$readiness->score),
                    reasons: $readiness->gaps,
                );
            }
        };

        $actionGenerator = new class implements ActionGenerator {
            public function generate(
                StateSnapshot $state,
                ReadinessAssessment $readiness,
                Decision $decision,
            ): array {
                return [
                    new ActionProposal(
                        kind: 'verification',
                        title: '実機で主要導線を確認する',
                        intent: 'release readinessのverification gapを閉じる',
                        confidence: $decision->confidence,
                        estimatedMinutes: 20,
                        successSignals: ['real_device_verified'],
                    ),
                ];
            }
        };

        $outcomeInterpreter = new class implements OutcomeInterpreter {
            public function interpret(
                ActionProposal $action,
                iterable $evidence,
                array $context = [],
            ): OutcomeObservation {
                return new OutcomeObservation(
                    status: 'observed',
                    observedAt: new DateTimeImmutable('2026-10-04T00:30:00+09:00'),
                    confidence: Confidence::deterministic(),
                    facts: ['real_device_verified' => true],
                );
            }
        };

        $state = $stateBuilder->build(
            IntelligenceDomain::Development,
            [],
            [$evidence],
        );
        $readiness = $readinessEvaluator->evaluate($state);
        $decision = $decisionEngine->decide($state, $readiness);
        $actions = $actionGenerator->generate($state, $readiness, $decision);
        $outcome = $outcomeInterpreter->interpret($actions[0], []);

        $this->assertSame(IntelligenceDomain::Development, $state->domain);
        $this->assertSame(['task_evidence:42'], $state->evidenceReferences);
        $this->assertSame(80, $readiness->score);
        $this->assertSame('verify', $decision->type);
        $this->assertSame('verification', $actions[0]->kind);
        $this->assertTrue($outcome->facts['real_device_verified']);

        $this->assertObjectNotHasProperty('taskId', $state);
        $this->assertObjectNotHasProperty('taskId', $decision);
        $this->assertObjectNotHasProperty('taskId', $actions[0]);
    }

    public function test_initial_domains_are_explicit_without_domain_logic_in_the_core_contract(): void
    {
        $this->assertSame(
            ['general', 'study', 'development'],
            array_map(
                fn (IntelligenceDomain $domain) => $domain->value,
                IntelligenceDomain::cases(),
            ),
        );
    }
}
