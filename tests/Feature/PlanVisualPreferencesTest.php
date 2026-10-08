<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanVisualPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_plan_with_visual_identity(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('plans.store'), [
            'title' => 'Visual Plan',
            'description' => 'Roadmap visual identity test',
            'category' => '個人開発',
            'visual_icon' => '🚀',
            'accent_key' => 'violet',
            'roadmap_world' => 'space',
            'start_date' => today()->toDateString(),
            'deadline' => today()->addMonth()->toDateString(),
        ]);

        $plan = Plan::where('title', 'Visual Plan')->firstOrFail();
        $response->assertRedirect(route('workspace.development.index', ['plan_id' => $plan->id]));
        $this->assertSame('🚀', $plan->visual_icon);
        $this->assertSame('violet', $plan->accentKey());
        $this->assertSame('space', $plan->roadmapWorld());
    }

    public function test_invalid_visual_theme_values_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('plans.store'), [
            'title' => 'Invalid Visual Plan',
            'accent_key' => 'not-a-color',
            'roadmap_world' => 'unknown-world',
            'start_date' => today()->toDateString(),
            'deadline' => today()->addMonth()->toDateString(),
        ])->assertSessionHasErrors(['accent_key', 'roadmap_world']);
    }

    public function test_existing_plan_defaults_have_safe_visual_fallbacks(): void
    {
        $plan = new Plan(['category' => '資格学習']);

        $this->assertSame('📘', $plan->displayIcon());
        $this->assertSame('sky', $plan->accentKey());
        $this->assertSame('default', $plan->roadmapWorld());
    }
}
