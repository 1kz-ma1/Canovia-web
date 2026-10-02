<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\ConstellationProjectionService;
use App\Services\RoadmapService;
use App\Services\RoadmapSpatialProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConstellationShapeGrammarV517Test extends TestCase
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

    public function test_chain_plans_receive_stable_but_visually_distinct_shape_keys(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $first = $this->plan($user, 'First Chain');
        $second = $this->plan($user, 'Second Chain');

        $this->tasks($first, 8);
        $this->tasks($second, 8);

        $project = fn (Plan $plan) => $this->project($plan);

        $firstProjection = $project($first);
        $secondProjection = $project($second);
        $firstAgain = $project($first);

        $this->assertSame(2, $firstProjection['schema_version']);
        $this->assertSame('chain', $firstProjection['pattern']);
        $this->assertSame('chain', $secondProjection['pattern']);

        $this->assertContains($firstProjection['shape_key'], [
            'arc',
            'ladder',
            'orbit',
            'zigzag',
        ]);
        $this->assertNotSame(
            $firstProjection['shape_key'],
            $secondProjection['shape_key'],
        );

        $this->assertSame(
            $firstProjection['shape_key'],
            $firstAgain['shape_key'],
        );
        $this->assertSame(
            collect($firstProjection['stars'])->map(fn ($star) => [$star['x'], $star['y']])->all(),
            collect($firstAgain['stars'])->map(fn ($star) => [$star['x'], $star['y']])->all(),
        );
        $this->assertNotSame(
            collect($firstProjection['stars'])->map(fn ($star) => [$star['x'], $star['y']])->all(),
            collect($secondProjection['stars'])->map(fn ($star) => [$star['x'], $star['y']])->all(),
        );
    }

    public function test_selected_constellation_exposes_shape_task_group_rail_and_previous_next_navigation(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $first = $this->plan($user, 'Alpha');
        $second = $this->plan($user, 'Beta');
        $third = $this->plan($user, 'Gamma');

        $this->tasks($first, 5);
        $this->tasks($second, 6);
        $this->tasks($third, 7);

        $response = $this->actingAs($user)->get(route('roadmap.index', [
            'plan_id' => $second->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('data-constellation-selected-workspace', false)
            ->assertSee('data-constellation-shape="', false)
            ->assertSee('data-constellation-star-rail', false)
            ->assertSee('data-constellation-star-jump=', false)
            ->assertSee('data-constellation-plan-switcher', false)
            ->assertSee('data-constellation-previous-plan', false)
            ->assertSee('data-constellation-next-plan', false)
            ->assertSee('前のPlan')
            ->assertSee('次のPlan');

        $this->assertSame($first->id, $response->viewData('previousPlan')->id);
        $this->assertSame($third->id, $response->viewData('nextPlan')->id);
    }

    public function test_overview_uses_same_shape_contract_as_selected_workspace(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'Signature');
        $this->tasks($plan, 8);

        $overview = $this->actingAs($user)->get(route('roadmap.index'));
        $projection = $overview->viewData('constellations')->firstWhere('plan_id', $plan->id);

        $overview
            ->assertOk()
            ->assertSee('data-constellation-overview', false)
            ->assertSee('data-constellation-shape="'.$projection['shape_key'].'"', false);

        $selected = $this->actingAs($user)->get(route('roadmap.index', [
            'plan_id' => $plan->id,
        ]));

        $selected
            ->assertOk()
            ->assertSee('data-constellation-shape="'.$projection['shape_key'].'"', false);
    }

    public function test_client_contract_supports_star_rail_and_guarded_horizontal_plan_swipe(): void
    {
        $module = file_get_contents(resource_path('js/constellation-roadmap.mjs'));
        $css = file_get_contents(resource_path('css/constellation-roadmap.css'));

        $this->assertStringContainsString('data-constellation-star-jump', $module);
        $this->assertStringContainsString('mountPlanSwipe', $module);
        $this->assertStringContainsString("closest('a,button,input,select,textarea,label')", $module);
        $this->assertStringContainsString('Math.abs(deltaX) < 72', $module);
        $this->assertStringContainsString('Math.abs(deltaY) > 48', $module);
        $this->assertStringContainsString('data-constellation-next-plan', $module);
        $this->assertStringContainsString('data-constellation-previous-plan', $module);

        $this->assertStringContainsString('.canovia-constellation-star-rail', $css);
        $this->assertStringContainsString('.canovia-constellation-plan-switcher', $css);
        $this->assertStringContainsString('[data-constellation-swipe-zone]', $css);
        $this->assertStringContainsString('data-constellation-shape="orbit"', $css);
    }

    /**
     * @return array<string,mixed>
     */
    private function project(Plan $plan): array
    {
        $plan = $plan->fresh(['tasks.prerequisite', 'tasks.prerequisites']);
        $roadmap = app(RoadmapService::class)->build($plan);
        $spatial = app(RoadmapSpatialProjectionService::class)->build($roadmap);

        return app(ConstellationProjectionService::class)->project(
            $plan,
            $roadmap,
            $spatial,
        );
    }

    private function plan(User $user, string $title): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }

    private function tasks(Plan $plan, int $count): void
    {
        $previous = null;

        for ($index = 1; $index <= $count; $index++) {
            $previous = Task::query()->create([
                'plan_id' => $plan->id,
                'depends_on_task_id' => $previous?->id,
                'title' => $plan->title.' Task '.$index,
                'description' => 'Shape Task '.$index,
                'estimated_minutes' => 60,
                'remaining_minutes' => 60,
                'progress_percent' => $index === 1 ? 20 : 0,
                'status' => $index === 1 ? 'doing' : 'todo',
                'priority' => 1,
                'activation_cost' => 2,
                'sort_order' => $index,
            ]);
        }
    }
}
