<?php

namespace Tests\Feature;

use App\Enums\MapLevel;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MapContextualShortcutPinningV487Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_weak_plan_can_be_pinned_from_l2_plan_context_then_appears_on_l0(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '弱いsignalでも自分で固定するPlan', 5);
        $task = $this->task($plan, '未着手Task', 'todo', 0);

        $response = $this->actingAs($user)->get(route('map.index', [
            'level' => MapLevel::Plan->value,
            'intent' => 'plan',
            'plan' => $plan->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('このPlanを全体へ固定')
            ->assertSee('data-map-shortcut-pin-context', false);

        $graph = $response->viewData('graph');
        $planNode = $graph['nodes']->firstWhere('id', 'plan:'.$plan->id);

        $this->assertIsArray($planNode);
        $this->assertTrue((bool) data_get($planNode, 'shortcut_pin.eligible'));
        $this->assertFalse((bool) data_get($planNode, 'shortcut_pin.pinned'));
        $this->assertSame('available', data_get($planNode, 'shortcut_pin.status'));

        $before = [
            'priority' => (int) $plan->priority,
            'status' => (string) $task->status,
            'progress' => (int) $task->progress_percent,
        ];

        $this->actingAs($user)
            ->post(route('map.personalization.pins.store', $plan))
            ->assertRedirect(route('map.index'));

        $l0 = $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->viewData('graph');

        $shortcut = $l0['nodes']->firstWhere('id', 'satellite:plan:'.$plan->id);

        $this->assertIsArray($shortcut);
        $this->assertSame('manual_pin', data_get($shortcut, 'personalization.mode'));

        $plan->refresh();
        $task->refresh();

        $this->assertSame($before, [
            'priority' => (int) $plan->priority,
            'status' => (string) $task->status,
            'progress' => (int) $task->progress_percent,
        ]);
    }

    public function test_pinned_plan_can_be_unpinned_from_l3_plan_context(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'Executionから解除するPlan', 3);
        $this->task($plan, '進行中Task', 'doing', 30);

        $this->actingAs($user)
            ->post(route('map.personalization.pins.store', $plan))
            ->assertRedirect(route('map.index'));

        $response = $this->actingAs($user)->get(route('map.index', [
            'level' => MapLevel::Execution->value,
            'intent' => 'execution',
            'plan' => $plan->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('全体への固定を解除')
            ->assertSee('全体Mapの近道として固定されています');

        $graph = $response->viewData('graph');
        $planNode = $graph['nodes']->firstWhere('id', 'plan:'.$plan->id);

        $this->assertIsArray($planNode);
        $this->assertTrue((bool) data_get($planNode, 'shortcut_pin.eligible'));
        $this->assertTrue((bool) data_get($planNode, 'shortcut_pin.pinned'));
        $this->assertSame('pinned', data_get($planNode, 'shortcut_pin.status'));
    }

    public function test_completed_plan_keeps_stale_pin_visible_for_cleanup_but_not_l0_projection(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '完了済みPinを整理するPlan', 2);
        $task = $this->task($plan, '完了するTask', 'doing', 20);

        $this->actingAs($user)
            ->post(route('map.personalization.pins.store', $plan))
            ->assertRedirect(route('map.index'));

        $task->forceFill([
            'status' => 'done',
            'progress_percent' => 100,
            'remaining_minutes' => 0,
        ])->save();

        $response = $this->actingAs($user)->get(route('map.index', [
            'level' => MapLevel::Plan->value,
            'intent' => 'plan',
            'plan' => $plan->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('現在は全体Mapの表示対象外')
            ->assertSee('全体への固定を解除');

        $graph = $response->viewData('graph');
        $planNode = $graph['nodes']->firstWhere('id', 'plan:'.$plan->id);

        $this->assertIsArray($planNode);
        $this->assertFalse((bool) data_get($planNode, 'shortcut_pin.eligible'));
        $this->assertTrue((bool) data_get($planNode, 'shortcut_pin.pinned'));
        $this->assertSame('inactive', data_get($planNode, 'shortcut_pin.status'));

        $l0 = $this->actingAs($user)
            ->get(route('map.index'))
            ->viewData('graph');

        $this->assertNull(
            $l0['nodes']->firstWhere('id', 'satellite:plan:'.$plan->id)
        );
    }

    public function test_shared_plan_does_not_receive_contextual_pin_controls(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'Shared Plan', 1, collaborative: true);
        $this->task($plan, '共同Task', 'doing', 10);

        $response = $this->actingAs($user)->get(route('map.index', [
            'level' => MapLevel::Plan->value,
            'intent' => 'plan',
            'plan' => $plan->id,
        ]));

        $response->assertOk();

        $graph = $response->viewData('graph');
        $planNode = $graph['nodes']->firstWhere('id', 'plan:'.$plan->id);

        $this->assertIsArray($planNode);
        $this->assertArrayNotHasKey('shortcut_pin', $planNode);
    }

    private function plan(
        User $user,
        string $title,
        int $priority,
        bool $collaborative = false,
    ): Plan {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => '個人開発',
            'priority' => $priority,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => $collaborative,
        ]);
    }

    private function task(
        Plan $plan,
        string $title,
        string $status,
        int $progress,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 60,
            'remaining_minutes' => $progress >= 100 ? 0 : 60,
            'progress_percent' => $progress,
            'status' => $status,
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }
}
