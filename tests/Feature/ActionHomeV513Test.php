<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanActivityLog;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActionHomeV513Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_home_is_action_and_alert_surface_instead_of_plan_browser(): void
    {
        [$user] = $this->scenario();

        $response = $this->actingAs($user)->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('data-action-home', false)
            ->assertSee('data-action-home-guidance', false)
            ->assertSee('今やること')
            ->assertSee('data-home-collaboration', false)
            ->assertSee('data-action-home-recent', false)
            ->assertDontSee('data-dashboard-tab=', false)
            ->assertDontSee('data-dashboard-panel=', false)
            ->assertDontSee('data-plan-card-url=', false)
            ->assertDontSee('YOUR WORLDS');
    }

    public function test_home_detects_when_a_plan_changes_into_delayed_state(): void
    {
        [$user, $plan] = $this->scenario(
            startDate: today(),
            deadline: today()->addDays(10),
        );

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-action-home-signal="plan_status_changed"', false);

        $plan->update([
            'start_date' => today()->subDays(10),
            'deadline' => today()->addDays(10),
        ]);

        $response = $this->actingAs($user)->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('data-action-home-signal="plan_status_changed"', false)
            ->assertSee('予定通り → 遅れ気味')
            ->assertSee(route('navigation.index', ['plan_id' => $plan->id]), false);
    }

    public function test_home_surfaces_collaboration_activity_from_other_people_only(): void
    {
        [$owner, $plan] = $this->scenario();
        $peer = User::factory()->create(['name' => '共同メンバー']);
        $plan->update(['is_collaborative' => true]);

        PlanActivityLog::create([
            'plan_id' => $plan->id,
            'user_id' => $peer->id,
            'action' => 'task_created',
            'target_type' => 'task',
            'target_id' => 999,
            'metadata' => ['task_title' => '共同で追加したTask'],
            'created_at' => now(),
        ]);

        PlanActivityLog::create([
            'plan_id' => $plan->id,
            'user_id' => $owner->id,
            'action' => 'task_created',
            'target_type' => 'task',
            'target_id' => 1000,
            'metadata' => ['task_title' => '自分で追加したTask'],
            'created_at' => now()->addSecond(),
        ]);

        $response = $this->actingAs($owner)->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('data-action-home-signal="collaboration"', false)
            ->assertSee('共同メンバーさんがタスクを追加しました')
            ->assertSee('共同で追加したTask')
            ->assertDontSee('自分で追加したTask');
    }

    public function test_home_prompts_for_plan_creation_when_no_plan_exists(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-action-home-create-prompt', false)
            ->assertSee('計画を作る');
    }

    private function scenario(
        mixed $startDate = null,
        mixed $deadline = null,
    ): array {
        $user = User::factory()->create();

        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Action Homeテスト計画',
            'description' => 'Homeは今必要なActionだけを示す',
            'category' => 'その他',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => $startDate ?? today(),
            'deadline' => $deadline ?? today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::create([
            'plan_id' => $plan->id,
            'title' => '次に進めるTask',
            'description' => 'Action Homeから実行する',
            'next_action_note' => '次の一歩',
            'estimated_minutes' => 120,
            'remaining_minutes' => 120,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
