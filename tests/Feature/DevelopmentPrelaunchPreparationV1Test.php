<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DevelopmentPrelaunchPreparationV1Test extends TestCase
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

    public function test_prototype_without_launch_cue_has_only_optional_entry(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'アプリの試作');

        $this->actingAs($user)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-development-prelaunch-entry', false)
            ->assertDontSee('data-development-prelaunch-preparation', false)
            ->assertSee('data-development-home-next-action', false);

        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_unfinished_public_release_task_shows_one_uncertain_preparation(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '個人開発');
        $this->task($plan, '初回公開の準備');

        $response = $this->actingAs($user)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id]));

        $response
            ->assertOk()
            ->assertSee('data-development-prelaunch-preparation', false)
            ->assertSee('data-development-prelaunch-status="unknown"', false)
            ->assertSee('公開先とデータの保存・復旧方法を確認する')
            ->assertSee('準備不足の断定やリリース許可ではありません')
            ->assertSee('既存の7 Quality Gatesとは別')
            ->assertSee('data-development-prelaunch-plan-task', false)
            ->assertSee('data-development-home-next-action', false);

        $this->assertSame(
            1,
            substr_count($response->getContent(), 'data-development-prelaunch-preparation'),
        );
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('plan_artifacts', 0);
    }

    public function test_user_can_open_preparation_voluntarily_without_github(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '静的Webページ');

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'work',
                'release_preflight' => '1',
            ]))
            ->assertOk()
            ->assertSee('data-development-prelaunch-preparation', false)
            ->assertSee('data-development-prelaunch-status="unknown"', false)
            ->assertSee('DBを使わない場合は復元テストを必須にしません');

        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('plan_artifacts', 0);
    }

    public function test_prompt_is_work_surface_only_and_does_not_leak_to_other_plan(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, '公開予定のアプリ');
        $private = $this->plan($other, '本番公開するサービス');

        $this->actingAs($owner)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'repository',
                'release_preflight' => 1,
            ]))
            ->assertOk()
            ->assertDontSee('data-development-prelaunch-preparation', false)
            ->assertDontSee('data-development-prelaunch-entry', false);

        $this->actingAs($owner)
            ->get(route('workspace.development.index', [
                'plan_id' => $private->id,
                'release_preflight' => 1,
            ]))
            ->assertNotFound();

        $this->actingAs($other)
            ->get(route('workspace.development.index', ['plan_id' => $private->id]))
            ->assertOk()
            ->assertSee('data-development-prelaunch-preparation', false);
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

    private function task(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);
    }
}
