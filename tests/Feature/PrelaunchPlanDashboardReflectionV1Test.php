<?php

namespace Tests\Feature;

use App\Models\GuidedExecution;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PrelaunchPlanDashboardReflectionV1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'session.driver' => 'array',
            'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_owner_can_discover_saved_reflection_and_return_to_its_task_without_new_progress(): void
    {
        [$owner, $plan, $task] = $this->scenario();
        $outcome = 'ブラウザの表示を確認できたが、導線に迷った';

        $this->actingAs($owner)->get(route('plans.dashboard', $plan))
            ->assertOk()
            ->assertDontSee('data-plan-board-reflections', false);

        $this->actingAs($owner)->post(route('plans.tasks.guided_execution.prepare', [$plan, $task]), [
            'prepare_request_id' => (string) Str::uuid(),
            'intent' => 'はじめてのタスクを確認する',
        ])->assertSessionHasNoErrors();

        $execution = GuidedExecution::query()->where('task_id', $task->id)->sole();
        $this->actingAs($owner)->post(route('plans.tasks.guided_execution.reflect', [$plan, $task, $execution]), [
            'reflection_request_id' => (string) Str::uuid(),
            'outcome_rating' => 'partly',
            'actual_outcome' => $outcome,
            'next_adjustment' => '次は情報を整理してから試す',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, TaskEvidence::query()->where('task_id', $task->id)->count());
        $this->assertSame(0, (int) $task->fresh()->progress_percent);

        $response = $this->actingAs($owner)->get(route('plans.dashboard', $plan))
            ->assertOk()
            ->assertSee('data-plan-board-reflections', false)
            ->assertSee('data-plan-board-reflection-task="'.$task->id.'"', false)
            ->assertSee($outcome)
            ->assertSee('振り返りEvidence')
            ->assertSee('進捗率への自動反映なし')
            ->assertSee('href="'.route('plans.tasks.guided_execution.show', [$plan, $task]).'"', false)
            ->assertSee('時間を記録した作業はまだありません。');

        $this->assertCount(1, $response->viewData('workspace')['dashboard_board']['reflections']);
        $this->assertEmpty($response->viewData('workspace')['dashboard_board']['activity']);
    }

    public function test_shared_plan_cannot_expose_another_actor_reflection_or_link_to_editor_or_viewer(): void
    {
        [$owner, $plan, $task] = $this->scenario(collaborative: true);
        $editor = User::factory()->create(['first_run_completed_at' => now()]);
        $viewer = User::factory()->create(['first_run_completed_at' => now()]);
        foreach ([[$editor, PlanMember::ROLE_EDITOR], [$viewer, PlanMember::ROLE_VIEWER]] as [$member, $role]) {
            PlanMember::query()->create([
                'plan_id' => $plan->id,
                'user_id' => $member->id,
                'role' => $role,
                'invited_by_user_id' => $owner->id,
                'joined_at' => now(),
            ]);
        }

        $this->actingAs($owner)->post(route('plans.tasks.guided_execution.prepare', [$plan, $task]), [
            'intent' => '共同Planでも自分だけの振り返り',
        ])->assertSessionHasNoErrors();
        $execution = GuidedExecution::query()->where('task_id', $task->id)->sole();
        $secret = '本人専用の未共有メモ';
        $this->actingAs($owner)->post(route('plans.tasks.guided_execution.reflect', [$plan, $task, $execution]), [
            'outcome_rating' => 'partly',
            'actual_outcome' => $secret,
        ])->assertSessionHasNoErrors();

        $this->actingAs($owner)->get(route('plans.dashboard', $plan))
            ->assertOk()->assertSee($secret)->assertSee('data-plan-board-reflections', false);

        $this->actingAs($editor)->get(route('plans.dashboard', $plan))
            ->assertOk()->assertDontSee($secret)
            ->assertDontSee('data-plan-board-reflections', false);

        $this->actingAs($viewer)->get(route('plans.dashboard', $plan))
            ->assertOk()->assertDontSee($secret)
            ->assertDontSee('data-plan-board-reflections', false);
    }

    /** @return array{User,Plan,Task} */
    private function scenario(bool $collaborative = false): array
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => '初回Planの現在地',
            'description' => '初回体験',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => $collaborative,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '最初の画面を確認',
            'description' => '自分で進める',
            'estimated_minutes' => 45,
            'remaining_minutes' => 45,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$owner, $plan, $task];
    }
}
