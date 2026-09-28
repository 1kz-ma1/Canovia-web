<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanoviaMapPrimaryLaunchV434Test extends TestCase
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

    public function test_map_overview_exposes_one_tap_primary_launch_and_keeps_context_focus_separate(): void
    {
        [$user, $task] = $this->scenario();

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('data-map-direct-primary-launch', false)
            ->assertSee('data-map-classic-action', false)
            ->assertSee('data-map-action-role="primary"', false)
            ->assertSee('data-map-node-id="task:'.$task->id.'"', false)
            ->assertSee('data-map-node-type="task"', false)
            ->assertSee('data-map-position-role="now"', false)
            ->assertSee('data-map-is-primary="1"', false)
            ->assertSee('そのまま進める')
            ->assertSee('Contextを見る');
    }

    public function test_map_without_primary_action_does_not_render_direct_launch(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->assertDontSee('data-map-direct-primary-launch', false)
            ->assertSee('まだMapに置くActionがありません');
    }

    private function scenario(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Primary Launchを検証する',
            'description' => 'Mapから実行先へ最短で移動する',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '今やるTaskを直接始める',
            'description' => 'Focusせずに既存の実行先へ入れるようにする',
            'estimated_minutes' => 45,
            'remaining_minutes' => 45,
            'progress_percent' => 15,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        return [$user, $task];
    }
}
