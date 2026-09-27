<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanoviaMapFocusV421Test extends TestCase
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

    public function test_map_renders_focus_hooks_and_contextual_classic_surface_actions(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Living Mapを育てる',
            'description' => 'Contextから必要操作だけを出す',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Focus Modeを実装する',
            'description' => '1-hopだけを表示する',
            'estimated_minutes' => 90,
            'remaining_minutes' => 60,
            'progress_percent' => 30,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'next_action_note' => 'Contextual Classic Surfaceまでつなぐ',
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('data-map-workspace', false)
            ->assertSee('data-map-context-surface', false)
            ->assertSee('data-map-context-content', false)
            ->assertSee('data-map-focus-reset', false)
            ->assertSee('data-map-surface-template="task:'.$task->id.'"', false)
            ->assertSee('Contextual Classic Surface')
            ->assertSee('Taskを編集')
            ->assertSee(route('tasks.edit', $task), false)
            ->assertSee('data-map-edge-source="plan:'.$plan->id.'"', false)
            ->assertSee('data-map-edge-target="task:'.$task->id.'"', false);
    }

    public function test_map_nodes_keep_real_href_as_no_javascript_fallback(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Fallback Plan',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addWeek(),
            'is_public' => false,
        ]);

        Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Fallback Task',
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('href="'.route('plans.show', $plan).'"', false)
            ->assertSee('data-map-node', false);
    }
}
