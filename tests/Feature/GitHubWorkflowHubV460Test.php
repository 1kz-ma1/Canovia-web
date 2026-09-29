<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\PlanMember;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Services\GitHubWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GitHubWorkflowHubV460Test extends TestCase
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

    public function test_github_url_parser_reads_structure_without_inventing_remote_status(): void
    {
        $service = app(GitHubWorkflowService::class);

        $pr = $service->parseUrl('https://github.com/1kz-ma1/Canovia/pull/321');
        $issue = $service->parseUrl('https://github.com/1kz-ma1/Canovia/issues/77');
        $branch = $service->parseUrl('https://github.com/1kz-ma1/Canovia/tree/feature/mobile/map');
        $actions = $service->parseUrl('https://github.com/1kz-ma1/Canovia/actions/runs/123456');
        $invalid = $service->parseUrl('https://example.com/1kz-ma1/Canovia/pull/321');

        $this->assertTrue($pr['valid']);
        $this->assertSame('1kz-ma1/Canovia', $pr['repo_full_name']);
        $this->assertSame('pull_request', $pr['kind']);
        $this->assertSame(321, $pr['number']);
        $this->assertSame('PR #321', $pr['reference']);

        $this->assertSame('issue', $issue['kind']);
        $this->assertSame('Issue #77', $issue['reference']);

        $this->assertSame('branch', $branch['kind']);
        $this->assertSame('feature/mobile/map', $branch['branch']);

        $this->assertSame('actions_run', $actions['kind']);
        $this->assertSame('Actions #123456', $actions['reference']);

        $this->assertFalse($invalid['valid']);
        $this->assertArrayNotHasKey('remote_status', $pr);
    }

    public function test_hub_shows_five_simple_lanes_and_keeps_existing_pr_unclassified_without_explicit_state(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'Canovia開発');

        $unclassified = $this->artifact(
            $plan,
            'PR without Canovia state',
            'https://github.com/1kz-ma1/Canovia/pull/120',
        );
        $review = $this->artifact(
            $plan,
            'PR for review',
            'https://github.com/1kz-ma1/Canovia/pull/121',
            ['github_workflow_state' => 'review'],
        );

        $response = $this->actingAs($user)->get(route('github_workflow.index'));

        $response
            ->assertOk()
            ->assertSee('GitHubを「次に何をするか」で管理')
            ->assertSee('data-github-workflow-lane="now"', false)
            ->assertSee('data-github-workflow-lane="review"', false)
            ->assertSee('data-github-workflow-lane="changes"', false)
            ->assertSee('data-github-workflow-lane="merge"', false)
            ->assertSee('data-github-workflow-lane="done"', false)
            ->assertSee('GitHub API未接続')
            ->assertSee('PR without Canovia state')
            ->assertSee('PR for review');

        $this->assertCount(1, $response->viewData('unclassified'));
        $this->assertSame($unclassified->id, data_get($response->viewData('unclassified'), '0.id'));

        $reviewLane = collect($response->viewData('lanes'))->firstWhere('key', 'review');
        $this->assertSame($review->id, data_get($reviewLane, 'items.0.id'));
        $this->assertNull(data_get($reviewLane, 'items.0.remote_status'));
    }

    public function test_pasting_github_url_creates_artifact_with_explicit_canovia_state_and_generated_title(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'Canovia開発');

        $this->actingAs($user)
            ->post(route('github_workflow.store'), [
                'plan_id' => $plan->id,
                'url' => 'https://github.com/1kz-ma1/Canovia/pull/456',
                'workflow_state' => 'now',
                'title' => '',
            ])
            ->assertRedirect(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertSessionHasNoErrors();

        $artifact = PlanArtifact::query()->firstOrFail();

        $this->assertSame($plan->id, $artifact->plan_id);
        $this->assertSame('github', $artifact->provider);
        $this->assertSame('link', $artifact->artifact_type);
        $this->assertSame('1kz-ma1/Canovia · PR #456', $artifact->title);
        $this->assertSame('now', $artifact->githubWorkflowState());
        $this->assertSame('今やる', $artifact->githubWorkflowStateLabel());
    }

    public function test_quick_add_rejects_non_github_url(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'Canovia開発');

        $this->actingAs($user)
            ->post(route('github_workflow.store'), [
                'plan_id' => $plan->id,
                'url' => 'https://example.com/owner/repo/pull/1',
                'workflow_state' => 'now',
            ])
            ->assertSessionHasErrors('url');

        $this->assertDatabaseCount('plan_artifacts', 0);
    }

    public function test_state_update_is_manual_preserves_other_metadata_and_does_not_claim_task_evidence(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '共同開発', collaborative: true);
        $task = $this->task($plan, 'PRを直す');
        $artifact = $this->artifact(
            $plan,
            'PR #12',
            'https://github.com/example/project/pull/12',
            [
                'collaboration_state' => 'review',
                'custom_key' => 'keep-me',
            ],
        );
        $artifact->tasks()->sync([$task->id]);

        $beforeEvidence = TaskEvidence::query()->count();

        $this->actingAs($user)
            ->patch(route('github_workflow.state.update', $artifact), [
                'workflow_state' => 'changes',
                'return_plan_id' => $plan->id,
            ])
            ->assertRedirect(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertSessionHasNoErrors();

        $artifact->refresh();

        $this->assertSame('changes', $artifact->githubWorkflowState());
        $this->assertSame('review', $artifact->collaborationState());
        $this->assertSame('keep-me', data_get($artifact->metadata, 'custom_key'));
        $this->assertSame(
            $beforeEvidence,
            TaskEvidence::query()->count(),
            'Canovia workflow lane change must not masquerade as GitHub execution evidence.',
        );
    }

    public function test_viewer_can_see_shared_github_item_but_cannot_change_workflow_state(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $viewer = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, 'Shared Dev', collaborative: true);
        $artifact = $this->artifact(
            $plan,
            'Shared PR',
            'https://github.com/example/project/pull/9',
            ['github_workflow_state' => 'review'],
        );

        PlanMember::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $viewer->id,
            'role' => PlanMember::ROLE_VIEWER,
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($viewer)->get(route('github_workflow.index'));

        $response
            ->assertOk()
            ->assertSee('Shared PR')
            ->assertSee('閲覧のみ · 状態変更はEditor以上');

        $this->actingAs($viewer)
            ->patch(route('github_workflow.state.update', $artifact), [
                'workflow_state' => 'done',
            ])
            ->assertForbidden();

        $this->assertSame('review', $artifact->fresh()->githubWorkflowState());
    }

    public function test_other_users_private_github_artifacts_never_appear_or_mutate(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, 'Private Dev');
        $artifact = $this->artifact(
            $plan,
            'Private PR',
            'https://github.com/private/project/pull/1',
            ['github_workflow_state' => 'now'],
        );

        $this->actingAs($other)
            ->get(route('github_workflow.index'))
            ->assertOk()
            ->assertDontSee('Private PR');

        $this->actingAs($other)
            ->patch(route('github_workflow.state.update', $artifact), [
                'workflow_state' => 'done',
            ])
            ->assertForbidden();

        $this->assertSame('now', $artifact->fresh()->githubWorkflowState());
    }

    public function test_normal_artifact_edit_preserves_github_state_and_clears_it_when_provider_changes(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'Canovia開発');
        $artifact = $this->artifact(
            $plan,
            'Repo',
            'https://github.com/1kz-ma1/Canovia',
            ['github_workflow_state' => 'merge'],
            artifactType: 'repository',
        );

        $this->actingAs($user)
            ->put(route('plans.artifacts.update', [$plan, $artifact]), [
                'provider' => 'github',
                'artifact_type' => 'repository',
                'title' => 'Repo updated',
                'url' => 'https://github.com/1kz-ma1/Canovia',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('merge', $artifact->fresh()->githubWorkflowState());

        $this->actingAs($user)
            ->put(route('plans.artifacts.update', [$plan, $artifact]), [
                'provider' => 'external',
                'artifact_type' => 'link',
                'title' => 'External docs',
                'url' => 'https://example.com/docs',
            ])
            ->assertSessionHasNoErrors();

        $artifact->refresh();

        $this->assertNull(data_get($artifact->metadata, 'github_workflow_state'));
        $this->assertNull($artifact->githubWorkflowState());
    }

    public function test_roadmap_and_artifact_surface_link_to_plan_filtered_github_hub(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'Canovia開発');
        $this->task($plan, 'GitHub導線を確認');

        $roadmap = $this->actingAs($user)
            ->get(route('roadmap.index', ['plan_id' => $plan->id]));

        $roadmap
            ->assertOk()
            ->assertSee(route('github_workflow.index', ['plan_id' => $plan->id]));

        $artifacts = $this->actingAs($user)
            ->get(route('plans.artifacts.index', $plan));

        $artifacts
            ->assertOk()
            ->assertSee('GitHub Workflow')
            ->assertSee(route('github_workflow.index', ['plan_id' => $plan->id]));
    }

    public function test_repository_url_becomes_an_overview_root_instead_of_a_workflow_lane(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'HINANEX');

        $this->actingAs($user)
            ->post(route('github_workflow.store'), [
                'plan_id' => $plan->id,
                'url' => 'https://github.com/1kz-ma1/HINANEX',
                'workflow_state' => 'now',
                'title' => 'ヒナネクス-リポジトリ',
            ])
            ->assertRedirect(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertSessionHasNoErrors();

        $artifact = PlanArtifact::query()->firstOrFail();

        $this->assertSame('repository', $artifact->artifact_type);
        $this->assertNull($artifact->githubWorkflowState());

        $response = $this->actingAs($user)
            ->get(route('github_workflow.index', ['plan_id' => $plan->id]));

        $response
            ->assertOk()
            ->assertSee('REPOSITORY OVERVIEW')
            ->assertSee('Repositoryを起点に、全体像を見る')
            ->assertSee('1kz-ma1/HINANEX')
            ->assertSee('Repositoryだけ登録されています')
            ->assertSee('Repositoryは「今やる」項目ではなく');

        $this->assertSame(1, data_get($response->viewData('summary'), 'repositories'));
        $this->assertSame(0, data_get($response->viewData('summary'), 'total'));
        $this->assertCount(1, $response->viewData('repository_overviews'));

        foreach ($response->viewData('lanes') as $lane) {
            $this->assertCount(0, $lane['items']);
        }
    }

    public function test_repository_overview_groups_known_objects_workflow_state_and_linked_tasks(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'HINANEX');
        $task = $this->task($plan, 'C / Validation');

        $repo = $this->artifact(
            $plan,
            'HINANEX repository',
            'https://github.com/1kz-ma1/HINANEX',
            ['github_workflow_state' => 'now'],
            artifactType: 'repository',
        );
        $pr = $this->artifact(
            $plan,
            'Validation PR',
            'https://github.com/1kz-ma1/HINANEX/pull/12',
            ['github_workflow_state' => 'review'],
        );
        $branch = $this->artifact(
            $plan,
            'Validation branch',
            'https://github.com/1kz-ma1/HINANEX/tree/feature/validation',
            ['github_workflow_state' => 'now'],
        );
        $pr->tasks()->sync([$task->id]);

        $response = $this->actingAs($user)
            ->get(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('Validation PR')
            ->assertSee('Validation branch')
            ->assertSee('C / Validation');

        $overview = data_get($response->viewData('repository_overviews'), '0');

        $this->assertSame('1kz-ma1/HINANEX', data_get($overview, 'repo_full_name'));
        $this->assertTrue((bool) data_get($overview, 'repository_registered'));
        $this->assertSame($repo->id, data_get($overview, 'repository_artifact_id'));
        $this->assertSame(2, data_get($overview, 'work_count'));
        $this->assertSame(1, data_get($overview, 'kind_counts.pull_request'));
        $this->assertSame(1, data_get($overview, 'kind_counts.branch'));
        $this->assertSame(1, data_get($overview, 'workflow_counts.review'));
        $this->assertSame(1, data_get($overview, 'workflow_counts.now'));
        $this->assertSame($task->id, data_get($overview, 'linked_tasks.0.id'));

        $nowLane = collect($response->viewData('lanes'))->firstWhere('key', 'now');
        $reviewLane = collect($response->viewData('lanes'))->firstWhere('key', 'review');

        $this->assertCount(1, $nowLane['items']);
        $this->assertSame($branch->id, data_get($nowLane, 'items.0.id'));
        $this->assertCount(1, $reviewLane['items']);
        $this->assertSame($pr->id, data_get($reviewLane, 'items.0.id'));
    }

    private function plan(
        User $user,
        string $title,
        bool $collaborative = false,
    ): Plan {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title.'の説明',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => $collaborative,
            'collaboration_join_code' => $collaborative ? 'CNV-'.strtoupper(Str::random(6)) : null,
            'collaboration_share_token' => $collaborative ? Str::random(48) : null,
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
            'progress_percent' => 10,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function artifact(
        Plan $plan,
        string $title,
        string $url,
        array $metadata = [],
        string $artifactType = 'link',
    ): PlanArtifact {
        return PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $plan->user_id,
            'provider' => 'github',
            'artifact_type' => $artifactType,
            'title' => $title,
            'url' => $url,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
