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

class ConstellationRoadmapV511Test extends TestCase
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

    public function test_large_plan_is_compressed_into_task_group_stars_instead_of_one_star_per_task(): void
    {
        [$user, $plan] = $this->scenario('Compressed Plan', 8, 60);

        $roadmap = app(RoadmapService::class)->build($plan->fresh('tasks'));
        $spatial = app(RoadmapSpatialProjectionService::class)->build($roadmap);
        $constellation = app(ConstellationProjectionService::class)->project(
            $plan,
            $roadmap,
            $spatial,
        );

        $this->assertSame(8, $constellation['task_count']);
        $this->assertCount(4, $constellation['stars']);
        $this->assertSame(
            8,
            collect($constellation['stars'])->sum('task_count'),
        );
        $this->assertLessThan(
            $constellation['task_count'],
            count($constellation['stars']),
        );
        $this->assertSame('chain', $constellation['pattern']);
        $this->assertNotEmpty($constellation['edges']);
        $this->assertNotNull($constellation['current_star_id']);
    }

    public function test_estimated_cost_can_make_same_task_count_constellation_richer_without_exposing_a_score(): void
    {
        [, $small] = $this->scenario('Small Cost', 8, 60);
        [, $large] = $this->scenario('Large Cost', 8, 600);

        $project = function (Plan $plan): array {
            $roadmap = app(RoadmapService::class)->build($plan->fresh('tasks'));
            $spatial = app(RoadmapSpatialProjectionService::class)->build($roadmap);

            return app(ConstellationProjectionService::class)->project(
                $plan,
                $roadmap,
                $spatial,
            );
        };

        $smallProjection = $project($small);
        $largeProjection = $project($large);

        $this->assertGreaterThan(
            count($smallProjection['stars']),
            count($largeProjection['stars']),
        );
        $this->assertGreaterThan(
            $smallProjection['richness_tier'],
            $largeProjection['richness_tier'],
        );
        $this->assertArrayNotHasKey('score', $largeProjection);
        $this->assertArrayNotHasKey('richness_score', $largeProjection);
    }

    public function test_roadmap_opens_as_universe_and_only_selected_plan_exposes_task_group_counts(): void
    {
        [$user, $first] = $this->scenario('First Constellation', 8, 60);
        [, $second] = $this->scenario('Second Constellation', 6, 90, $user);

        $overview = $this->actingAs($user)->get(route('roadmap.index'));

        $overview
            ->assertOk()
            ->assertSee('data-constellation-universe', false)
            ->assertSee('SPACE STATION')
            ->assertSee('First Constellation')
            ->assertSee('Second Constellation')
            ->assertDontSee('is-selected', false)
            ->assertDontSee('data-constellation-star-open', false);

        $this->assertNull($overview->viewData('plan'));
        $this->assertCount(2, $overview->viewData('constellations'));

        $selected = $this->actingAs($user)->get(route('roadmap.index', [
            'plan_id' => $first->id,
        ]));

        $selected
            ->assertOk()
            ->assertSee('is-selected', false)
            ->assertSee('data-constellation-star-open', false)
            ->assertSee('data-constellation-star-template', false)
            ->assertSee('data-constellation-inspector', false)
            ->assertSee('SELECTED CONSTELLATION')
            ->assertSee('このPlanを実行')
            ->assertSee('Plan詳細');

        $this->assertSame($first->id, $selected->viewData('plan')->id);
        $this->assertNotNull($selected->viewData('roadmap'));
        $this->assertNotNull($selected->viewData('roadmapSpatial'));
        $this->assertSame(
            [$first->id, $second->id],
            $overview->viewData('constellations')->pluck('plan_id')->all(),
        );
    }

    public function test_new_plan_appends_to_stable_constellation_orbit_without_moving_existing_plans(): void
    {
        [$user, $first] = $this->scenario('Stable First', 5, 60);

        $before = $this->actingAs($user)->get(route('roadmap.index'));
        $firstBefore = $before->viewData('constellations')
            ->firstWhere('plan_id', $first->id);

        [, $second] = $this->scenario('Stable Second', 5, 60, $user);

        $after = $this->actingAs($user)->get(route('roadmap.index'));
        $firstAfter = $after->viewData('constellations')
            ->firstWhere('plan_id', $first->id);
        $secondAfter = $after->viewData('constellations')
            ->firstWhere('plan_id', $second->id);

        $this->assertSame($firstBefore['orbit'], $firstAfter['orbit']);
        $this->assertNotSame($firstAfter['orbit'], $secondAfter['orbit']);
    }

    public function test_constellation_runtime_opens_star_task_list_and_mounts_after_full_and_instant_navigation(): void
    {
        $module = file_get_contents(resource_path('js/constellation-roadmap.mjs'));
        $app = file_get_contents(resource_path('js/app.js'));
        $css = file_get_contents(resource_path('css/constellation-roadmap.css'));

        $this->assertStringContainsString('export function mountConstellationRoadmap', $module);
        $this->assertStringContainsString('template.content.cloneNode(true)', $module);
        $this->assertStringContainsString('data-constellation-station-open', $module);
        $this->assertStringContainsString('centerUniverse(page)', $module);

        $this->assertStringContainsString(
            "import { mountConstellationRoadmap } from './constellation-roadmap.mjs';",
            $app,
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($app, 'mountConstellationRoadmap();'),
        );

        $this->assertStringContainsString('.canovia-constellation-universe', $css);
        $this->assertStringContainsString('.canovia-space-station', $css);
        $this->assertStringContainsString('.canovia-main-star', $css);
        $this->assertStringContainsString('touch-action: pan-x pan-y', $css);
    }

    /**
     * @return array{0:User,1:Plan}
     */
    private function scenario(
        string $title,
        int $taskCount,
        int $estimatedMinutes,
        ?User $user = null,
    ): array {
        $user ??= User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
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
        ]);

        $previous = null;
        for ($index = 1; $index <= $taskCount; $index++) {
            $status = $index <= 2
                ? 'done'
                : ($index === 3 ? 'doing' : 'todo');
            $progress = $status === 'done'
                ? 100
                : ($status === 'doing' ? 35 : 0);

            $previous = Task::query()->create([
                'plan_id' => $plan->id,
                'depends_on_task_id' => $previous?->id,
                'title' => $title.' Task '.$index,
                'description' => 'Constellation Task '.$index,
                'estimated_minutes' => $estimatedMinutes,
                'remaining_minutes' => $status === 'done' ? 0 : $estimatedMinutes,
                'progress_percent' => $progress,
                'status' => $status,
                'priority' => 1,
                'activation_cost' => 2,
                'sort_order' => $index,
            ]);
        }

        return [$user, $plan];
    }
}
