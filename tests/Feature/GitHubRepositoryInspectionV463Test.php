<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\User;
use App\Models\UserProductGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GitHubRepositoryInspectionV463Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'services.github.api_url' => 'https://api.github.com',
            'services.github.read_token' => null,
        ]);
    }

    public function test_repository_capture_with_developer_access_loads_a_bounded_github_snapshot(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        $this->grantAllAccess($user);
        $this->fakeGitHubRepository();

        $this->actingAs($user)
            ->post(route('github_workflow.store'), [
                'plan_id' => $plan->id,
                'url' => 'https://github.com/1kz-ma1/HINANEX',
                'workflow_state' => 'now',
                'title' => '',
            ])
            ->assertRedirect(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Public Repository Previewを読み込みました。GitHub App接続後は同じ画面でauthoritative同期へ切り替わります。');

        $artifact = PlanArtifact::query()->firstOrFail();
        $snapshot = data_get($artifact->metadata, 'github_repository_snapshot');

        $this->assertSame('repository', $artifact->artifact_type);
        $this->assertNull($artifact->githubWorkflowState());
        $this->assertSame('github_rest', data_get($snapshot, 'source'));
        $this->assertSame('1kz-ma1/HINANEX', data_get($snapshot, 'repo_full_name'));
        $this->assertSame('main', data_get($snapshot, 'repository.default_branch'));
        $this->assertSame('PHP', data_get($snapshot, 'repository.language'));
        $this->assertSame('feature/map', data_get($snapshot, 'branches.1.name'));
        $this->assertSame(12, data_get($snapshot, 'pull_requests.0.number'));
        $this->assertSame(31, data_get($snapshot, 'issues.0.number'));
        $this->assertSame('success', data_get($snapshot, 'actions_runs.0.conclusion'));

        $this->actingAs($user)
            ->get(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('GITHUB NOW')
            ->assertSee('GitHubから取得した現在の構造')
            ->assertSee('feature/map')
            ->assertSee('#12 Validationを更新')
            ->assertSee('#31 E2E確認')
            ->assertSee('CI')
            ->assertSee('Public Preview更新');

        Http::assertSentCount(5);
    }

    public function test_free_repository_capture_stays_manual_and_never_calls_github_api(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        Http::fake();

        $this->actingAs($user)
            ->post(route('github_workflow.store'), [
                'plan_id' => $plan->id,
                'url' => 'https://github.com/1kz-ma1/HINANEX',
                'workflow_state' => 'now',
            ])
            ->assertSessionHasNoErrors();

        $artifact = PlanArtifact::query()->firstOrFail();
        $this->assertNull(data_get($artifact->metadata, 'github_repository_snapshot'));
        Http::assertNothingSent();

        $this->actingAs($user)
            ->get(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('Repository URLだけで終わらせない')
            ->assertSee('Developer GitHub Evidenceは現在利用不可')
            ->assertSee('現在の利用権ではRepository read / Return Evidenceを利用できません');

        $this->actingAs($user)
            ->post(route('github_workflow.repository.refresh', $artifact))
            ->assertRedirect(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertSessionHas(
                'status',
                fn (string $message) => str_contains(
                    $message,
                    'Developer GitHub Evidence',
                ),
            );
    }

    public function test_manual_refresh_updates_snapshot_without_turning_repository_into_workflow_evidence(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        $this->grantAllAccess($user);
        $artifact = PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'github',
            'artifact_type' => 'repository',
            'title' => 'HINANEX',
            'url' => 'https://github.com/1kz-ma1/HINANEX',
            'metadata' => [
                'github_workflow_state' => 'now',
                'custom_key' => 'keep',
            ],
        ]);

        $this->fakeGitHubRepository();

        $this->actingAs($user)
            ->post(route('github_workflow.repository.refresh', $artifact))
            ->assertRedirect(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'GitHubからRepositoryの現在構造を更新しました。');

        $artifact->refresh();

        $this->assertNull($artifact->githubWorkflowState());
        $this->assertSame('keep', data_get($artifact->metadata, 'custom_key'));
        $this->assertSame('main', data_get($artifact->metadata, 'github_repository_snapshot.repository.default_branch'));
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_failed_remote_inspection_keeps_repository_capture_available(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        $this->grantAllAccess($user);

        Http::fake([
            'https://api.github.com/repos/1kz-ma1/PRIVATE' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->actingAs($user)
            ->post(route('github_workflow.store'), [
                'plan_id' => $plan->id,
                'url' => 'https://github.com/1kz-ma1/PRIVATE',
                'workflow_state' => 'now',
            ])
            ->assertRedirect(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'status',
                'Public PreviewでRepositoryを取得できませんでした。Private Repositoryの場合はGitHub Appを接続してください。',
            );

        $artifact = PlanArtifact::query()->firstOrFail();

        $this->assertSame('repository', $artifact->artifact_type);
        $this->assertNull(data_get($artifact->metadata, 'github_repository_snapshot'));
        $this->assertNull($artifact->githubWorkflowState());
    }

    public function test_service_token_never_exposes_a_private_repository_to_repository_inspection(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        $this->grantAllAccess($user);

        config(['services.github.read_token' => 'server-token']);

        Http::fake([
            'https://api.github.com/repos/private-org/secret-repo' => Http::response([
                'full_name' => 'private-org/secret-repo',
                'private' => true,
                'visibility' => 'private',
                'default_branch' => 'main',
                'html_url' => 'https://github.com/private-org/secret-repo',
            ], 200),
        ]);

        $this->actingAs($user)
            ->post(route('github_workflow.store'), [
                'plan_id' => $plan->id,
                'url' => 'https://github.com/private-org/secret-repo',
                'workflow_state' => 'now',
            ])
            ->assertRedirect(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'status',
                'Private RepositoryはPublic Previewでは読み込めません。GitHub Appを接続するとPrivateのまま同期できます。',
            );

        $artifact = PlanArtifact::query()->firstOrFail();

        $this->assertNull(data_get($artifact->metadata, 'github_repository_snapshot'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) =>
            $request->url() === 'https://api.github.com/repos/private-org/secret-repo'
            && $request->hasHeader('Authorization', 'Bearer server-token')
        );
    }

    private function fakeGitHubRepository(): void
    {
        Http::fake([
            'https://api.github.com/repos/1kz-ma1/HINANEX' => Http::response([
                'full_name' => '1kz-ma1/HINANEX',
                'description' => '複数担当で開発するHINANEX',
                'default_branch' => 'main',
                'language' => 'PHP',
                'visibility' => 'public',
                'archived' => false,
                'fork' => false,
                'stargazers_count' => 3,
                'forks_count' => 1,
                'open_issues_count' => 4,
                'updated_at' => '2026-09-29T02:00:00Z',
                'pushed_at' => '2026-09-29T01:55:00Z',
                'html_url' => 'https://github.com/1kz-ma1/HINANEX',
            ], 200, ['X-RateLimit-Remaining' => '59']),
            'https://api.github.com/repos/1kz-ma1/HINANEX/branches*' => Http::response([
                [
                    'name' => 'main',
                    'protected' => true,
                    'commit' => ['sha' => str_repeat('a', 40)],
                ],
                [
                    'name' => 'feature/map',
                    'protected' => false,
                    'commit' => ['sha' => str_repeat('b', 40)],
                ],
            ]),
            'https://api.github.com/repos/1kz-ma1/HINANEX/pulls*' => Http::response([
                [
                    'number' => 12,
                    'title' => 'Validationを更新',
                    'draft' => false,
                    'head' => ['ref' => 'feature/validation'],
                    'base' => ['ref' => 'main'],
                    'updated_at' => '2026-09-29T01:50:00Z',
                    'html_url' => 'https://github.com/1kz-ma1/HINANEX/pull/12',
                ],
            ]),
            'https://api.github.com/repos/1kz-ma1/HINANEX/issues*' => Http::response([
                [
                    'number' => 12,
                    'title' => 'PRとして返るIssue API項目',
                    'pull_request' => ['url' => 'https://api.github.com/repos/1kz-ma1/HINANEX/pulls/12'],
                    'updated_at' => '2026-09-29T01:50:00Z',
                    'html_url' => 'https://github.com/1kz-ma1/HINANEX/pull/12',
                ],
                [
                    'number' => 31,
                    'title' => 'E2E確認',
                    'updated_at' => '2026-09-29T01:40:00Z',
                    'html_url' => 'https://github.com/1kz-ma1/HINANEX/issues/31',
                ],
            ]),
            'https://api.github.com/repos/1kz-ma1/HINANEX/actions/runs*' => Http::response([
                'workflow_runs' => [
                    [
                        'id' => 9001,
                        'name' => 'CI',
                        'event' => 'pull_request',
                        'status' => 'completed',
                        'conclusion' => 'success',
                        'head_branch' => 'feature/validation',
                        'updated_at' => '2026-09-29T01:45:00Z',
                        'html_url' => 'https://github.com/1kz-ma1/HINANEX/actions/runs/9001',
                    ],
                ],
            ]),
        ]);
    }

    private function grantAllAccess(User $user): void
    {
        UserProductGrant::query()->create([
            'user_id' => $user->id,
            'product_key' => ProductKey::AllAccess,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
            'metadata' => ['test' => true],
        ]);
    }

    private function plan(User $user): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'HINANEX',
            'description' => '複数担当で開発する',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }
}
