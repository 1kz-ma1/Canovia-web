<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Presentation\IntelligenceStateChangeFeedbackService;
use App\Models\IntelligenceActionProjection;
use App\Models\IntelligenceDecisionTrace;
use App\Models\IntelligenceStateSnapshot;
use App\Models\Plan;
use App\Models\StudyScopeCapture;
use App\Models\StudyScopeItem;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StateChangeFeedbackV546Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Carbon::setTestNow('2026-10-04 18:00:00');

        config([
            'session.driver' => 'array',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_service_explains_development_action_change_from_new_ci_evidence(): void
    {
        [$user, $plan, $task] = $this->developmentScenario();
        $ci = $this->evidence(
            $task,
            'pull_request_ci_observed',
            EvidenceSource::GitHub,
        );

        $this->historyPair(
            $plan,
            IntelligenceDomain::Development,
            previousScore: 25,
            currentScore: 45,
            previousLevel: 'developing',
            currentLevel: 'developing',
            previousReason: 'ci_unverified',
            currentReason: 'review_unverified',
            previousDecision: 'CI / Testを確認する必要があります。',
            currentDecision: '次はReview状態を確認します。',
            previousAction: 'CIを確認',
            currentAction: 'Reviewを確認',
            currentEvidence: ['task_evidence:'.$ci->id],
        );

        $feedback = app(IntelligenceStateChangeFeedbackService::class)
            ->latestForPlan($plan, IntelligenceDomain::Development);

        $this->assertNotNull($feedback);
        $this->assertSame('action_changed', $feedback['kind']);
        $this->assertSame(25, $feedback['readiness_before']);
        $this->assertSame(45, $feedback['readiness_after']);
        $this->assertSame(20, $feedback['readiness_delta']);
        $this->assertTrue($feedback['decision_changed']);
        $this->assertTrue($feedback['action_changed']);
        $this->assertSame('CIを確認', $feedback['action_before']);
        $this->assertSame('Reviewを確認', $feedback['action_after']);
        $this->assertContains('CI / Test', $feedback['added_evidence_labels']);
        $this->assertStringContainsString('Readiness 25 → 45', $feedback['summary']);
        $this->assertStringContainsString('CI / Testを反映', $feedback['summary']);
    }

    public function test_small_score_only_change_is_suppressed(): void
    {
        [, $plan] = $this->studyScenario();

        $this->historyPair(
            $plan,
            IntelligenceDomain::Study,
            previousScore: 58,
            currentScore: 60,
            previousLevel: 'developing',
            currentLevel: 'developing',
            previousReason: 'mastery_below_target',
            currentReason: 'mastery_below_target',
            previousDecision: '理解度を補強します。',
            currentDecision: '理解度を補強します。',
            previousAction: '演習で補強',
            currentAction: '演習で補強',
            sameActionFingerprint: true,
        );

        $this->assertNull(
            app(IntelligenceStateChangeFeedbackService::class)
                ->latestForPlan($plan, IntelligenceDomain::Study),
        );
    }

    public function test_five_point_readiness_change_is_meaningful_even_when_action_stays_same(): void
    {
        [, $plan, $task] = $this->studyScenario();
        $practice = $this->evidence(
            $task,
            'study_practice_assessed',
            EvidenceSource::Native,
        );

        $this->historyPair(
            $plan,
            IntelligenceDomain::Study,
            previousScore: 58,
            currentScore: 67,
            previousLevel: 'developing',
            currentLevel: 'developing',
            previousReason: 'mastery_below_target',
            currentReason: 'mastery_below_target',
            previousDecision: '理解度を補強します。',
            currentDecision: '理解度を補強します。',
            previousAction: '演習で補強',
            currentAction: '演習で補強',
            sameActionFingerprint: true,
            currentEvidence: ['task_evidence:'.$practice->id],
        );

        $feedback = app(IntelligenceStateChangeFeedbackService::class)
            ->latestForPlan($plan, IntelligenceDomain::Study);

        $this->assertNotNull($feedback);
        $this->assertSame('readiness_improved', $feedback['kind']);
        $this->assertSame(9, $feedback['readiness_delta']);
        $this->assertFalse($feedback['decision_changed']);
        $this->assertFalse($feedback['action_changed']);
        $this->assertContains('Practice結果', $feedback['added_evidence_labels']);
    }

    public function test_study_workspace_renders_latest_persisted_change_without_creating_history(): void
    {
        [$user, $plan, $task] = $this->studyScenario();
        $this->studyScope($plan, 'ネットワーク', 'CIDR');
        $practice = $this->evidence(
            $task,
            'study_practice_assessed',
            EvidenceSource::Native,
        );

        $this->historyPair(
            $plan,
            IntelligenceDomain::Study,
            previousScore: 40,
            currentScore: 55,
            previousLevel: 'developing',
            currentLevel: 'developing',
            previousReason: 'coverage_below_target',
            currentReason: 'mastery_below_target',
            previousDecision: '未観測の範囲を確認します。',
            currentDecision: '理解度の弱い範囲を補強します。',
            previousAction: '現在地を確認',
            currentAction: '演習で補強',
            currentEvidence: ['task_evidence:'.$practice->id],
        );

        $before = IntelligenceDecisionTrace::count();

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-intelligence-state-change', false)
            ->assertSee('STATE CHANGE')
            ->assertSee('40')
            ->assertSee('55')
            ->assertSee('Practice結果')
            ->assertSee('現在地を確認')
            ->assertSee('演習で補強');

        $this->assertSame($before, IntelligenceDecisionTrace::count());
    }

    public function test_development_workspace_renders_evidence_to_action_change(): void
    {
        [$user, $plan, $task] = $this->developmentScenario();
        $this->evidence(
            $task,
            'github_commit_observed',
            EvidenceSource::GitHub,
        );
        $ci = $this->evidence(
            $task,
            'pull_request_ci_observed',
            EvidenceSource::GitHub,
        );

        $this->historyPair(
            $plan,
            IntelligenceDomain::Development,
            previousScore: 14,
            currentScore: 29,
            previousLevel: 'developing',
            currentLevel: 'developing',
            previousReason: 'ci_unverified',
            currentReason: 'review_unverified',
            previousDecision: 'CI / Testが未確認です。',
            currentDecision: 'Reviewが未確認です。',
            previousAction: 'CIを確認',
            currentAction: 'Reviewを確認',
            currentEvidence: ['task_evidence:'.$ci->id],
        );

        $this->actingAs($user)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-intelligence-state-change', false)
            ->assertSee('次にやることが変わりました')
            ->assertSee('CI / Test')
            ->assertSee('Reviewを確認');
    }

    public function test_overview_shows_at_most_two_representative_mode_changes(): void
    {
        $user = User::factory()->create();

        $study = $this->plan($user, 'AP対策', '資格学習', 1);
        $studyTask = $this->task($study, 'CIDR演習');
        $this->studyScope($study, 'ネットワーク', 'CIDR');
        $practice = $this->evidence(
            $studyTask,
            'study_practice_assessed',
            EvidenceSource::Native,
        );
        $this->historyPair(
            $study,
            IntelligenceDomain::Study,
            previousScore: 50,
            currentScore: 60,
            previousLevel: 'developing',
            currentLevel: 'developing',
            previousReason: 'mastery_below_target',
            currentReason: 'retention_unverified',
            previousDecision: '理解度を補強します。',
            currentDecision: '次は定着を確認します。',
            previousAction: '演習で補強',
            currentAction: '定着を確認',
            currentEvidence: ['task_evidence:'.$practice->id],
        );

        $development = $this->plan($user, 'Canovia', '個人開発', 1);
        $developmentTask = $this->task($development, 'V54.6');
        $commit = $this->evidence(
            $developmentTask,
            'github_commit_observed',
            EvidenceSource::GitHub,
        );
        $this->historyPair(
            $development,
            IntelligenceDomain::Development,
            previousScore: 0,
            currentScore: 14,
            previousLevel: 'unknown',
            currentLevel: 'developing',
            previousReason: 'development_evidence_missing',
            currentReason: 'ci_unverified',
            previousDecision: 'Development Evidenceが必要です。',
            currentDecision: 'CI / Testを確認します。',
            previousAction: 'GitHub Evidenceを確認',
            currentAction: 'CIを確認',
            currentEvidence: ['task_evidence:'.$commit->id],
        );

        $response = $this->actingAs($user)
            ->get(route('workspace.overview.index'))
            ->assertOk()
            ->assertSee('data-overview-intelligence-changes', false)
            ->assertSee('Evidenceで判断がどう変わったか')
            ->assertSee('Practice結果')
            ->assertSee('Commit');

        $this->assertSame(
            2,
            substr_count(
                $response->getContent(),
                'data-intelligence-state-change-kind="',
            ),
        );
    }

    private function studyScenario(): array
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, 'AP対策', '資格学習', 1);
        $task = $this->task($plan, 'CIDR演習');

        return [$user, $plan, $task];
    }

    private function developmentScenario(): array
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, 'Canovia', '個人開発', 1);
        $task = $this->task($plan, 'Release candidate');

        return [$user, $plan, $task];
    }

    private function plan(
        User $user,
        string $title,
        string $category,
        int $priority,
    ): Plan {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => $category,
            'priority' => $priority,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => $plan->tasks()->count() + 1,
        ]);
    }

    private function studyScope(
        Plan $plan,
        string $subject,
        string $unit,
    ): StudyScopeItem {
        $capture = StudyScopeCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $plan->user_id,
            'status' => 'confirmed',
            'exam_title' => '試験',
            'exam_date' => $plan->deadline?->format('Y-m-d'),
            'confidence' => 1,
            'extraction_version' => 'study_scope_v1',
            'confirmed_at' => now(),
        ]);

        return StudyScopeItem::query()->create([
            'study_scope_capture_id' => $capture->id,
            'plan_id' => $plan->id,
            'subject' => $subject,
            'unit' => $unit,
            'range_text' => $unit,
            'confidence' => 1,
            'sort_order' => 0,
        ]);
    }

    private function evidence(
        Task $task,
        string $type,
        EvidenceSource $source,
    ): TaskEvidence {
        $metadata = match ($type) {
            'github_commit_observed' => [
                'repo_full_name' => '1kz-ma1/Canovia-web',
                'commit_sha' => str_repeat('a', 40),
                'branch' => 'feature/v54-6',
            ],
            'pull_request_ci_observed' => [
                'repo_full_name' => '1kz-ma1/Canovia-web',
                'pull_request_number' => 246,
                'head_sha' => str_repeat('a', 40),
                'ci_state' => 'success',
            ],
            'study_practice_assessed' => [
                'score_percent' => 80,
                'strengths' => ['CIDR'],
                'weaknesses' => [],
                'weakness_topics' => [],
                'provider_payload' => ['private' => 'must not render'],
            ],
            default => [],
        };

        return TaskEvidence::query()->create([
            'plan_id' => $task->plan_id,
            'task_id' => $task->id,
            'user_id' => $task->plan?->user_id,
            'source' => $source->value,
            'type' => $type,
            'external_key' => $type.':'.Str::uuid(),
            'confidence' => 1,
            'occurred_at' => now(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param array<int,string> $currentEvidence
     */
    private function historyPair(
        Plan $plan,
        IntelligenceDomain $domain,
        int $previousScore,
        int $currentScore,
        string $previousLevel,
        string $currentLevel,
        string $previousReason,
        string $currentReason,
        string $previousDecision,
        string $currentDecision,
        string $previousAction,
        string $currentAction,
        bool $sameActionFingerprint = false,
        array $currentEvidence = [],
    ): void {
        $previousState = $this->state(
            $plan,
            $domain,
            'previous-'.$domain->value.'-'.$plan->id,
            [],
            now()->subMinute(),
        );
        $currentState = $this->state(
            $plan,
            $domain,
            'current-'.$domain->value.'-'.$plan->id,
            $currentEvidence,
            now(),
        );

        $previousTrace = $this->trace(
            $plan,
            $domain,
            $previousState,
            'previous-'.$domain->value.'-'.$plan->id,
            $previousScore,
            $previousLevel,
            $previousReason,
            $previousDecision,
            now()->subMinute(),
        );
        $currentTrace = $this->trace(
            $plan,
            $domain,
            $currentState,
            'current-'.$domain->value.'-'.$plan->id,
            $currentScore,
            $currentLevel,
            $currentReason,
            $currentDecision,
            now(),
        );

        $fingerprint = hash(
            'sha256',
            $sameActionFingerprint
                ? 'same-action-'.$domain->value.'-'.$plan->id
                : 'previous-action-'.$domain->value.'-'.$plan->id,
        );

        $this->projection(
            $plan,
            $domain,
            $previousTrace,
            'previous-action-'.$domain->value.'-'.$plan->id,
            $fingerprint,
            $previousAction,
            'superseded',
            now()->subMinute(),
        );

        $this->projection(
            $plan,
            $domain,
            $currentTrace,
            'current-action-'.$domain->value.'-'.$plan->id,
            $sameActionFingerprint
                ? $fingerprint
                : hash(
                    'sha256',
                    'current-action-'.$domain->value.'-'.$plan->id,
                ),
            $currentAction,
            'active',
            now(),
        );
    }

    private function state(
        Plan $plan,
        IntelligenceDomain $domain,
        string $key,
        array $evidenceReferences,
        mixed $capturedAt,
    ): IntelligenceStateSnapshot {
        return IntelligenceStateSnapshot::query()->create([
            'user_id' => $plan->user_id,
            'plan_id' => $plan->id,
            'domain' => $domain->value,
            'scope_type' => $domain->value.'_plan',
            'scope_id' => (string) $plan->id,
            'state_fingerprint' => hash('sha256', 'state-'.$key),
            'state_reference' => 'state:'.$key.':'.Str::uuid(),
            'captured_at' => $capturedAt,
            'metrics' => [],
            'facts' => [],
            'evidence_references' => $evidenceReferences,
            'metadata' => [],
        ]);
    }

    private function trace(
        Plan $plan,
        IntelligenceDomain $domain,
        IntelligenceStateSnapshot $state,
        string $key,
        int $score,
        string $level,
        string $reason,
        string $summary,
        mixed $createdAt,
    ): IntelligenceDecisionTrace {
        $trace = IntelligenceDecisionTrace::query()->create([
            'user_id' => $plan->user_id,
            'plan_id' => $plan->id,
            'intelligence_state_snapshot_id' => $state->id,
            'domain' => $domain->value,
            'scope_type' => $domain->value.'_plan',
            'scope_id' => (string) $plan->id,
            'state_reference' => $state->state_reference,
            'state_fingerprint' => $state->state_fingerprint,
            'readiness_fingerprint' => hash('sha256', 'readiness-'.$key),
            'readiness_score' => $score,
            'readiness_level' => $level,
            'readiness_confidence' => 0.8,
            'readiness_components' => [],
            'readiness_gaps' => [],
            'readiness_metadata' => [],
            'decision_reference' => 'decision:'.hash('sha256', $key),
            'decision_type' => 'test_decision',
            'reason_code' => $reason,
            'decision_summary' => $summary,
            'decision_confidence' => 0.8,
            'input_fingerprint' => hash('sha256', 'input-'.$key),
            'decision_reasons' => [],
            'decision_metadata' => [],
            'metadata' => [],
        ]);

        $trace->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $trace->fresh();
    }

    private function projection(
        Plan $plan,
        IntelligenceDomain $domain,
        IntelligenceDecisionTrace $trace,
        string $key,
        string $fingerprint,
        string $title,
        string $status,
        mixed $createdAt,
    ): IntelligenceActionProjection {
        $projection = IntelligenceActionProjection::query()->create([
            'user_id' => $plan->user_id,
            'plan_id' => $plan->id,
            'intelligence_decision_trace_id' => $trace->id,
            'domain' => $domain->value,
            'scope_type' => $domain->value.'_plan',
            'scope_id' => (string) $plan->id,
            'state_fingerprint' => $trace->state_fingerprint,
            'action_fingerprint' => $fingerprint,
            'action_reference' => 'action:'.hash('sha256', $key),
            'kind' => 'test_action',
            'title' => $title,
            'intent' => $title,
            'confidence' => 0.8,
            'estimated_minutes' => null,
            'success_signals' => [],
            'metadata' => [],
            'status' => $status,
            'superseded_at' => $status === 'superseded'
                ? $createdAt
                : null,
        ]);

        $projection->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $projection->fresh();
    }
}
