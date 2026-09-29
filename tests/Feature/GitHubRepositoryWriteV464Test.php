<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GitHubRepositoryWriteV464Test extends TestCase
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
            'services.github.app_id' => '12345',
            'services.github.app_private_key' => $this->privateKey(),
            'services.github.app_private_key_base64' => null,
            'services.github.app_install_url' => 'https://github.com/apps/canovia/installations/new',
        ]);
    }

    public function test_editor_can_submit_one_file_as_branch_commit_and_pull_request_through_github_app(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        $repo = $this->repository($plan, $user);
        $this->grantAllAccess($user);

        $this->fakeSuccessfulGitHubWrite();

        $beforeEvidence = TaskEvidence::query()->count();

        $response = $this->actingAs($user)
            ->post(route('github_workflow.repository.change', $repo), [
                'file_path' => 'app/Services/MapService.php',
                'file_content' => "<?php\n\nfinal class MapService {}\n",
                'commit_message' => 'fix map interaction',
                'pull_request_title' => 'Map interactionを修正',
                'pull_request_body' => 'ズーム操作の修正です。',
            ]);

        $prArtifact = PlanArtifact::query()
            ->where('provider', 'github')
            ->where('artifact_type', 'link')
            ->firstOrFail();

        $response
            ->assertRedirect(route('github_workflow.index', ['plan_id' => $plan->id]).'#github-item-'.$prArtifact->id)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '変更をレビュー用Pull RequestとしてGitHubへ反映しました。mainにはまだ反映されていません。');

        $this->assertSame('review', $prArtifact->githubWorkflowState());
        $this->assertSame('https://github.com/1kz-ma1/HINANEX/pull/55', $prArtifact->url);
        $this->assertSame('55', $prArtifact->external_id);
        $this->assertSame('github_app', data_get($prArtifact->metadata, 'github_write_origin.executed_by'));
        $this->assertSame('app/Services/MapService.php', data_get($prArtifact->metadata, 'github_write_origin.file_path'));
        $this->assertStringStartsWith('canovia/', (string) data_get($prArtifact->metadata, 'github_write_origin.branch'));

        $repo->refresh();
        $this->assertNull($repo->githubWorkflowState());
        $this->assertSame(55, data_get($repo->metadata, 'github_last_write.pull_request_number'));
        $this->assertSame('github_app', data_get($repo->metadata, 'github_last_write.executed_by'));

        $this->assertSame($beforeEvidence, TaskEvidence::query()->count());

        Http::assertSentCount(8);
        Http::assertSent(function (HttpRequest $request) {
            if ($request->method() !== 'POST' || $request->url() !== 'https://api.github.com/repos/1kz-ma1/HINANEX/git/refs') {
                return false;
            }

            return str_starts_with((string) data_get($request->data(), 'ref'), 'refs/heads/canovia/')
                && data_get($request->data(), 'sha') === str_repeat('a', 40);
        });
        Http::assertSent(function (HttpRequest $request) {
            if ($request->method() !== 'PUT' || $request->url() !== 'https://api.github.com/repos/1kz-ma1/HINANEX/contents/app/Services/MapService.php') {
                return false;
            }

            return str_starts_with((string) data_get($request->data(), 'branch'), 'canovia/')
                && data_get($request->data(), 'sha') === str_repeat('b', 40)
                && data_get($request->data(), 'message') === 'fix map interaction';
        });
        Http::assertSent(function (HttpRequest $request) {
            if ($request->method() !== 'POST' || $request->url() !== 'https://api.github.com/repos/1kz-ma1/HINANEX/pulls') {
                return false;
            }

            return data_get($request->data(), 'base') === 'main'
                && str_starts_with((string) data_get($request->data(), 'head'), 'canovia/')
                && data_get($request->data(), 'title') === 'Map interactionを修正';
        });

        Http::assertNotSent(fn (HttpRequest $request) =>
            str_contains($request->url(), '/merge')
            || $request->method() === 'DELETE'
        );
    }

    public function test_user_without_github_write_capability_cannot_trigger_remote_write(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        $repo = $this->repository($plan, $user);
        Http::fake();

        $this->actingAs($user)
            ->post(route('github_workflow.repository.change', $repo), [
                'file_path' => 'README.md',
                'file_content' => 'updated',
                'commit_message' => 'update readme',
                'pull_request_title' => 'README更新',
            ])
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('plan_artifacts', 1);
    }

    public function test_missing_repository_installation_does_not_create_branch_or_pr(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        $repo = $this->repository($plan, $user);
        $this->grantAllAccess($user);

        Http::fake([
            'https://api.github.com/repos/1kz-ma1/HINANEX/installation' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->actingAs($user)
            ->post(route('github_workflow.repository.change', $repo), [
                'file_path' => 'README.md',
                'file_content' => 'updated',
                'commit_message' => 'update readme',
                'pull_request_title' => 'README更新',
            ])
            ->assertRedirect(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertSessionHas(
                'status',
                'Canovia GitHub AppがこのRepositoryに接続されていません。Repository管理者に接続してもらってください。',
            );

        Http::assertSentCount(1);
        $this->assertDatabaseCount('plan_artifacts', 1);
        $this->assertNull(data_get($repo->fresh()->metadata, 'github_last_write'));
    }

    public function test_github_workflow_files_are_rejected_before_any_remote_write(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        $repo = $this->repository($plan, $user);
        $this->grantAllAccess($user);
        Http::fake();

        $this->actingAs($user)
            ->post(route('github_workflow.repository.change', $repo), [
                'file_path' => '.github/workflows/ci.yml',
                'file_content' => 'name: CI',
                'commit_message' => 'change ci',
                'pull_request_title' => 'CI変更',
            ])
            ->assertRedirect(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertSessionHas(
                'status',
                '.github/workflows 配下はV46.4の安全境界では変更できません。',
            );

        Http::assertNothingSent();
        $this->assertDatabaseCount('plan_artifacts', 1);
    }

    public function test_write_surface_explains_one_time_github_app_setup_and_no_direct_merge(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        $this->repository($plan, $user);
        $this->grantAllAccess($user);

        $this->actingAs($user)
            ->get(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('GitHubを知らなくても、変更をレビューに出す')
            ->assertSee('Repository管理者がCanovia GitHub Appを一度接続すれば')
            ->assertSee('変更をレビューに出す')
            ->assertSee('mainへ直接push・mergeはしません')
            ->assertSee('GitHub Appを接続');
    }

    private function fakeSuccessfulGitHubWrite(): void
    {
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'GET' && $url === 'https://api.github.com/repos/1kz-ma1/HINANEX/installation') {
                return Http::response(['id' => 777], 200);
            }

            if ($method === 'POST' && $url === 'https://api.github.com/app/installations/777/access_tokens') {
                return Http::response([
                    'token' => 'installation-token',
                    'expires_at' => now()->addHour()->toIso8601String(),
                    'permissions' => [
                        'metadata' => 'read',
                        'contents' => 'write',
                        'pull_requests' => 'write',
                    ],
                ], 201);
            }

            if ($method === 'GET' && $url === 'https://api.github.com/repos/1kz-ma1/HINANEX') {
                return Http::response([
                    'full_name' => '1kz-ma1/HINANEX',
                    'private' => true,
                    'visibility' => 'private',
                    'archived' => false,
                    'default_branch' => 'main',
                ], 200);
            }

            if ($method === 'GET' && $url === 'https://api.github.com/repos/1kz-ma1/HINANEX/git/ref/heads/main') {
                return Http::response([
                    'ref' => 'refs/heads/main',
                    'object' => ['sha' => str_repeat('a', 40)],
                ], 200);
            }

            if ($method === 'GET' && str_starts_with($url, 'https://api.github.com/repos/1kz-ma1/HINANEX/contents/app/Services/MapService.php')) {
                return Http::response([
                    'type' => 'file',
                    'sha' => str_repeat('b', 40),
                    'path' => 'app/Services/MapService.php',
                ], 200);
            }

            if ($method === 'POST' && $url === 'https://api.github.com/repos/1kz-ma1/HINANEX/git/refs') {
                return Http::response([
                    'ref' => data_get($request->data(), 'ref'),
                    'object' => ['sha' => str_repeat('a', 40)],
                ], 201);
            }

            if ($method === 'PUT' && $url === 'https://api.github.com/repos/1kz-ma1/HINANEX/contents/app/Services/MapService.php') {
                return Http::response([
                    'content' => [
                        'path' => 'app/Services/MapService.php',
                        'sha' => str_repeat('c', 40),
                    ],
                    'commit' => [
                        'sha' => str_repeat('d', 40),
                    ],
                ], 200);
            }

            if ($method === 'POST' && $url === 'https://api.github.com/repos/1kz-ma1/HINANEX/pulls') {
                return Http::response([
                    'number' => 55,
                    'title' => 'Map interactionを修正',
                    'state' => 'open',
                    'html_url' => 'https://github.com/1kz-ma1/HINANEX/pull/55',
                ], 201);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });
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
            'description' => 'GitHubを知らない担当もCanoviaから変更を出す',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }

    private function repository(Plan $plan, User $user): PlanArtifact
    {
        return PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'github',
            'artifact_type' => 'repository',
            'title' => 'HINANEX',
            'url' => 'https://github.com/1kz-ma1/HINANEX',
            'metadata' => null,
        ]);
    }

    private function privateKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($key);

        $pem = '';
        $this->assertTrue(openssl_pkey_export($key, $pem));
        $this->assertNotSame('', $pem);

        return $pem;
    }
}
