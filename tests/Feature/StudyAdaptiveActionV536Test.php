<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Intelligence\Study\StudyAdaptiveActionService;
use App\Models\IntelligenceActionProjection;
use App\Models\IntelligenceDecisionTrace;
use App\Models\Plan;
use App\Models\StudyScopeCapture;
use App\Models\StudyScopeItem;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Services\StudyAdaptiveHomeActionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyAdaptiveActionV536Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-04 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_missing_scope_produces_action_without_creating_task(): void
    {
        [$user, $plan] = $this->studyPlan();

        $result = app(StudyAdaptiveActionService::class)->refresh(
            $plan,
            now(),
        );

        $action = $result->primaryAction();

        $this->assertSame('capture_scope', $result->decision->type);
        $this->assertSame('study_scope_capture', $action?->kind);
        $this->assertSame('study_scope', data_get($action?->metadata, 'route_kind'));
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('intelligence_state_snapshots', 1);
        $this->assertDatabaseCount('intelligence_decision_traces', 1);
        $this->assertDatabaseCount('intelligence_action_projections', 1);

        $projection = IntelligenceActionProjection::firstOrFail();
        $this->assertSame(IntelligenceActionProjection::STATUS_ACTIVE, $projection->status);
        $this->assertNull($projection->projected_task_id);
    }

    public function test_existing_matching_task_is_reused_instead_of_creating_new_task(): void
    {
        [$user, $plan] = $this->studyPlan();
        $this->scope($plan, '数学', '二次関数');

        $task = $this->task($plan, '二次関数を演習する');

        $result = app(StudyAdaptiveActionService::class)->evaluate($plan);

        $action = $result->primaryAction();

        $this->assertSame('establish_scope_baseline', $result->decision->type);
        $this->assertSame($task->id, data_get($action?->metadata, 'target_task_id'));
        $this->assertSame('study_practice', data_get($action?->metadata, 'route_kind'));

        $this->actingAs($user)
            ->post(route('plans.study_action.execute', $plan))
            ->assertRedirect(route('plans.tasks.study_practice.show', [$plan, $task]));

        $this->assertDatabaseCount('tasks', 1);

        $projection = IntelligenceActionProjection::query()
            ->where('plan_id', $plan->id)
            ->where('status', IntelligenceActionProjection::STATUS_ACTIVE)
            ->firstOrFail();

        // Reuse does not create a duplicate Task.
        $this->assertNull($projection->projected_task_id);
    }

    public function test_action_creates_task_only_when_user_executes_and_is_idempotent(): void
    {
        [$user, $plan] = $this->studyPlan();
        $this->scope($plan, '英語', 'Lesson 4');

        $result = app(StudyAdaptiveActionService::class)->evaluate($plan);
        $action = $result->primaryAction();

        $this->assertSame('project_task', data_get($action?->metadata, 'route_kind'));
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('intelligence_action_projections', 0);

        $first = $this->actingAs($user)
            ->post(route('plans.study_action.execute', $plan));

        $task = Task::firstOrFail();

        $first->assertRedirect(route('plans.tasks.study_practice.show', [$plan, $task]));

        $this->assertSame(0, $task->estimated_minutes);
        $this->assertSame(0, $task->remaining_minutes);
        $this->assertSame(0, $task->progress_percent);

        $projections = IntelligenceActionProjection::query()
            ->where('plan_id', $plan->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $projections);
        $this->assertSame($task->id, $projections[0]->projected_task_id);
        $this->assertSame(
            IntelligenceActionProjection::STATUS_SUPERSEDED,
            $projections[0]->status,
        );
        $this->assertSame(
            IntelligenceActionProjection::STATUS_ACTIVE,
            $projections[1]->status,
        );
        $this->assertSame(
            $task->id,
            data_get($projections[1]->metadata, 'target_task_id'),
        );

        $this->actingAs($user)
            ->post(route('plans.study_action.execute', $plan))
            ->assertRedirect(route('plans.tasks.study_practice.show', [$plan, $task]));

        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('intelligence_action_projections', 2);
    }

    public function test_new_evidence_supersedes_previous_action_when_best_action_changes(): void
    {
        [, $plan] = $this->studyPlan();
        $this->scope($plan, '数学', '二次関数');
        $task = $this->task($plan, '二次関数を演習する');

        $service = app(StudyAdaptiveActionService::class);

        $baseline = $service->refresh($plan, now());
        $this->assertSame('establish_scope_baseline', $baseline->decision->type);

        Carbon::setTestNow('2026-10-04 12:10:00');

        $this->practiceEvidence(
            $task,
            92,
            strengths: ['二次関数'],
        );

        $retention = $service->refresh($plan, now());

        $this->assertSame('verify_scope_retention', $retention->decision->type);

        $actions = IntelligenceActionProjection::query()
            ->where('plan_id', $plan->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $actions);
        $this->assertSame(
            IntelligenceActionProjection::STATUS_SUPERSEDED,
            $actions[0]->status,
        );
        $this->assertNotNull($actions[0]->superseded_at);
        $this->assertSame(
            IntelligenceActionProjection::STATUS_ACTIVE,
            $actions[1]->status,
        );
        $this->assertSame('study_retention_check', $actions[1]->kind);
    }

    public function test_same_semantic_state_does_not_duplicate_action_history(): void
    {
        [, $plan] = $this->studyPlan();
        $this->scope($plan, '数学', '二次関数');
        $this->task($plan, '二次関数を演習する');

        $service = app(StudyAdaptiveActionService::class);

        $first = $service->refresh($plan, now());

        Carbon::setTestNow('2026-10-04 13:00:00');
        $second = $service->refresh($plan, now());

        $this->assertSame(
            $first->projection?->action_reference,
            $second->projection?->action_reference,
        );
        $this->assertDatabaseCount('intelligence_action_projections', 1);

        // Decision trace preserves the two observed snapshots even when the
        // current semantic Action remains the same.
        $this->assertDatabaseCount('intelligence_decision_traces', 2);
    }

    public function test_done_task_is_not_reused_as_current_action_execution_target(): void
    {
        [, $plan] = $this->studyPlan();
        $this->scope($plan, '数学', '二次関数');

        $this->task(
            $plan,
            '二次関数を演習する',
            status: 'done',
            progress: 100,
        );

        $result = app(StudyAdaptiveActionService::class)->evaluate($plan);
        $action = $result->primaryAction();

        $this->assertNull(data_get($action?->metadata, 'target_task_id'));
        $this->assertSame('project_task', data_get($action?->metadata, 'route_kind'));
    }

    public function test_home_uses_intelligence_action_when_primary_guidance_plan_is_study(): void
    {
        [$user, $plan] = $this->studyPlan();
        $this->scope($plan, '数学', '二次関数');
        $task = $this->task($plan, '二次関数を演習する');

        $guidance = collect([[
            'plan' => $plan,
            'task' => $task,
            'adaptive' => null,
            'recommended_tool' => null,
            'priority_evaluation' => [
                'priority' => 1,
                'mode' => 'auto',
            ],
        ]]);

        $plan->load('tasks');

        $home = app(StudyAdaptiveHomeActionService::class)->primary(
            collect([$plan]),
            $guidance,
        );

        $this->assertNotNull($home);
        $this->assertSame($plan->id, $home['plan']->id);
        $this->assertSame('study_baseline_check', $home['action']->kind);
        $this->assertSame($task->id, $home['target_task']->id);
        $this->assertSame(
            route('plans.study_action.execute', $plan),
            $home['execute_url'],
        );
    }

    public function test_action_trace_keeps_target_metadata_but_not_raw_learning_payloads(): void
    {
        [, $plan] = $this->studyPlan();
        $this->scope($plan, '数学', '二次関数');
        $task = $this->task($plan, '二次関数を演習する');

        $this->practiceEvidence(
            $task,
            45,
            weaknesses: ['二次関数'],
            weaknessTopics: ['二次関数'],
        );

        app(StudyAdaptiveActionService::class)->refresh($plan, now());

        $trace = IntelligenceDecisionTrace::latest('id')->firstOrFail();

        $this->assertSame(
            $task->id,
            data_get($trace->decision_metadata, 'target_task_id'),
        );
        $this->assertSame(
            '二次関数',
            data_get($trace->decision_metadata, 'unit'),
        );

        $projection = IntelligenceActionProjection::firstOrFail();

        $this->assertArrayNotHasKey('questions', $projection->metadata ?? []);
        $this->assertArrayNotHasKey('answers', $projection->metadata ?? []);
        $this->assertArrayNotHasKey('provider_payload', $projection->metadata ?? []);
    }

    private function studyPlan(): array
    {
        $user = User::factory()->create();

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => '定期テスト対策',
            'category' => '定期テスト学習',
            'priority' => 1,
            'priority_mode' => 'auto',
            'start_date' => '2026-10-01',
            'deadline' => '2026-10-20',
            'is_public' => false,
        ]);

        return [$user, $plan];
    }

    private function scope(
        Plan $plan,
        string $subject,
        string $unit,
    ): StudyScopeItem {
        $capture = StudyScopeCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $plan->user_id,
            'status' => 'confirmed',
            'exam_title' => '中間テスト',
            'exam_date' => '2026-10-20',
            'confidence' => 1,
            'extraction_version' => 'study_scope_v1',
            'confirmed_at' => now(),
        ]);

        return StudyScopeItem::query()->create([
            'study_scope_capture_id' => $capture->id,
            'plan_id' => $plan->id,
            'subject' => $subject,
            'unit' => $unit,
            'range_text' => $unit.'の範囲',
            'confidence' => 1,
            'sort_order' => 0,
        ]);
    }

    private function task(
        Plan $plan,
        string $title,
        string $status = 'todo',
        int $progress = 0,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 60,
            'remaining_minutes' => $status === 'done' ? 0 : 60,
            'progress_percent' => $progress,
            'status' => $status,
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => $plan->tasks()->count() + 1,
        ]);
    }

    private function practiceEvidence(
        Task $task,
        int $score,
        array $strengths = [],
        array $weaknesses = [],
        array $weaknessTopics = [],
    ): TaskEvidence {
        return TaskEvidence::query()->create([
            'plan_id' => $task->plan_id,
            'task_id' => $task->id,
            'user_id' => $task->plan?->user_id,
            'source' => EvidenceSource::Native->value,
            'type' => 'study_practice_assessed',
            'external_key' => 'practice:'.Str::uuid(),
            'confidence' => 1,
            'occurred_at' => now(),
            'metadata' => [
                'score_percent' => $score,
                'strengths' => $strengths,
                'weaknesses' => $weaknesses,
                'weakness_topics' => $weaknessTopics,
                'questions' => ['must not cross Intelligence boundary'],
                'answers' => ['must not cross Intelligence boundary'],
                'provider_payload' => ['secret' => 'must not cross'],
            ],
        ]);
    }
}
