<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\UserPersonalizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlanLifecyclePersonalizationV5829Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_new_plan_records_observed_lifecycle_and_refresh_trigger_once(): void
    {
        $user = $this->personalizedUser();
        $requestId = (string) Str::uuid();

        $response = $this->actingAs($user)
            ->post(route('plans.store'), [
                'title' => '新しい学習Plan',
                'description' => 'Lifecycle trigger test',
                'category' => '資格学習',
                'priority' => 1,
                'priority_mode' => 'manual',
                'start_date' => today()->toDateString(),
                'deadline' => today()->addMonth()->toDateString(),
                'create_request_id' => $requestId,
            ]);

        $plan = Plan::query()
            ->where('creation_request_id', $requestId)
            ->firstOrFail();

        $response->assertRedirect(
            route('workspace.study.index', ['plan_id' => $plan->id]),
        );

        $context = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(
            $plan->id,
            data_get(
                $context->observed_context,
                'plan_lifecycle.last_created_plan_id',
            ),
        );
        $this->assertNotNull(data_get(
            $context->observed_context,
            'plan_lifecycle.last_created_at',
        ));
        $this->assertSame(
            1,
            $this->refreshEventCount($plan, 'new_plan'),
        );

        $this->actingAs($user)
            ->post(route('plans.store'), [
                'title' => '新しい学習Plan',
                'description' => 'Lifecycle trigger test',
                'category' => '資格学習',
                'priority' => 1,
                'priority_mode' => 'manual',
                'start_date' => today()->toDateString(),
                'deadline' => today()->addMonth()->toDateString(),
                'create_request_id' => $requestId,
            ]);

        $this->assertSame(
            1,
            $this->refreshEventCount($plan, 'new_plan'),
        );
    }

    public function test_plan_completed_triggers_only_when_last_active_task_completes(): void
    {
        $user = $this->personalizedUser();
        $plan = $this->plan($user);
        $first = $this->task($plan, '最初のTask');
        $last = $this->task($plan, '最後のTask');

        $this->actingAs($user)
            ->put(route('tasks.update', $first), $this->taskPayload(
                $first,
                status: 'done',
                progress: 100,
            ))
            ->assertRedirect(route('plans.show', $plan));

        $this->assertSame(
            0,
            $this->refreshEventCount($plan, 'plan_completed'),
        );

        $this->actingAs($user)
            ->put(route('tasks.update', $last), $this->taskPayload(
                $last,
                status: 'done',
                progress: 100,
            ))
            ->assertRedirect(route('plans.show', $plan));

        $context = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(
            $plan->id,
            data_get(
                $context->observed_context,
                'plan_lifecycle.last_completed_plan_id',
            ),
        );
        $this->assertSame(
            $last->id,
            data_get(
                $context->observed_context,
                'plan_lifecycle.last_completed_task_id',
            ),
        );
        $this->assertContains(
            $plan->id,
            data_get(
                $context->observed_context,
                'plan_lifecycle.recent_completed_plan_ids',
                [],
            ),
        );
        $this->assertSame(
            1,
            $this->refreshEventCount($plan, 'plan_completed'),
        );
    }

    public function test_reopened_plan_does_not_repeat_completion_without_future_fingerprint_contract(): void
    {
        $user = $this->personalizedUser();
        $plan = $this->plan($user);
        $task = $this->task($plan, '単一Task');

        $this->actingAs($user)
            ->put(route('tasks.update', $task), $this->taskPayload(
                $task,
                status: 'done',
                progress: 100,
            ))
            ->assertRedirect(route('plans.show', $plan));

        $task->refresh();

        $this->actingAs($user)
            ->put(route('tasks.update', $task), $this->taskPayload(
                $task,
                status: 'doing',
                progress: 80,
            ))
            ->assertRedirect(route('plans.show', $plan));

        $task->refresh();

        $this->actingAs($user)
            ->put(route('tasks.update', $task), $this->taskPayload(
                $task,
                status: 'done',
                progress: 100,
            ))
            ->assertRedirect(route('plans.show', $plan));

        $this->assertSame(
            1,
            $this->refreshEventCount($plan, 'plan_completed'),
        );
    }

    public function test_user_without_personalization_context_is_not_silently_profiled(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $plan = $this->plan($user);
        $task = $this->task($plan, 'Task');

        $this->actingAs($user)
            ->put(route('tasks.update', $task), $this->taskPayload(
                $task,
                status: 'done',
                progress: 100,
            ))
            ->assertRedirect(route('plans.show', $plan));

        $this->assertDatabaseMissing(
            'user_personalization_contexts',
            ['user_id' => $user->id],
        );
        $this->assertSame(
            0,
            $this->refreshEventCount($plan, 'plan_completed'),
        );
    }

    private function personalizedUser(): User
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('personalization.store'), [
                'domains' => ['study'],
                'weekly_capacity' => '5_10',
                'study_goal' => '応用情報技術者試験 合格',
                'study_kind' => 'qualification',
                'study_stage' => 'started',
            ])
            ->assertRedirect(route('personalization.result'));

        return $user;
    }

    private function plan(User $user): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Lifecycle Plan',
            'description' => 'Plan completion trigger',
            'category' => '資格学習',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => null,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function taskPayload(
        Task $task,
        string $status,
        int $progress,
    ): array {
        return [
            'title' => $task->title,
            'description' => $task->description,
            'estimated_minutes' => $task->estimated_minutes,
            'remaining_minutes' => $status === 'done'
                ? 0
                : max(1, (int) $task->remaining_minutes),
            'progress_percent' => $progress,
            'status' => $status,
            'priority' => $task->priority,
            'activation_cost' => $task->activation_cost,
            'next_action_note' => $task->next_action_note,
        ];
    }

    private function refreshEventCount(
        Plan $plan,
        string $trigger,
    ): int {
        return BehaviorEvent::query()
            ->where(
                'event_type',
                BehaviorEventType::ContextRefreshTriggered->value,
            )
            ->where('plan_id', $plan->id)
            ->get()
            ->filter(
                fn (BehaviorEvent $event) =>
                    data_get($event->metadata, 'trigger') === $trigger,
            )
            ->count();
    }
}
