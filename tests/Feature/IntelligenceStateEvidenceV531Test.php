<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Intelligence\Adapters\TaskEvidenceAdapter;
use App\Intelligence\Data\Confidence;
use App\Intelligence\Data\EvidenceObservation;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Services\StateSnapshotStore;
use App\Intelligence\Study\StudyStateBuilder;
use App\Intelligence\Support\IntelligenceFingerprint;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntelligenceStateEvidenceV531Test extends TestCase
{
    use RefreshDatabase;

    public function test_evidence_fingerprint_is_stable_for_semantically_equivalent_fact_order(): void
    {
        $left = new EvidenceObservation(
            reference: 'task_evidence:10',
            source: EvidenceSource::Native,
            type: 'study_practice_assessed',
            occurredAt: new DateTimeImmutable('2026-10-04T07:00:00+09:00'),
            confidence: new Confidence(1),
            facts: [
                'score_percent' => 80,
                'weaknesses' => ['network', 'database'],
            ],
            references: ['study_attempt:3', 'task_evidence:10'],
        );

        $right = new EvidenceObservation(
            reference: 'task_evidence:10',
            source: EvidenceSource::Native,
            type: 'study_practice_assessed',
            occurredAt: new DateTimeImmutable('2026-10-04T07:00:00+09:00'),
            confidence: new Confidence(1),
            facts: [
                'weaknesses' => ['network', 'database'],
                'score_percent' => 80,
            ],
            references: ['task_evidence:10', 'study_attempt:3'],
        );

        $this->assertSame(
            IntelligenceFingerprint::evidence($left),
            IntelligenceFingerprint::evidence($right),
        );
        $this->assertSame(
            IntelligenceFingerprint::evidenceReference($left),
            IntelligenceFingerprint::evidenceReference($right),
        );
    }

    public function test_task_evidence_adapter_only_carries_normalized_decision_facts(): void
    {
        [$user, $plan, $task] = $this->studyContext();

        $evidence = TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native->value,
            'type' => 'study_practice_assessed',
            'external_key' => 'study-practice-attempt:1',
            'confidence' => 1,
            'occurred_at' => now(),
            'metadata' => [
                'study_practice_attempt_id' => 1,
                'study_practice_session_id' => 2,
                'score_percent' => 84,
                'recommended_task_progress_percent' => 60,
                'strengths' => ['OS'],
                'weaknesses' => ['network'],
                'next_step' => 'networkを確認',
                'provider_payload' => ['secret' => 'raw-provider-data'],
                'questions' => ['raw question'],
                'answers' => ['raw answer'],
                'evidence_summary' => 'free text summary',
                'next_action' => 'free text action',
                'url' => 'https://example.invalid/private',
                'title' => 'private title',
            ],
        ]);

        $observation = app(TaskEvidenceAdapter::class)->adapt($evidence);

        $this->assertSame(84, $observation->facts['score_percent']);
        $this->assertSame(['network'], $observation->facts['weaknesses']);
        $this->assertSame('task_evidence:'.$evidence->id, $observation->reference);

        foreach ([
            'provider_payload',
            'questions',
            'answers',
            'evidence_summary',
            'next_action',
            'url',
            'title',
        ] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $observation->facts);
        }
    }

    public function test_study_state_is_built_from_evidence_without_using_task_progress_as_state_truth(): void
    {
        $adapter = app(TaskEvidenceAdapter::class);
        [$user, $plan, $task] = $this->studyContext();

        $task->update(['progress_percent' => 95]);

        $first = TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native->value,
            'type' => 'study_practice_assessed',
            'external_key' => 'study-practice-attempt:1',
            'confidence' => 1,
            'occurred_at' => now()->subHour(),
            'metadata' => [
                'score_percent' => 60,
                'strengths' => ['OS'],
                'weaknesses' => ['network'],
            ],
        ]);

        $second = TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native->value,
            'type' => 'study_practice_assessed',
            'external_key' => 'study-practice-attempt:2',
            'confidence' => 1,
            'occurred_at' => now(),
            'metadata' => [
                'score_percent' => 80,
                'strengths' => ['database'],
                'weaknesses' => ['network', 'performance'],
            ],
        ]);

        $recall = TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native->value,
            'type' => 'study_recall_reviewed',
            'external_key' => 'study-recall:1',
            'confidence' => 1,
            'occurred_at' => now()->addMinute(),
            'metadata' => [
                'rating' => 'good',
                'interval_days' => 3,
                'prompt' => 'raw recall prompt must not become state',
            ],
        ]);

        $state = app(StudyStateBuilder::class)->build(
            IntelligenceDomain::Study,
            [
                'scope_type' => 'plan',
                'scope_id' => $plan->id,
                'captured_at' => '2026-10-04T07:30:00+09:00',
            ],
            [
                $adapter->adapt($first),
                $adapter->adapt($second),
                $adapter->adapt($recall),
            ],
        );

        $this->assertSame(3, $state->metrics['evidence_count']);
        $this->assertSame(2, $state->metrics['practice_attempt_count']);
        $this->assertSame(1, $state->metrics['recall_review_count']);
        $this->assertSame(80, $state->metrics['latest_score_percent']);
        $this->assertSame(70, $state->metrics['average_score_percent']);
        $this->assertSame(80, $state->metrics['best_score_percent']);
        $this->assertSame(
            ['network', 'performance'],
            $state->facts['observed_weaknesses'],
        );
        $this->assertArrayNotHasKey('progress_percent', $state->metrics);
        $this->assertArrayNotHasKey('task_progress_percent', $state->facts);
        $this->assertCount(3, $state->evidenceReferences);
    }

    public function test_state_store_separates_semantic_state_from_snapshot_identity(): void
    {
        [$user, $plan] = $this->studyContext();

        $builder = app(StudyStateBuilder::class);
        $store = app(StateSnapshotStore::class);

        $evidence = new EvidenceObservation(
            reference: 'task_evidence:100',
            source: EvidenceSource::Native,
            type: 'study_practice_assessed',
            occurredAt: new DateTimeImmutable('2026-10-04T07:00:00+09:00'),
            confidence: Confidence::deterministic(),
            facts: [
                'score_percent' => 75,
                'strengths' => [],
                'weaknesses' => ['network'],
            ],
        );

        $firstState = $builder->build(
            IntelligenceDomain::Study,
            [
                'scope_type' => 'plan',
                'scope_id' => $plan->id,
                'captured_at' => '2026-10-04T07:30:00+09:00',
            ],
            [$evidence],
        );

        $sameSnapshot = $store->persist(
            $firstState,
            $user->id,
            $plan->id,
            [
                'schema_version' => '1.0',
                'builder' => StudyStateBuilder::class,
                'builder_version' => '53.1',
                'provider_payload' => ['must' => 'not persist'],
            ],
        );

        $sameSnapshotAgain = $store->persist(
            $firstState,
            $user->id,
            $plan->id,
        );

        $laterState = $builder->build(
            IntelligenceDomain::Study,
            [
                'scope_type' => 'plan',
                'scope_id' => $plan->id,
                'captured_at' => '2026-10-04T08:00:00+09:00',
            ],
            [$evidence],
        );

        $laterSnapshot = $store->persist(
            $laterState,
            $user->id,
            $plan->id,
            [
                'builder' => StudyStateBuilder::class,
                'provider_payload' => ['must' => 'not persist'],
            ],
        );

        $this->assertSame($sameSnapshot->id, $sameSnapshotAgain->id);
        $this->assertNotSame($sameSnapshot->id, $laterSnapshot->id);
        $this->assertSame(
            $sameSnapshot->state_fingerprint,
            $laterSnapshot->state_fingerprint,
        );
        $this->assertNotSame(
            $sameSnapshot->state_reference,
            $laterSnapshot->state_reference,
        );
        $this->assertArrayNotHasKey('provider_payload', $sameSnapshot->metadata ?? []);
        $this->assertSame(
            $laterSnapshot->id,
            $store->latest(IntelligenceDomain::Study, 'plan', $plan->id)?->id,
        );
        $this->assertDatabaseCount('intelligence_state_snapshots', 2);
    }

    private function studyContext(): array
    {
        $user = User::factory()->create();

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'V53 Study State',
            'category' => '資格学習',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'ネットワーク演習',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
