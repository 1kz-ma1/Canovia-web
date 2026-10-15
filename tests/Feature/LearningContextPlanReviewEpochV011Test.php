<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanAdjustment;
use App\Models\Task;
use App\Models\User;
use App\Services\LearningContextSourceCaptureService;
use App\Services\LearningContextSourceEpochService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LearningContextPlanReviewEpochV011Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'intelligence.learning_context_watch.epoch_shadow_enabled' => true,
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $user->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'PRIVATE_STUDY_PLAN',
            'category' => '資格学習', 'priority' => 1,
            'priority_mode' => 'manual', 'start_date' => today(),
            'deadline' => today()->addDays(12),
            'is_public' => false, 'is_collaborative' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id, 'title' => 'FIRST_STUDY_TASK',
            'status' => 'doing', 'progress_percent' => 25,
            'estimated_minutes' => 30, 'remaining_minutes' => 25,
            'priority' => 1, 'activation_cost' => 1, 'sort_order' => 10,
        ]);
        $second = Task::query()->create([
            'plan_id' => $plan->id, 'title' => 'SECOND_STUDY_TASK',
            'status' => 'doing', 'progress_percent' => 15,
            'estimated_minutes' => 30, 'remaining_minutes' => 25,
            'priority' => 1, 'activation_cost' => 1, 'sort_order' => 20,
        ]);
        return [$user, $plan, $task, $second];
    }

    private function apply(User $user, Plan $plan, array $operations, string $token): void
    {
        $proposal = [
            'token' => $token,
            'can_apply' => true,
            'action' => 'update_progress',
            'flow' => 'plan_update',
            'summary' => 'SYNTHETIC_PLAN_REVIEW_ONLY',
            'raw_json' => '{"flow":"plan_update"}',
            'operations' => $operations,
        ];
        $this->actingAs($user)
            ->withSession([
                'plan_review_drafts.'.$plan->id => [
                    'flow' => 'plan_update', 'prompt' => 'synthetic input only',
                ],
                'plan_review_proposals.'.$plan->id => $proposal,
            ])
            ->post(route('plans.review_assistant.apply', $plan), [
                'proposal_token' => $token,
                'selected_operations' => array_keys($operations),
                'return_to' => 'plan',
            ])->assertRedirect(route('plans.show', $plan))
            ->assertSessionHasNoErrors();
    }

    public function test_multi_operation_plan_review_records_each_actual_source_write_once(): void
    {
        [$owner, $plan, $task, $second] = $this->fixture();
        $source = app(LearningContextSourceCaptureService::class);
        $before = $source->capture($owner, $plan->id);
        $this->assertNotNull($before);
        $operations = [
            [
                'type' => 'update_plan', 'title' => 'PRIVATE_STUDY_CHANGED',
                'deadline' => today()->addDays(15)->toDateString(),
            ],
            [
                'type' => 'create_task', 'client_ref' => 'NEW_STUDY',
                'title' => 'THIRD_STUDY_TASK',
                'description' => '',
                'estimated_minutes' => 20, 'remaining_minutes' => 20,
                'progress_percent' => 0, 'progress_reason' => '',
                'next_action_note' => '', 'status' => 'todo',
                'priority' => 1, 'activation_cost' => 1,
            ],
            [
                'type' => 'update_task', 'task_id' => $task->id,
                'progress_percent' => 40, 'status' => 'doing',
            ],
            ['type' => 'cancel_task', 'task_id' => $second->id],
            [
                'type' => 'reorder_tasks',
                'items' => [
                    ['task_id' => $task->id],
                    ['task_id' => $second->id],
                    ['task_ref' => 'NEW_STUDY'],
                ],
            ],
        ];
        $this->apply($owner, $plan, $operations, 'synthetic_multi_'.Str::uuid());

        $created = Task::query()->where('plan_id', $plan->id)
            ->where('title', 'THIRD_STUDY_TASK')->sole();
        $this->assertSame(40, (int) $task->fresh()->progress_percent);
        $this->assertSame('cancelled', $second->fresh()->status);
        $this->assertSame(3, (int) $created->sort_order);
        $this->assertSame('PRIVATE_STUDY_CHANGED', $plan->fresh()->title);
        $this->assertSame(7,
            app(LearningContextSourceEpochService::class)->inspect($plan->id)['epoch']);
        $this->assertSame('task_updated',
            app(LearningContextSourceEpochService::class)->inspect($plan->id)['last_mutation_kind']);
        $this->assertNotSame($before['source_revision'],
            $source->capture($owner, $plan->id)['source_revision']);
        $this->assertDatabaseCount('plan_adjustments', 1);
    }

    public function test_plan_review_sessionless_retry_and_same_value_noop_do_not_double_count(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $token = 'synthetic-safe-update-'.Str::uuid();
        $operations = [['type' => 'update_task', 'task_id' => $task->id,
            'title' => 'FIRST_STUDY_TASK']];

        $this->apply($owner, $plan, $operations, $token);
        $this->assertNull(app(LearningContextSourceEpochService::class)->inspect($plan->id));
        $this->assertDatabaseCount('plan_adjustments', 1);

        $this->actingAs($owner)->post(route('plans.review_assistant.apply', $plan), [
            'proposal_token' => $token, 'selected_operations' => [0], 'return_to' => 'plan',
        ])->assertRedirect(route('plans.show', $plan));
        $this->assertNull(app(LearningContextSourceEpochService::class)->inspect($plan->id));
        $this->assertDatabaseCount('plan_adjustments', 1);
    }

    public function test_plan_review_work_log_progress_update_changes_task_and_epoch_once(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $this->apply($owner, $plan, [[
            'type' => 'create_work_log',
            'task_id' => $task->id,
            'worked_on' => today()->toDateString(),
            'actual_minutes' => 20,
            'progress_after_percent' => 50,
            'remaining_minutes_after' => 15,
            'progress_reason' => 'Synthetic graded progress',
            'difficulty' => null, 'memo' => null, 'outcome' => null,
        ]], 'synthetic_log_'.Str::uuid());
        $this->assertSame(50, (int) $task->fresh()->progress_percent);
        $this->assertSame(1, app(LearningContextSourceEpochService::class)
            ->inspect($plan->id)['epoch']);
        $this->assertDatabaseCount('work_logs', 1);
    }

    public function test_non_study_plan_review_does_not_allocate_shadow_epoch(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $plan->update(['category' => '個人開発']);
        $this->apply($owner, $plan, [[
            'type' => 'update_task', 'task_id' => $task->id,
            'title' => 'UNRELATED_DEVELOPMENT_TASK',
        ]], 'synthetic_dev_'.Str::uuid());
        $this->assertNull(app(LearningContextSourceEpochService::class)->inspect($plan->id));
    }
}
