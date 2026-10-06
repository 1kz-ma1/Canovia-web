<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeveloperNavigationIAV581Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled',
        ]);
    }

    public function test_navigation_uses_grouped_existing_surfaces_instead_of_horizontal_tab_row(): void
    {
        [$user, $plan] = $this->scenario();

        $response = $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'repository',
            ]));

        $response
            ->assertOk()
            ->assertSee('data-development-navigation-form', false)
            ->assertSee('data-development-plan-select', false)
            ->assertSee('data-development-surface-select', false)
            ->assertSee(
                'data-development-current-category="project"',
                false,
            )
            ->assertSee('<optgroup label="実行">', false)
            ->assertSee('<optgroup label="設計">', false)
            ->assertSee('<optgroup label="プロジェクト">', false)
            ->assertSee('<optgroup label="観測">', false)
            ->assertSee('<option', false)
            ->assertSee('value="repository"', false)
            ->assertSee('selected', false)
            ->assertDontSee('data-development-surface-tab=', false)
            ->assertDontSee('development-surface-tabs', false)
            ->assertDontSee('<optgroup label="自動化">', false);

        $html = $response->getContent();

        $execution = strpos($html, '<optgroup label="実行">');
        $design = strpos($html, '<optgroup label="設計">');
        $project = strpos($html, '<optgroup label="プロジェクト">');
        $observation = strpos($html, '<optgroup label="観測">');

        $this->assertNotFalse($execution);
        $this->assertNotFalse($design);
        $this->assertNotFalse($project);
        $this->assertNotFalse($observation);

        $this->assertLessThan($design, $execution);
        $this->assertLessThan($project, $design);
        $this->assertLessThan($observation, $project);
    }

    public function test_each_existing_surface_maps_to_expected_lifecycle_category(): void
    {
        [$user, $plan] = $this->scenario();

        $expected = [
            'work' => 'execution',
            'improvements' => 'design',
            'repository' => 'project',
            'team' => 'project',
            'preview' => 'observation',
        ];

        foreach ($expected as $surface => $category) {
            $this->actingAs($user)
                ->get(route('workspace.development.index', [
                    'plan_id' => $plan->id,
                    'surface' => $surface,
                ]))
                ->assertOk()
                ->assertSee(
                    'data-development-current-category="'.$category.'"',
                    false,
                )
                ->assertSee(
                    'data-development-surface="'.$surface.'"',
                    false,
                );
        }
    }

    public function test_plan_and_view_controls_share_one_compact_navigation_form(): void
    {
        [$user, $plan] = $this->scenario();

        $second = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Second Development',
            'description' => 'Another development plan',
            'category' => '個人開発',
            'priority' => 2,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $response = $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $second->id,
                'surface' => 'preview',
            ]));

        $response
            ->assertOk()
            ->assertSee('class="development-workspace-navigation"', false)
            ->assertSee(
                'action="'.route('workspace.development.index').'"',
                false,
            )
            ->assertSee('data-development-navigation-form', false)
            ->assertSee('name="plan_id"', false)
            ->assertSee('name="surface"', false)
            ->assertSee('value="'.$second->id.'"', false)
            ->assertSee('value="preview"', false)
            ->assertSee(
                'data-development-current-category="observation"',
                false,
            );
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
            'title' => 'Canovia Development',
            'description' => 'Developer Navigation IA',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        return [$user, $plan];
    }
}
