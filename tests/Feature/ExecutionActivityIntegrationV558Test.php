<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Execution\ExecutionCapability;
use App\Intelligence\Study\StudyPlanIntelligenceService;
use App\Models\ExecutionActivity;
use App\Models\Plan;
use App\Models\PlanExecutionPreference;
use App\Models\StudyScopeCapture;
use App\Models\StudyScopeItem;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Services\ExecutionActivityService;
use App\Services\ExecutionLaunchResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExecutionActivityIntegrationV558Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'session.driver' => 'array',
            'canovia.execution_setup_validation_enabled' => true,
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_validation_provider_result_flows_through_activity_evidence_and_study_intelligence(): void
    {
        [$user, $plan, $task] = $this->studyPracticeContext(progress: 17);
        $activityKey = (string) Str::uuid();

        $this->actingAs($user)
            ->get(route('execution.validation.study_practice', [$plan, $task]))
            ->assertOk()
            ->assertSee('data-execution-activity-result-form', false)
            ->assertSee('Practice結果をCanoviaへ返す');

        $this->actingAs($user)
            ->post(
                route(
                    'execution.validation.study_practice.result',
                    [$plan, $task],
                ),
                [
                    'activity_key' => $activityKey,
                    'score_percent' => 88,
                    'duration_minutes' => 25,
                    'strengths' => "TCP/IP\nCIDR",
                    'weaknesses' => 'DNS',
                ],
            )
            ->assertRedirect(
                route('workspace.study.index', ['plan_id' => $plan->id]),
            );

        $activity = ExecutionActivity::query()->firstOrFail();

        $this->assertSame(
            ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
            $activity->provider_key,
        );
        $this->assertSame(
            ExecutionCapability::STUDY_PRACTICE,
            $activity->capability,
        );
        $this->assertSame('study_practice_completed', $activity->type);
        $this->assertSame('completed', $activity->status);
        $this->assertSame(1500, $activity->duration_seconds);
        $this->assertSame(88, data_get($activity->metrics, 'score_percent'));
        $this->assertSame(
            ['TCP/IP', 'CIDR'],
            data_get($activity->metrics, 'strengths'),
        );
        $this->assertSame(
            ['DNS'],
            data_get($activity->metrics, 'weaknesses'),
        );
        $this->assertTrue(
            (bool) data_get($activity->metadata, 'validation_surface'),
        );
        $this->assertSame($plan->id, (int) $activity->plan_id);
        $this->assertSame($task->id, (int) $activity->task_id);
        $this->assertNotNull($activity->task_evidence_id);
        $this->assertNotNull($activity->linked_at);

        $evidence = TaskEvidence::query()->findOrFail(
            $activity->task_evidence_id,
        );

        $this->assertSame(EvidenceSource::External, $evidence->source);
        $this->assertSame('study_practice_assessed', $evidence->type);
        $this->assertSame(
            'execution-activity:'.$activity->id,
            $evidence->external_key,
        );
        $this->assertSame(88, data_get($evidence->metadata, 'score_percent'));
        $this->assertSame(
            ['TCP/IP', 'CIDR'],
            data_get($evidence->metadata, 'strengths'),
        );
        $this->assertSame(
            ['DNS'],
            data_get($evidence->metadata, 'weaknesses'),
        );
        $this->assertSame(
            ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
            data_get($evidence->metadata, 'provider_key'),
        );
        $this->assertArrayNotHasKey(
            'validation_surface',
            (array) $evidence->metadata,
        );
        $this->assertArrayNotHasKey(
            'metrics',
            (array) $evidence->metadata,
        );

        $this->assertSame(17, (int) $task->fresh()->progress_percent);
        $this->assertSame('todo', $task->fresh()->status);

        $intelligence = app(StudyPlanIntelligenceService::class)
            ->evaluate($plan);

        $this->assertSame(
            1,
            (int) data_get(
                $intelligence->state->metrics,
                'practice_attempt_count',
            ),
        );
        $this->assertSame(
            88,
            data_get(
                $intelligence->state->metrics,
                'latest_score_percent',
            ),
        );
        $this->assertContains(
            'TCP/IP',
            (array) data_get(
                $intelligence->state->facts,
                'observed_strengths',
                [],
            ),
        );
        $this->assertContains(
            'DNS',
            (array) data_get(
                $intelligence->state->facts,
                'observed_weaknesses',
                [],
            ),
        );
    }

    public function test_provider_result_redelivery_is_idempotent_and_updates_same_evidence(): void
    {
        [$user, $plan, $task] = $this->studyPracticeContext();
        $activityKey = (string) Str::uuid();
        $route = route(
            'execution.validation.study_practice.result',
            [$plan, $task],
        );

        $this->actingAs($user)->post($route, [
            'activity_key' => $activityKey,
            'score_percent' => 72,
            'weaknesses' => 'TCP/IP',
        ])->assertRedirect();

        $activity = ExecutionActivity::query()->firstOrFail();
        $evidenceId = (int) $activity->task_evidence_id;

        $this->actingAs($user)->post($route, [
            'activity_key' => $activityKey,
            'score_percent' => 92,
            'strengths' => 'TCP/IP',
        ])->assertRedirect();

        $this->assertDatabaseCount('execution_activities', 1);
        $this->assertDatabaseCount('task_evidences', 1);

        $updated = ExecutionActivity::query()->firstOrFail();
        $evidence = TaskEvidence::query()->firstOrFail();

        $this->assertSame($activity->id, $updated->id);
        $this->assertSame($evidenceId, $updated->task_evidence_id);
        $this->assertSame($evidenceId, $evidence->id);
        $this->assertSame(92, data_get($updated->metrics, 'score_percent'));
        $this->assertSame(92, data_get($evidence->metadata, 'score_percent'));

        $intelligence = app(StudyPlanIntelligenceService::class)
            ->evaluate($plan);

        $this->assertSame(
            1,
            (int) data_get(
                $intelligence->state->metrics,
                'practice_attempt_count',
            ),
        );
        $this->assertSame(
            92,
            data_get(
                $intelligence->state->metrics,
                'latest_score_percent',
            ),
        );
    }

    public function test_non_completed_or_non_assessment_activity_remains_generic_evidence(): void
    {
        [$user, $plan, $task] = $this->studyPracticeContext();
        $activities = app(ExecutionActivityService::class);

        $activity = $activities->record(
            providerKey:
                ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
            capability: ExecutionCapability::STUDY_PRACTICE,
            type: 'study_practice_started',
            title: 'External Practice started',
            status: 'started',
            metrics: ['score_percent' => 99],
            metadata: ['provider_trace' => 'must stay outside evidence'],
            externalKey: 'started-'.Str::uuid(),
            userId: $user->id,
            startedAt: now(),
        );

        $linked = $activities->linkToTask($activity, $task);
        $evidence = TaskEvidence::query()->findOrFail(
            $linked->task_evidence_id,
        );

        $this->assertSame('execution_activity_observed', $evidence->type);
        $this->assertArrayNotHasKey(
            'provider_trace',
            (array) $evidence->metadata,
        );

        $intelligence = app(StudyPlanIntelligenceService::class)
            ->evaluate($plan);

        $this->assertSame(
            0,
            (int) data_get(
                $intelligence->state->metrics,
                'practice_attempt_count',
            ),
        );
        $this->assertNull(
            data_get(
                $intelligence->state->metrics,
                'latest_score_percent',
            ),
        );
    }

    public function test_result_intake_requires_selected_validation_provider(): void
    {
        [$user, $plan, $task] = $this->studyPracticeContext();

        PlanExecutionPreference::query()
            ->where('plan_id', $plan->id)
            ->where('capability', ExecutionCapability::STUDY_PRACTICE)
            ->update(['provider_key' => 'canovia.study.practice']);

        $this->actingAs($user)
            ->post(
                route(
                    'execution.validation.study_practice.result',
                    [$plan, $task],
                ),
                [
                    'activity_key' => (string) Str::uuid(),
                    'score_percent' => 80,
                ],
            )
            ->assertNotFound();

        $this->assertDatabaseCount('execution_activities', 0);
        $this->assertDatabaseCount('task_evidences', 0);
    }

    private function studyPracticeContext(
        int $progress = 0,
    ): array {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => '応用情報技術者試験',
            'description' => 'TCP/IPを重点学習',
            'category' => '資格学習',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);

        $capture = StudyScopeCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
            'exam_title' => '応用情報',
            'exam_date' => today()->addMonth(),
            'confidence' => 1,
            'extraction_version' => 'study_scope_v1',
            'confirmed_at' => now(),
        ]);

        StudyScopeItem::query()->create([
            'study_scope_capture_id' => $capture->id,
            'plan_id' => $plan->id,
            'subject' => 'ネットワーク',
            'unit' => 'TCP/IP',
            'range_text' => 'TCP/IP',
            'confidence' => 1,
            'sort_order' => 0,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'TCP/IPの過去問を解く',
            'description' => 'TCP/IPのPractice',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => $progress,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        PlanExecutionPreference::query()->create([
            'plan_id' => $plan->id,
            'capability' => ExecutionCapability::STUDY_PRACTICE,
            'provider_key' =>
                ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
            'user_selected' => true,
        ]);

        return [$user, $plan, $task];
    }
}
