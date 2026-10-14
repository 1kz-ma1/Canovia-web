<?php

namespace Tests\Feature;

use App\Models\GuidedExecution;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class GuidedExecutionNextActionV67Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function setupTask(): array
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Webアプリを公開',
            'category' => '個人開発',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $task = Task::create([
            'plan_id' => $plan->id,
            'title' => 'ログイン導線を確認',
            'next_action_note' => 'まずログインを確認する',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);
        return [$user, $plan, $task];
    }

    private function reflect(User $owner, Plan $plan, Task $task, string $nextAction): GuidedExecution
    {
        $this->actingAs($owner)->post(route('plans.tasks.guided_execution.prepare', [$plan, $task]), [
            'prepare_request_id' => (string) Str::uuid(),
            'intent' => '実機でログインの導線を観察する',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $execution = GuidedExecution::query()->where('task_id', $task->id)->latest('id')->firstOrFail();
        $this->actingAs($owner)->post(route('plans.tasks.guided_execution.reflect', [$plan, $task, $execution]), [
            'reflection_request_id' => (string) Str::uuid(),
            'outcome_rating' => 'partly',
            'actual_outcome' => 'ログインは動くが復帰先が正しくない',
            'next_adjustment' => $nextAction,
        ])->assertRedirect()->assertSessionHasNoErrors();

        return $execution->fresh();
    }

    private function confirm(User $user, Plan $plan, Task $task, GuidedExecution $execution, string $before)
    {
        return $this->actingAs($user)->post(
            route('plans.tasks.guided_execution.confirm_next_action', [$plan, $task, $execution]),
            ['expected_next_action_hash' => hash('sha256', $before)],
        );
    }

    public function test_explicit_confirmation_sets_only_next_action_and_repeated_post_is_idempotent(): void
    {
        [$owner, $plan, $task] = $this->setupTask();
        $adjustment = 'セッション切れ後の復帰先を修正する';
        $execution = $this->reflect($owner, $plan, $task, $adjustment);
        $this->assertSame('まずログインを確認する', $task->fresh()->next_action_note);
        $this->assertSame(20, $task->fresh()->progress_percent);

        $this->actingAs($owner)->get(route('plans.tasks.guided_execution.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('data-reflection-next-action', false)
            ->assertSee('この内容を次のActionに反映する');
        $this->confirm($owner, $plan, $task, $execution, 'まずログインを確認する')
            ->assertRedirect(route('plans.tasks.guided_execution.show', [$plan, $task]))
            ->assertSessionHasNoErrors();
        $this->assertSame($adjustment, $task->fresh()->next_action_note);
        $this->assertSame(20, $task->fresh()->progress_percent);
        $this->assertSame('doing', $task->fresh()->status);
        $this->assertSame(1, TaskEvidence::query()->where('task_id', $task->id)->count());
        $this->actingAs($owner)->get(route('plans.tasks.guided_execution.show', [$plan, $task]))
            ->assertOk()->assertSee('data-reflection-next-action-adopted', false);
        $this->confirm($owner, $plan, $task, $execution, 'まずログインを確認する')
            ->assertRedirect();
        $this->assertSame(1, TaskEvidence::query()->where('task_id', $task->id)->count());
        $this->assertSame(1, Task::query()->where('plan_id', $plan->id)->count());
    }

    public function test_stale_form_cannot_overwrite_newer_task_note(): void
    {
        [$owner, $plan, $task] = $this->setupTask();
        $execution = $this->reflect($owner, $plan, $task, '新しい確認作業を試す');
        $task->update(['next_action_note' => '他の画面から確定されたAction']);
        $this->confirm($owner, $plan, $task, $execution, 'まずログインを確認する')
            ->assertStatus(409);
        $this->assertSame('他の画面から確定されたAction', $task->fresh()->next_action_note);
    }

    public function test_older_reflection_cannot_replace_latest_reflection(): void
    {
        [$owner, $plan, $task] = $this->setupTask();
        $old = $this->reflect($owner, $plan, $task, '古い改善');
        $new = $this->reflect($owner, $plan, $task, '新しい改善');
        $this->confirm($owner, $plan, $task, $old, 'まずログインを確認する')
            ->assertStatus(409);
        $this->confirm($owner, $plan, $task, $new, 'まずログインを確認する')
            ->assertRedirect();
        $this->assertSame('新しい改善', $task->fresh()->next_action_note);
        $this->assertSame(2, TaskEvidence::query()->where('task_id', $task->id)->count());
    }

    public function test_foreign_actor_cannot_apply_or_read_reflection_and_task_not_modified(): void
    {
        [$owner, $plan, $task] = $this->setupTask();
        $execution = $this->reflect($owner, $plan, $task, '非公開の改善');
        $stranger = User::factory()->create();
        $this->confirm($stranger, $plan, $task, $execution, 'まずログインを確認する')
            ->assertForbidden();
        $this->actingAs($stranger)->get(route('plans.tasks.guided_execution.show', [$plan, $task]))
            ->assertForbidden();
        $this->assertSame('まずログインを確認する', $task->fresh()->next_action_note);
    }

    public function test_done_task_and_too_long_suggestion_cannot_be_promoted(): void
    {
        [$owner, $plan, $task] = $this->setupTask();
        $execution = $this->reflect($owner, $plan, $task, '作業を続ける');
        $task->update(['status' => 'done', 'progress_percent' => 100]);
        $this->confirm($owner, $plan, $task, $execution, 'まずログインを確認する')
            ->assertStatus(409);
        $this->assertSame('まずログインを確認する', $task->fresh()->next_action_note);
    }
}
