<?php

namespace Tests\Feature;

use App\Intelligence\Data\Confidence;
use App\Intelligence\Data\Decision;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Enums\ReadinessLevel;
use App\Intelligence\Services\DecisionOrchestrator;
use App\Intelligence\Services\DecisionTraceStore;
use App\Intelligence\Study\StudyDecisionEngine;
use App\Intelligence\Study\StudyReadinessEvaluator;
use App\Intelligence\Support\IntelligenceFingerprint;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class IntelligenceDecisionReadinessV532Test extends TestCase
{
    use RefreshDatabase;

    public function test_missing_practice_evidence_keeps_readiness_unknown_and_collects_baseline(): void
    {
        $state = $this->studyState(
            capturedAt: '2026-10-04T10:00:00+09:00',
            metrics: [
                'evidence_count' => 0,
                'practice_attempt_count' => 0,
                'recall_review_count' => 0,
                'latest_score_percent' => null,
                'average_score_percent' => null,
                'best_score_percent' => null,
            ],
        );

        $readiness = app(StudyReadinessEvaluator::class)->evaluate($state);

        $this->assertNull($readiness->score);
        $this->assertSame(ReadinessLevel::Unknown, $readiness->level);
        $this->assertSame(0.2, $readiness->confidence->value);
        $this->assertSame('practice_evidence_missing', $readiness->gaps[0]['code']);

        $decision = app(StudyDecisionEngine::class)->decide($state, $readiness);

        $this->assertSame('collect_baseline_evidence', $decision->type);
        $this->assertSame('practice_evidence_missing', $decision->reasonCode);
        $this->assertSame(
            IntelligenceFingerprint::decisionInput($state, $readiness),
            $decision->inputFingerprint,
        );
    }

    public function test_observed_weakness_beats_thin_sample_and_retention_gap(): void
    {
        $state = $this->studyState(
            capturedAt: '2026-10-04T10:10:00+09:00',
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
        );

        $readiness = app(StudyReadinessEvaluator::class)->evaluate($state);

        $this->assertSame(54, $readiness->score);
        $this->assertSame(ReadinessLevel::Developing, $readiness->level);
        $this->assertSame(0.6, $readiness->confidence->value);

        $gapCodes = array_column($readiness->gaps, 'code');

        $this->assertContains('practice_evidence_thin', $gapCodes);
        $this->assertContains('mastery_below_target', $gapCodes);
        $this->assertContains('retention_unverified', $gapCodes);
        $this->assertContains('observed_weaknesses', $gapCodes);

        $engine = app(StudyDecisionEngine::class);
        $candidates = $engine->candidates($state, $readiness);

        $this->assertSame('reinforce_observed_gap', $candidates[0]->type);
        $this->assertSame('expand_practice_sample', $candidates[1]->type);
        $this->assertSame('verify_retention', $candidates[2]->type);

        $decision = $engine->decide($state, $readiness);

        $this->assertSame('reinforce_observed_gap', $decision->type);
        $this->assertSame(3, $decision->metadata['candidate_count']);
        $this->assertSame(
            [
                'reinforce_observed_gap',
                'expand_practice_sample',
                'verify_retention',
            ],
            $decision->metadata['candidate_types'],
        );
    }

    public function test_ready_requires_performance_sample_retention_and_no_observed_weakness(): void
    {
        $state = $this->studyState(
            capturedAt: '2026-10-04T10:20:00+09:00',
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
                'observed_strengths' => ['network', 'database'],
                'observed_weaknesses' => [],
            ],
        );

        $readiness = app(StudyReadinessEvaluator::class)->evaluate($state);

        $this->assertSame(87, $readiness->score);
        $this->assertSame(ReadinessLevel::Ready, $readiness->level);
        $this->assertSame(0.8, $readiness->confidence->value);
        $this->assertSame([], $readiness->gaps);

        $decision = app(StudyDecisionEngine::class)->decide($state, $readiness);

        $this->assertSame('advance_scope', $decision->type);
        $this->assertSame('readiness_target_met', $decision->reasonCode);
    }

    public function test_same_snapshot_is_idempotent_but_later_observation_gets_new_decision_trace(): void
    {
        $orchestrator = app(DecisionOrchestrator::class);
        $evaluator = app(StudyReadinessEvaluator::class);
        $engine = app(StudyDecisionEngine::class);

        $firstState = $this->studyState(
            capturedAt: '2026-10-04T10:30:00+09:00',
            metrics: [
                'evidence_count' => 2,
                'practice_attempt_count' => 2,
                'recall_review_count' => 0,
                'latest_score_percent' => 76,
                'average_score_percent' => 74,
                'best_score_percent' => 80,
            ],
            facts: [
                'has_practice_evidence' => true,
                'has_recall_evidence' => false,
                'observed_strengths' => [],
                'observed_weaknesses' => ['network'],
            ],
        );

        $first = $orchestrator->evaluateAndPersist(
            $firstState,
            $evaluator,
            $engine,
            metadata: [
                'engine_version' => '53.2',
                'provider_payload' => ['must' => 'not persist'],
            ],
        );

        $same = $orchestrator->evaluateAndPersist(
            $firstState,
            $evaluator,
            $engine,
            metadata: [
                'engine_version' => '53.2',
            ],
        );

        $laterState = $this->studyState(
            capturedAt: '2026-10-04T11:00:00+09:00',
            metrics: $firstState->metrics,
            facts: $firstState->facts,
        );

        $later = $orchestrator->evaluateAndPersist(
            $laterState,
            $evaluator,
            $engine,
            metadata: [
                'engine_version' => '53.2',
            ],
        );

        $this->assertSame($first->trace->id, $same->trace->id);
        $this->assertNotSame($first->trace->id, $later->trace->id);

        $this->assertSame(
            $first->trace->state_fingerprint,
            $later->trace->state_fingerprint,
        );
        $this->assertSame(
            $first->trace->readiness_fingerprint,
            $later->trace->readiness_fingerprint,
        );
        $this->assertNotSame(
            $first->trace->state_reference,
            $later->trace->state_reference,
        );
        $this->assertNotSame(
            $first->trace->decision_reference,
            $later->trace->decision_reference,
        );

        $this->assertSame('study_readiness_v1', $first->trace->readiness_metadata['policy']);
        $this->assertSame('study_decision_v1', $first->trace->decision_metadata['policy_version']);
        $this->assertArrayNotHasKey('provider_payload', $first->trace->metadata ?? []);

        $this->assertDatabaseCount('intelligence_state_snapshots', 2);
        $this->assertDatabaseCount('intelligence_decision_traces', 2);
    }

    public function test_decision_trace_rejects_a_decision_for_different_inputs(): void
    {
        $state = $this->studyState(
            capturedAt: '2026-10-04T11:10:00+09:00',
            metrics: [
                'evidence_count' => 1,
                'practice_attempt_count' => 1,
                'recall_review_count' => 0,
                'latest_score_percent' => 70,
                'average_score_percent' => 70,
                'best_score_percent' => 70,
            ],
        );

        $readiness = app(StudyReadinessEvaluator::class)->evaluate($state);

        $invalid = new Decision(
            type: 'expand_practice_sample',
            reasonCode: 'practice_evidence_thin',
            summary: 'invalid input fingerprint',
            confidence: Confidence::deterministic(),
            inputFingerprint: str_repeat('0', 64),
        );

        $this->expectException(InvalidArgumentException::class);

        app(DecisionTraceStore::class)->persist(
            $state,
            $readiness,
            $invalid,
        );
    }

    private function studyState(
        string $capturedAt,
        array $metrics,
        array $facts = [],
    ): StateSnapshot {
        return new StateSnapshot(
            domain: IntelligenceDomain::Study,
            scopeType: 'plan',
            scopeId: 10,
            capturedAt: new DateTimeImmutable($capturedAt),
            metrics: $metrics,
            facts: [
                'has_practice_evidence' => false,
                'has_recall_evidence' => false,
                'observed_strengths' => [],
                'observed_weaknesses' => [],
                ...$facts,
            ],
            evidenceReferences: [
                [
                    'trace' => 'evidence:native:'.str_repeat('a', 64),
                    'origin' => 'task_evidence:1',
                ],
            ],
        );
    }
}
