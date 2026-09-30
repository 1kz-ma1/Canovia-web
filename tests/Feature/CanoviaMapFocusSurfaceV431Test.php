<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanoviaMapFocusSurfaceV431Test extends TestCase
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

    public function test_context_surface_exposes_mobile_size_toggle_without_replacing_close_behavior(): void
    {
        [$user, $task] = $this->scenario();

        $this->actingAs($user)
            ->get(route('map.index', ['level' => 'l3']))
            ->assertOk()
            ->assertSee('data-map-context-expand', false)
            ->assertSee('data-map-context-expand-label', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('data-map-context-close', false)
            ->assertSee('role="region"', false)
            ->assertSee('aria-labelledby="canovia-map-detail-document-title"', false)
            ->assertSee('id="canovia-map-detail-document-title"', false)
            ->assertSee('data-map-surface-template="task:'.$task->id.'"', false);
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
            'title' => 'Focus UXを磨く',
            'description' => 'Mapを見失わずにContextを操作する',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Bottom Sheetを整える',
            'description' => '選択Nodeと操作面を同時に見せる',
            'estimated_minutes' => 45,
            'remaining_minutes' => 45,
            'progress_percent' => 35,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        return [$user, $task];
    }
}
