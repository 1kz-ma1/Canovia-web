<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DevelopmentGitHubRoadmapWorkspaceTest extends TestCase
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

    public function test_roadmap_surface_shows_connection_guidance_without_fetching_an_unlinked_repo(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        Http::fake();

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'roadmap',
            ]))
            ->assertOk()
            ->assertSee('data-development-roadmap', false)
            ->assertSee('GitHub接続を確認する')
            ->assertSee('READ ONLY');

        Http::assertNothingSent();
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_unrelated_user_cannot_select_someone_elses_roadmap_plan(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $plan = $this->plan($owner);
        Http::fake();

        $this->actingAs($outsider)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'roadmap',
            ]))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    private function plan(User $user): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Development',
            'description' => 'Development testing',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addWeeks(3),
            'is_public' => false,
        ]);
    }
}
