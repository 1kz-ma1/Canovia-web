<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\ExecutionModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SurfaceRefinementPhase1V516Test extends TestCase
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

    public function test_constellation_overview_compacts_small_plan_sets_and_selection_uses_two_palettes(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $first = $this->plan($user, 'AP合格', '資格学習');
        $second = $this->plan($user, 'Canovia成功', '個人開発');

        $this->tasks($first, 6, 'AP');
        $this->tasks($second, 5, 'Canovia');

        $overview = $this->actingAs($user)->get(route('roadmap.index'));

        $overview
            ->assertOk()
            ->assertSee('data-constellation-overview', false)
            ->assertSee('data-constellation-density="compact"', false)
            ->assertSee('data-plan-count="2"', false)
            ->assertDontSee('data-constellation-selected-workspace', false);

        $selected = $this->actingAs($user)->get(route('roadmap.index', [
            'plan_id' => $first->id,
        ]));

        $selected
            ->assertOk()
            ->assertSee('data-constellation-selected-workspace', false)
            ->assertSee('data-constellation-focus-palette', false)
            ->assertSee('data-constellation-star-panel', false)
            ->assertSee('data-constellation-star-panel-body', false)
            ->assertSee('data-constellation-detail-palette', false)
            ->assertSee('data-constellation-star-selected="1"', false)
            ->assertSee('次: AP Next Action')
            ->assertDontSee('data-constellation-inspector', false)
            ->assertDontSee('data-constellation-star-dialog', false);
    }

    public function test_execution_rail_prefers_other_plans_in_same_mode_without_specialized_timer_button(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $first = $this->plan($user, '応用情報', '資格学習');
        $second = $this->plan($user, 'AWS資格', '資格学習');
        $third = $this->plan($user, 'Canovia', '個人開発');

        $this->task($first, '科目A横断演習');
        $this->task($second, '模擬試験');
        $this->task($third, 'UI実装');

        $response = $this->actingAs($user)->get(route('navigation.index', [
            'mode' => ExecutionModeService::STUDY,
        ]));

        $response
            ->assertOk()
            ->assertSee('data-execution-recommendation-rail', false)
            ->assertSee('data-execution-recommendation-track', false)
            ->assertSee('data-execution-alternative-plan-id', false)
            ->assertDontSee('data-execution-primary-timer', false)
            ->assertDontSee('data-candidate-toggle', false)
            ->assertDontSee('Timerで始める');

        $recommendation = $response->viewData('recommendation');
        $recommendations = $response->viewData('recommendations');

        $this->assertNotNull($recommendation);
        $this->assertGreaterThanOrEqual(2, $recommendations->count());
        $this->assertTrue(
            $recommendations
                ->skip(1)
                ->contains(fn ($candidate) => (int) $candidate->plan->id !== (int) $recommendation->plan->id),
        );
        $this->assertTrue(
            $recommendations
                ->every(fn ($candidate) => in_array(
                    (int) $candidate->plan->id,
                    [$first->id, $second->id],
                    true,
                )),
        );
    }

    public function test_generic_execution_keeps_timer_as_its_primary_execution_pattern(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '部屋を整理する', '生活');
        $this->task($plan, '机を片付ける');

        $response = $this->actingAs($user)->get(route('navigation.index'));

        $response
            ->assertOk()
            ->assertSee('data-execution-primary-timer', false);

        $action = $response->viewData('recommendationAction');

        $this->assertSame('timer', $action['action_id']);
        $this->assertTrue($action['supports_timer']);
    }

    public function test_phase1_client_contract_uses_inline_star_panel_and_always_visible_execution_rail(): void
    {
        $constellationJs = file_get_contents(resource_path('js/constellation-roadmap.mjs'));
        $constellationCss = file_get_contents(resource_path('css/constellation-roadmap.css'));
        $executionCss = file_get_contents(resource_path('css/execution-workspace.css'));
        $appJs = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('mountSelectedStarPanel', $constellationJs);
        $this->assertStringContainsString('data-constellation-star-panel-body', $constellationJs);
        $this->assertStringNotContainsString('data-constellation-star-dialog-body', $constellationJs);
        $this->assertStringContainsString('.canovia-constellation-selected-workspace', $constellationCss);
        $this->assertStringContainsString('.canovia-constellation-focus-palette', $constellationCss);
        $this->assertStringContainsString('.canovia-constellation-detail-palette', $constellationCss);
        $this->assertStringContainsString('.execution-recommendation-rail', $executionCss);
        $this->assertStringContainsString('data-candidate-always-open', $appJs);
    }

    private function plan(User $user, string $title, string $category): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }

    private function task(Plan $plan, string $title, int $sortOrder = 1): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title.' description',
            'next_action_note' => str_starts_with($title, 'AP') ? 'AP Next Action' : $title.' Next Action',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => $sortOrder,
        ]);
    }

    private function tasks(Plan $plan, int $count, string $prefix): void
    {
        $previous = null;

        for ($index = 1; $index <= $count; $index++) {
            $task = $this->task($plan, $prefix.' Task '.$index, $index);
            if ($previous) {
                $task->update(['depends_on_task_id' => $previous->id]);
            }
            $previous = $task;
        }
    }
}
