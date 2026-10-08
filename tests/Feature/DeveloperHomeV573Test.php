<?php

namespace Tests\Feature;

use App\Models\DevelopmentActivityObservation;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeveloperHomeV573Test extends TestCase
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

    public function test_developer_home_renders_one_information_stream_at_a_time(): void
    {
        [$user, $plan, $doing, $todo, $root] = $this->scenario();

        $this->observation($plan, $root, [
            'kind' => 'pull_request',
            'provider_number' => 260,
            'title' => $doing->title,
            'state' => 'open',
            'ref' => 'feature/task-'.$doing->id.'-developer-home',
            'sha' => str_repeat('a', 40),
            'url' => 'https://github.com/1kz-ma1/Canovia-web/pull/260',
        ]);

        $work = $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('data-development-surface="work"', false)
            ->assertSee('data-development-surface-panel="work"', false)
            ->assertSee('NEXT ACTION')
            ->assertSee('ACTIVE DEVELOPMENT')
            ->assertDontSee('data-development-home-recent-activity', false)
            ->assertDontSee('data-development-home-readiness', false);

        $html = $work->getContent();
        $doingPosition = strpos($html, $doing->title);
        $todoPosition = strpos($html, $todo->title);

        $this->assertNotFalse($doingPosition);
        $this->assertNotFalse($todoPosition);
        $this->assertLessThan($todoPosition, $doingPosition);

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'repository',
            ]))
            ->assertOk()
            ->assertSee('data-development-surface="repository"', false)
            ->assertSee('data-development-surface-panel="repository"', false)
            ->assertSee('data-development-home-recent-activity', false)
            ->assertSee('data-development-home-readiness', false)
            ->assertDontSee('data-development-home-next-action', false);
    }

    public function test_recent_github_reality_shows_linked_and_unresolved_activity_differently(): void
    {
        [$user, $plan, $doing, , $root] = $this->scenario();

        $linkedArtifact = PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'github',
            'artifact_type' => 'link',
            'title' => 'PR #259',
            'url' => 'https://github.com/1kz-ma1/Canovia-web/pull/259',
        ]);
        $linkedArtifact->tasks()->sync([$doing->id]);

        $this->observation($plan, $root, [
            'kind' => 'pull_request',
            'provider_number' => 259,
            'title' => 'V57.2 Developer Task Association',
            'state' => 'merged',
            'ref' => 'feature/v57-2-developer-task-association',
            'sha' => str_repeat('b', 40),
            'url' => 'https://github.com/1kz-ma1/Canovia-web/pull/259',
            'resolution_status' => 'linked',
            'resolved_artifact_id' => $linkedArtifact->id,
        ]);

        $unlinked = $this->observation($plan, $root, [
            'kind' => 'issue',
            'provider_number' => 91,
            'title' => $doing->title,
            'state' => 'open',
            'url' => 'https://github.com/1kz-ma1/Canovia-web/issues/91',
        ]);

        $response = $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'repository',
            ]));

        $response
            ->assertOk()
            ->assertSee('Task連携済み')
            ->assertSee('Task: '.$doing->title)
            ->assertSee('要整理')
            ->assertSee('関連付ける')
            ->assertSee('今回は無視')
            ->assertSee('V57.2 Developer Task Association');

        $unlinked->refresh();

        $this->assertSame('suggested', $unlinked->resolution_status);
        $this->assertSame((int) $doing->id, (int) $unlinked->suggested_task_id);
        $this->assertDatabaseCount('plan_artifact_task', 1);
    }

    public function test_plan_without_github_is_not_blocked_by_setup_onboarding(): void
    {
        [$user, $plan, , , $root] = $this->scenario();
        $root->delete();

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertDontSee(
                'data-workspace-mode-onboarding="development"',
                false,
            )
            ->assertSee('data-development-home-next-action', false)
            ->assertSee('data-development-local-first-action', false)
            ->assertSee('data-development-local-task-action', false)
            ->assertSee(route('plans.tasks.execution_orchestration.show', [$plan, $plan->tasks()->where('status', 'doing')->firstOrFail()]), false)
            ->assertSee('data-development-surface-select', false)
            ->assertSee('value="repository"', false)
            ->assertSee('リポジトリ')
            ->assertDontSee('data-development-home-readiness', false);

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'repository',
            ]))
            ->assertOk()
            ->assertSee('data-development-home-readiness', false);
    }

    public function test_empty_development_plan_can_start_without_a_github_repository(): void
    {
        [$user, $plan, $doing, $todo, $root] = $this->scenario();
        $doing->delete();
        $todo->delete();
        $root->delete();

        $this->actingAs($user)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-development-local-first-action', false)
            ->assertSee('data-development-local-create-action', false)
            ->assertSee('初期タスクを作る')
            ->assertSee(route('plans.ai_task_assistant.show', [
                'plan' => $plan,
                'return_to_workspace' => 1,
            ]), false)
            ->assertDontSee('data-development-local-task-action', false);
    }

    public function test_connected_development_plan_keeps_release_intelligence_first(): void
    {
        [$user, $plan] = $this->scenario();

        $this->actingAs($user)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-development-home-next-action', false)
            ->assertDontSee('data-development-local-first-action', false)
            ->assertDontSee('data-development-local-task-action', false);
    }

    public function test_completed_tasks_without_github_lead_to_a_review_instead_of_restarting_onboarding(): void
    {
        [$user, $plan, $doing, $todo, $root] = $this->scenario();
        $root->delete();

        foreach ([$doing, $todo] as $task) {
            $task->update([
                'status' => 'done',
                'progress_percent' => 100,
                'remaining_minutes' => 0,
            ]);
        }

        $url = route('workspace.development.index', ['plan_id' => $plan->id]);
        $this->actingAs($user)
            ->get($url)
            ->assertOk()
            ->assertSee('data-development-local-first-action', false)
            ->assertSee('data-development-completion-followup', false)
            ->assertSee('data-development-completion-context', false)
            ->assertSee('完了Task 2件')
            ->assertSee('Taskステータスのみ')
            ->assertSee(route('plans.review_assistant.show', $plan), false)
            ->assertDontSee('data-development-local-create-action', false)
            ->assertDontSee('data-development-local-task-action', false);

        WorkLog::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $todo->id,
            'task_title_snapshot' => $todo->title,
            'worked_on' => today(),
            'actual_minutes' => 50,
            'progress_delta_percent' => 100,
            'progress_before_percent' => 0,
            'progress_after_percent' => 100,
            'remaining_minutes_before' => 50,
            'remaining_minutes_after' => 0,
            'memo' => '画面の動作を確認した',
        ]);

        $this->actingAs($user)
            ->get($url)
            ->assertOk()
            ->assertSee($todo->title)
            ->assertSee('作業ログあり')
            ->assertSee('data-development-completion-followup', false)
            ->assertSee('data-development-plan-next-task', false);

        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('intelligence_action_projections', 0);
    }

    public function test_completed_task_is_context_while_next_open_task_remains_the_primary_action(): void
    {
        [$user, $plan, $doing, $todo, $root] = $this->scenario();
        $root->delete();
        $doing->update([
            'status' => 'done',
            'progress_percent' => 100,
            'remaining_minutes' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-development-local-task-action', false)
            ->assertSee($todo->title)
            ->assertSee('data-development-completion-context', false)
            ->assertSee($doing->title)
            ->assertDontSee('data-development-completion-followup', false)
            ->assertSee(
                route('plans.tasks.execution_orchestration.show', [$plan, $todo]),
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
            'description' => 'Developer Home V1',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $doing = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'V57.3 Developer Homeを実装',
            'description' => null,
            'estimated_minutes' => 120,
            'remaining_minutes' => 70,
            'progress_percent' => 42,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 2,
        ]);

        $todo = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'V57.4 開発支援を深める',
            'description' => null,
            'estimated_minutes' => 90,
            'remaining_minutes' => 90,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        $root = PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'github',
            'artifact_type' => 'repository',
            'title' => 'Canovia-web',
            'url' => 'https://github.com/1kz-ma1/Canovia-web',
            'metadata' => [
                'github_app_connection' => [
                    'status' => 'connected',
                    'installation_id' => 777,
                ],
            ],
        ]);

        return [$user, $plan, $doing, $todo, $root];
    }

    private function observation(
        Plan $plan,
        PlanArtifact $root,
        array $overrides,
    ): DevelopmentActivityObservation {
        return DevelopmentActivityObservation::query()->create([
            'plan_id' => $plan->id,
            'repository_artifact_id' => $root->id,
            'provider' => 'github',
            'kind' => $overrides['kind'] ?? 'issue',
            'external_key' => 'github:1kz-ma1/canovia-web:'
                .($overrides['kind'] ?? 'issue').':'
                .Str::lower(Str::random(12)),
            'provider_number' => $overrides['provider_number'] ?? null,
            'url' => $overrides['url'] ?? null,
            'title' => $overrides['title'] ?? null,
            'state' => $overrides['state'] ?? 'open',
            'ref' => $overrides['ref'] ?? null,
            'sha' => $overrides['sha'] ?? null,
            'occurred_at' => now()->subMinute(),
            'last_observed_at' => now(),
            'suggested_task_id' => $overrides['suggested_task_id'] ?? null,
            'suggestion_confidence' => $overrides['suggestion_confidence'] ?? null,
            'suggestion_basis' => $overrides['suggestion_basis'] ?? null,
            'resolution_status' => $overrides['resolution_status'] ?? 'unlinked',
            'resolved_artifact_id' => $overrides['resolved_artifact_id'] ?? null,
        ]);
    }
}
