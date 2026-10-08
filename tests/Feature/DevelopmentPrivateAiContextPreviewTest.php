<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DevelopmentPrivateAiContextPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['native_ai.driver' => 'disabled']);
    }

    public function test_owner_can_preview_minimal_plan_without_loading_or_exporting_tasks_by_default(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        $this->task($plan, 'Active private Task', 'doing');
        Http::fake();

        $this->actingAs($user)
            ->getJson(route('workspace.development.private_context.preview', ['plan' => $plan->id]))
            ->assertOk()
            ->assertJsonPath('schema', 'canovia.development_private_context_preview.v1')
            ->assertJsonPath('delivery', 'owner_preview_only')
            ->assertJsonPath('scope', 'overview')
            ->assertJsonPath('plan.title', 'My development')
            ->assertJsonPath('completion', 'unverified')
            ->assertJsonCount(0, 'tasks')
            ->assertJsonPath('truncated', false)
            ->assertDontSee('Active private Task')
            ->assertDontSee('owner_token')
            ->assertDontSee('description')
            ->assertHeader('Cache-Control', 'no-store, private');

        Http::assertNothingSent();
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_task_scope_is_whitelisted_ordered_bounded_and_never_changes_progress(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        $this->task($plan, 'Waiting Task', 'todo', 10);
        $this->task($plan, 'Now Task', 'doing', 35);
        $this->task($plan, 'Hold Task', 'paused', 5);
        $this->task($plan, 'Done Task', 'done', 100);
        $this->task($plan, 'Second waiting', 'todo', 0);

        $response = $this->actingAs($user)
            ->getJson(route('workspace.development.private_context.preview', [
                'plan' => $plan->id,
                'scope' => 'tasks',
                'limit' => 2,
            ]))
            ->assertOk()
            ->assertJsonPath('scope', 'tasks')
            ->assertJsonPath('tasks.0.title', 'Now Task')
            ->assertJsonPath('tasks.0.status', 'doing')
            ->assertJsonPath('tasks.0.recorded_progress_percent', 35)
            ->assertJsonPath('tasks.1.title', 'Waiting Task')
            ->assertJsonPath('truncated', true)
            ->assertJsonCount(2, 'tasks')
            ->assertDontSee('Done Task')
            ->assertDontSee('MY_SECRET_IN_DESCRIPTION')
            ->assertDontSee('MY_SECRET_IN_NEXT_NOTE')
            ->assertDontSee('user_id')
            ->assertDontSee('plan_id')
            ->assertDontSee('task_id')
            ->assertDontSee('owner_token')
            ->assertDontSee('evidence')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertDatabaseCount('tasks', 5);
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseHas('tasks', [
            'plan_id' => $plan->id,
            'title' => 'Now Task',
            'progress_percent' => 35,
        ]);
        $this->assertSame('owner_preview_only', $response->json('delivery'));
    }

    public function test_guests_outsiders_team_plans_and_other_domains_fail_closed(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $privatePlan = $this->plan($owner);

        $this->get(route('workspace.development.private_context.preview', ['plan' => $privatePlan->id]))
            ->assertRedirect();

        $this->actingAs($outsider)
            ->getJson(route('workspace.development.private_context.preview', ['plan' => $privatePlan->id, 'scope' => 'tasks']))
            ->assertNotFound();

        $team = $this->plan($owner, '個人開発', true);
        $this->actingAs($owner)
            ->getJson(route('workspace.development.private_context.preview', ['plan' => $team->id]))
            ->assertNotFound();

        $creative = $this->plan($owner, '制作活動');
        $this->actingAs($owner)
            ->getJson(route('workspace.development.private_context.preview', ['plan' => $creative->id]))
            ->assertNotFound();

        $study = $this->plan($owner, '資格学習');
        $this->actingAs($owner)
            ->getJson(route('workspace.development.private_context.preview', ['plan' => $study->id]))
            ->assertNotFound();
    }

    public function test_invalid_scopes_and_limits_are_rejected_before_projection(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);

        $this->actingAs($user)
            ->getJson(route('workspace.development.private_context.preview', [
                'plan' => $plan->id,
                'scope' => 'all',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scope');

        $this->actingAs($user)
            ->getJson(route('workspace.development.private_context.preview', [
                'plan' => $plan->id,
                'scope' => 'tasks',
                'limit' => 99,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');

        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_preview_ui_is_owner_only_and_contains_no_task_data_until_click(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);
        $this->task($plan, 'SHOULD_NOT_BE_IN_HTML', 'doing');

        $this->actingAs($owner)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id, 'surface' => 'work']))
            ->assertOk()
            ->assertSee('data-development-private-context-preview', false)
            ->assertSee('AI共有内容を確認')
            ->assertSee('表示内容をコピー')
            ->assertSee(route('workspace.development.private_context.preview', ['plan' => $plan->id]), false)
            ->assertSee('data-private-context-value', false);

        $team = $this->plan($owner, '個人開発', true);
        $this->actingAs($owner)
            ->get(route('workspace.development.index', ['plan_id' => $team->id, 'surface' => 'work']))
            ->assertOk()
            ->assertDontSee('data-development-private-context-preview', false);
    }

    private function plan(User $user, string $category = '個人開発', bool $collaborative = false): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'My development',
            'description' => 'CONFIDENTIAL_PLAN_DESCRIPTION',
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(20),
            'is_public' => false,
            'is_collaborative' => $collaborative,
        ]);
    }

    private function task(Plan $plan, string $title, string $status, int $progress = 0): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => 'MY_SECRET_IN_DESCRIPTION',
            'next_action_note' => 'MY_SECRET_IN_NEXT_NOTE',
            'status' => $status,
            'priority' => 1,
            'progress_percent' => $progress,
            'estimated_minutes' => 30,
            'remaining_minutes' => 20,
        ]);
    }
}
