<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DevelopmentOperationalEvidenceV2Test extends TestCase
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

    public function test_only_launch_related_work_shows_the_ranked_evidence_without_provider_access(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '開発プロジェクト');
        Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '初回公開の準備',
            'description' => '公開を安全に進める',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        $page = $this->actingAs($user)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-development-operational-primary', false)
            ->assertSee('data-development-operational-evidence', false)
            ->assertSee('data-development-operational-check="backup"', false)
            ->assertSee('data-development-operational-check="restore"', false)
            ->assertSee('data-development-operational-status="unknown"', false)
            ->assertSee('本番サーバー・DB・復元実績の独立検証ではありません')
            ->assertSee('データがない場合、DB復元テストは不要です');

        $html = $page->getContent();
        $this->assertSame(6, substr_count($html, 'data-development-operational-check='));
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('plan_artifacts', 0);
    }

    public function test_unrelated_prototype_stays_unobtrusive_but_opt_in_works(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '習作');

        $this->actingAs($user)->get(route('workspace.development.index', [
            'plan_id' => $plan->id,
        ]))->assertOk()
          ->assertSee('data-development-prelaunch-entry', false)
          ->assertDontSee('data-development-operational-evidence', false);

        $this->actingAs($user)->get(route('workspace.development.index', [
            'plan_id' => $plan->id, 'release_preflight' => '1',
        ]))->assertOk()
          ->assertSee('data-development-operational-evidence', false)
          ->assertSee('data-development-operational-check="ci"', false);

        $this->actingAs($user)->get(route('workspace.development.index', [
            'plan_id' => $plan->id, 'surface' => 'repository', 'release_preflight' => '1',
        ]))->assertOk()
          ->assertDontSee('data-development-operational-evidence', false);
    }

    public function test_other_users_private_plan_is_not_accessible(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $viewer = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, '初回公開プロジェクト');

        $this->actingAs($viewer)->get(route('workspace.development.index', [
            'plan_id' => $plan->id, 'release_preflight' => '1',
        ]))->assertNotFound();
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
            'priority' => 2,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addWeeks(3),
            'is_public' => false,
        ]);
    }
}
