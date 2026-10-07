<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\UserPersonalizationContext;
use App\Services\PlanCompletionFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlanCompletionFingerprintV5830Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_fingerprint_ignores_runtime_completion_state_but_changes_for_material_task_edit(): void
    {
        $user = $this->personalizedUser();
        $plan = $this->plan($user);
        $task = $this->task($plan, '同じTask');

        $service = app(PlanCompletionFingerprintService::class);

        $before = $service->snapshot($plan);

        $task->forceFill([
            'status' => 'done',
            'progress_percent' => 100,
            'remaining_minutes' => 0,
        ])->save();

        $runtimeChanged = $service->snapshot($plan);

        $this->assertSame(
            $before['fingerprint'],
            $runtimeChanged['fingerprint'],
        );

        $task->forceFill([
            'title' => '内容を変えたTask',
        ])->save();

        $materialChanged = $service->snapshot($plan);

        $this->assertNotSame(
            $before['fingerprint'],
            $materialChanged['fingerprint'],
        );
    }

    public function test_first_completion_stores_revision_one_and_refresh_metadata(): void
    {
        $user = $this->personalizedUser();
        $plan = $this->plan($user);
        $task = $this->task($plan, '完了Task');

        $this->actingAs($user)
            ->put(
                route('tasks.update', $task),
                $this->taskPayload(
                    $task,
                    status: 'done',
                    progress: 100,
                ),
            )
            ->assertRedirect(route('plans.show', $plan));

        $context = $this->context($user);
        $snapshot = data_get(
            $context->observed_context,
            'plan_lifecycle.completion_snapshots.'.$plan->id,
        );

        $this->assertIsArray($snapshot);
        $this->assertSame(1, data_get($snapshot, 'revision'));
        $this->assertSame(
            'first_completion',
            data_get($snapshot, 'change'),
        );
        $this->assertSame(1, data_get($snapshot, 'task_count'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($snapshot, 'fingerprint'),
        );

        $event = $this->completionEvents($plan)->firstOrFail();

        $this->assertSame(
            1,
            data_get($event->metadata, 'completion_revision'),
        );
        $this->assertSame(
            'first_completion',
            data_get($event->metadata, 'completion_change'),
        );
    }

    public function test_reopen_and_recomplete_without_material_change_does_not_create_new_revision(): void
    {
        $user = $this->personalizedUser();
        $plan = $this->plan($user);
        $task = $this->task($plan, '同一構造');

        $this->complete($user, $plan, $task);

        $task->refresh();
        $this->actingAs($user)
            ->put(
                route('tasks.update', $task),
                $this->taskPayload(
                    $task,
                    status: 'doing',
                    progress: 80,
                ),
            )
            ->assertRedirect(route('plans.show', $plan));

        $task->refresh();
        $this->complete($user, $plan, $task);

        $context = $this->context($user);

        $this->assertSame(
            1,
            data_get(
                $context->observed_context,
                'plan_lifecycle.completion_snapshots.'.$plan->id.'.revision',
            ),
        );
        $this->assertCount(1, $this->completionEvents($plan));
    }

    public function test_material_edit_then_recomplete_creates_revision_two(): void
    {
        $user = $this->personalizedUser();
        $plan = $this->plan($user);
        $task = $this->task($plan, 'Revision 1');

        $this->complete($user, $plan, $task);

        $firstFingerprint = (string) data_get(
            $this->context($user)->observed_context,
            'plan_lifecycle.completion_snapshots.'.$plan->id.'.fingerprint',
        );

        $task->refresh();
        $payload = $this->taskPayload(
            $task,
            status: 'doing',
            progress: 70,
        );
        $payload['title'] = 'Revision 2';

        $this->actingAs($user)
            ->put(route('tasks.update', $task), $payload)
            ->assertRedirect(route('plans.show', $plan));

        $task->refresh();
        $this->complete($user, $plan, $task);

        $context = $this->context($user);
        $snapshot = data_get(
            $context->observed_context,
            'plan_lifecycle.completion_snapshots.'.$plan->id,
        );

        $this->assertSame(2, data_get($snapshot, 'revision'));
        $this->assertSame(
            'material_revision',
            data_get($snapshot, 'change'),
        );
        $this->assertNotSame(
            $firstFingerprint,
            data_get($snapshot, 'fingerprint'),
        );
        $this->assertSame(
            2,
            data_get(
                $context->observed_context,
                'plan_lifecycle.last_completion_revision',
            ),
        );

        $events = $this->completionEvents($plan);
        $this->assertCount(2, $events);
        $this->assertSame(
            2,
            data_get(
                $events->last()->metadata,
                'completion_revision',
            ),
        );
        $this->assertSame(
            'material_revision',
            data_get(
                $events->last()->metadata,
                'completion_change',
            ),
        );
    }

    public function test_new_task_after_completion_is_a_material_completion_revision(): void
    {
        $user = $this->personalizedUser();
        $plan = $this->plan($user);
        $first = $this->task($plan, '既存Task');

        $this->complete($user, $plan, $first);

        $newTask = $this->task($plan, '後から追加したTask');
        $this->complete($user, $plan, $newTask);

        $context = $this->context($user);

        $this->assertSame(
            2,
            data_get(
                $context->observed_context,
                'plan_lifecycle.completion_snapshots.'.$plan->id.'.revision',
            ),
        );
        $this->assertSame(
            2,
            data_get(
                $context->observed_context,
                'plan_lifecycle.completion_snapshots.'.$plan->id.'.task_count',
            ),
        );
        $this->assertCount(2, $this->completionEvents($plan));
    }

    public function test_legacy_v5829_memory_is_baselined_without_false_completion_event(): void
    {
        $user = $this->personalizedUser();
        $plan = $this->plan($user);
        $task = $this->task($plan, 'Legacy Plan');

        $context = $this->context($user);
        $context->forceFill([
            'observed_context' => [
                ...(array) $context->observed_context,
                'plan_lifecycle' => [
                    'recent_completed_plan_ids' => [$plan->id],
                    'last_completed_plan_id' => $plan->id,
                ],
            ],
        ])->save();

        $this->complete($user, $plan, $task);

        $context = $this->context($user);

        $this->assertSame(
            'legacy_baseline',
            data_get(
                $context->observed_context,
                'plan_lifecycle.completion_snapshots.'.$plan->id.'.change',
            ),
        );
        $this->assertSame(
            1,
            data_get(
                $context->observed_context,
                'plan_lifecycle.completion_snapshots.'.$plan->id.'.revision',
            ),
        );
        $this->assertCount(0, $this->completionEvents($plan));

        $task->refresh();
        $payload = $this->taskPayload(
            $task,
            status: 'doing',
            progress: 60,
        );
        $payload['description'] = 'legacy baseline後に意味のある変更';

        $this->actingAs($user)
            ->put(route('tasks.update', $task), $payload)
            ->assertRedirect(route('plans.show', $plan));

        $task->refresh();
        $this->complete($user, $plan, $task);

        $context = $this->context($user);

        $this->assertSame(
            2,
            data_get(
                $context->observed_context,
                'plan_lifecycle.completion_snapshots.'.$plan->id.'.revision',
            ),
        );
        $this->assertCount(1, $this->completionEvents($plan));
    }

    public function test_cancelling_last_incomplete_task_can_complete_plan_without_marking_cancelled_task_as_completed(): void
    {
        $user = $this->personalizedUser();
        $plan = $this->plan($user);
        $done = $this->task($plan, '完了済みTask');
        $cancelled = $this->task($plan, '不要になったTask');

        $this->complete($user, $plan, $done);
        $this->assertCount(0, $this->completionEvents($plan));

        $cancelled->refresh();

        $this->actingAs($user)
            ->put(
                route('tasks.update', $cancelled),
                $this->taskPayload(
                    $cancelled,
                    status: 'cancelled',
                    progress: 0,
                ),
            )
            ->assertRedirect(route('plans.show', $plan));

        $context = $this->context($user);

        $this->assertCount(1, $this->completionEvents($plan));
        $this->assertSame(
            $cancelled->id,
            data_get(
                $context->observed_context,
                'plan_lifecycle.last_completion_trigger_task_id',
            ),
        );
        $this->assertNull(data_get(
            $context->observed_context,
            'plan_lifecycle.last_completed_task_id',
        ));
        $this->assertSame(
            1,
            data_get(
                $context->observed_context,
                'plan_lifecycle.completion_snapshots.'.$plan->id.'.task_count',
            ),
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
            'title' => 'Completion Fingerprint Plan',
            'description' => 'V58.30',
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
            'description' => '構造Fingerprint対象',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }

    private function complete(
        User $user,
        Plan $plan,
        Task $task,
    ): void {
        $task->refresh();

        $this->actingAs($user)
            ->put(
                route('tasks.update', $task),
                $this->taskPayload(
                    $task,
                    status: 'done',
                    progress: 100,
                ),
            )
            ->assertRedirect(route('plans.show', $plan));
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
                : max(0, (int) $task->remaining_minutes),
            'progress_percent' => $progress,
            'status' => $status,
            'priority' => $task->priority,
            'activation_cost' => $task->activation_cost,
            'next_action_note' => $task->next_action_note,
        ];
    }

    private function context(
        User $user,
    ): UserPersonalizationContext {
        return UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();
    }

    private function completionEvents(Plan $plan)
    {
        return BehaviorEvent::query()
            ->where(
                'event_type',
                BehaviorEventType::ContextRefreshTriggered->value,
            )
            ->where('plan_id', $plan->id)
            ->get()
            ->filter(
                fn (BehaviorEvent $event) =>
                    data_get($event->metadata, 'trigger')
                        === 'plan_completed',
            )
            ->values();
    }
}
