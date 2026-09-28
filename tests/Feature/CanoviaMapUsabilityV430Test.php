<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanoviaMapUsabilityV430Test extends TestCase
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

    public function test_map_starts_with_compact_toolbar_and_one_tap_primary_focus_affordance(): void
    {
        [$user, $task] = $this->scenario();

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('canovia-map-toolbar', false)
            ->assertSee('Mapの見方')
            ->assertSee('今やることを見る')
            ->assertSee('data-map-primary-focus', false)
            ->assertSee('data-map-is-primary="1"', false)
            ->assertSee($task->title);
    }

    public function test_map_without_primary_action_does_not_render_fake_now_shortcut(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->assertDontSee('data-map-primary-focus', false)
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
            'title' => 'Map UXを磨く',
            'description' => '最初の一画面でNowへ到達できるようにする',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Primary Actionを見やすくする',
            'description' => 'Mapを開いた瞬間に今やることを理解する',
            'estimated_minutes' => 45,
            'remaining_minutes' => 45,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        return [$user, $task];
    }
}
