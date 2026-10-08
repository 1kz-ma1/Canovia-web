<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningImmersionShellV5888Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_workspace_home_keeps_regular_navigation_and_no_immersive_header(): void
    {
        $this->actingAs(User::factory()->create(['first_run_completed_at' => now()]))
            ->get(route('workspace.study.top'))
            ->assertOk()
            ->assertSee('data-learning-immersion="false"', false)
            ->assertSee('data-navigation-shell="workspace"', false)
            ->assertSee('data-canovia-nav-key="mobile-workspace-study"', false)
            ->assertDontSee('data-learning-immersion-header', false);
    }

    public function test_learning_answer_and_exam_routes_are_defined_as_immersive_only(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString("request()->routeIs('plans.tasks.learning.show')", $layout);
        $this->assertStringContainsString("request()->routeIs('plans.tasks.learning.exam.show')", $layout);
        $this->assertStringContainsString('data-learning-immersion-header', $layout);
        $this->assertStringContainsString('data-learning-immersion-exit', $layout);
        $this->assertStringContainsString('data-learning-immersion-home', $layout);
        $this->assertStringContainsString('@if (! $learningImmersion)', $layout);
        $this->assertStringContainsString('@if ($learningImmersion)', $layout);
    }
}
