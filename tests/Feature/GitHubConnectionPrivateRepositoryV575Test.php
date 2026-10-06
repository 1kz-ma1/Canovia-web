<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\User;
use App\Models\UserProductGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GitHubConnectionPrivateRepositoryV575Test extends TestCase
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
            'services.github.app_install_url' =>
                'https://github.com/apps/canovia/installations/new',
            'native_ai.driver' => 'disabled',
        ]);
    }

    public function test_read_capability_can_start_connection_without_write_capability(): void
    {
        config([
            'entitlements.features.developer_github_evidence.free' => true,
            'entitlements.features.developer_github_write.free' => false,
        ]);

        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $plan = $this->plan($user);
        $repository = $this->repository($plan, $user);

        $response = $this->actingAs($user)
            ->post(route('github_workflow.app.connect', $repository))
            ->assertRedirect();

        $location = (string) $response->headers->get('Location');

        $this->assertStringStartsWith(
            'https://github.com/apps/canovia/installations/new?state=',
            $location,
        );

        $repository->refresh();

        $this->assertSame(
            'connecting',
            data_get(
                $repository->metadata,
                'github_app_connection.status',
            ),
        );
    }

    public function test_connected_private_repository_uses_github_app_snapshot(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $this->grantAllAccess($user);

        $plan = $this->plan($user);
        $repository = $this->repository(
            $plan,
            $user,
            connected: true,
            writeReady: false,
        );

        Http::fake(function (HttpRequest $request) {
            $url = $request->url();

            if (
                $request->method() === 'GET'
                && $url === 'https://api.github.com/repos/private-org/secret-repo/installation'
            ) {
                return Http::response([
                    'id' => 777,
                ], 200);
            }

            if (
                $request->method() === 'POST'
                && $url === 'https://api.github.com/app/installations/777/access_tokens'
            ) {
                return Http::response([
                    'token' => 'installation-token',
                    'permissions' => [
                        'metadata' => 'read',
                        'contents' => 'read',
                        'pull_requests' => 'read',
                        'issues' => 'read',
                    ],
                ], 201);
            }

            if (
                $request->method() === 'GET'
                && $url === 'https://api.github.com/repos/private-org/secret-repo'
            ) {
                return Http::response([
                    'full_name' => 'private-org/secret-repo',
                    'private' => true,
                    'visibility' => 'private',
                    'description' => 'Private development repository',
                    'default_branch' => 'main',
                    'language' => 'PHP',
                    'archived' => false,
                    'fork' => false,
                    'stargazers_count' => 0,
                    'forks_count' => 0,
                    'open_issues_count' => 1,
                    'updated_at' => '2026-10-06T03:00:00Z',
                    'pushed_at' => '2026-10-06T02:55:00Z',
                    'html_url' =>
                        'https://github.com/private-org/secret-repo',
                ], 200, [
                    'X-RateLimit-Remaining' => '4998',
                ]);
            }

            if (
                $request->method() === 'GET'
                && str_starts_with(
                    $url,
                    'https://api.github.com/repos/private-org/secret-repo/branches',
                )
            ) {
                return Http::response([[
                    'name' => 'main',
                    'protected' => true,
                    'commit' => [
                        'sha' => str_repeat('a', 40),
                    ],
                ]], 200);
            }

            if (
                $request->method() === 'GET'
                && str_starts_with(
                    $url,
                    'https://api.github.com/repos/private-org/secret-repo/pulls',
                )
            ) {
                return Http::response([[
                    'number' => 12,
                    'title' => 'Private PR',
                    'draft' => false,
                    'head' => ['ref' => 'feature/private'],
                    'base' => ['ref' => 'main'],
                    'updated_at' => '2026-10-06T02:50:00Z',
                    'html_url' =>
                        'https://github.com/private-org/secret-repo/pull/12',
                ]], 200);
            }

            if (
                $request->method() === 'GET'
                && str_starts_with(
                    $url,
                    'https://api.github.com/repos/private-org/secret-repo/issues',
                )
            ) {
                return Http::response([[
                    'number' => 31,
                    'title' => 'Private Issue',
                    'updated_at' => '2026-10-06T02:45:00Z',
                    'html_url' =>
                        'https://github.com/private-org/secret-repo/issues/31',
                ]], 200);
            }

            return Http::response([
                'message' =>
                    'Unexpected request '.$request->method().' '.$url,
            ], 500);
        });

        $this->actingAs($user)
            ->post(route(
                'github_workflow.repository.refresh',
                $repository,
            ))
            ->assertRedirect(route(
                'github_workflow.index',
                ['plan_id' => $plan->id],
            ))
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'success',
                'GitHubからRepositoryの現在構造を更新しました。',
            );

        $repository->refresh();

        $snapshot = data_get(
            $repository->metadata,
            'github_repository_snapshot',
        );

        $this->assertSame(
            'github_app_rest',
            data_get($snapshot, 'source'),
        );
        $this->assertSame(
            'private',
            data_get($snapshot, 'repository.visibility'),
        );
        $this->assertSame(
            'main',
            data_get($snapshot, 'repository.default_branch'),
        );
        $this->assertSame(
            12,
            data_get($snapshot, 'pull_requests.0.number'),
        );
        $this->assertSame(
            31,
            data_get($snapshot, 'issues.0.number'),
        );

        $this->actingAs($user)
            ->get(route('github_workflow.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('GitHub App sync')
            ->assertSee('private')
            ->assertSee('GitHub接続済み')
            ->assertSee('Read only')
            ->assertSee('Private Repository');

        Http::assertSent(fn (HttpRequest $request) =>
            $request->url() ===
                'https://api.github.com/repos/private-org/secret-repo'
            && $request->hasHeader(
                'Authorization',
                'Bearer installation-token',
            )
        );
    }

    public function test_developer_home_exposes_direct_connection_action(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $this->grantAllAccess($user);

        $plan = $this->plan($user);
        $repository = $this->repository($plan, $user);

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-development-github-connection',
                false,
            )
            ->assertSee(
                route('github_workflow.app.connect', $repository),
                false,
            )
            ->assertSee('GitHubを接続')
            ->assertSee(
                'Private RepositoryをPublicへ変更する必要はありません。',
            );
    }

    public function test_read_only_connection_does_not_become_write_handoff_target(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $this->grantAllAccess($user);

        $plan = $this->plan($user);
        $task = $plan->tasks()->create([
            'title' => 'Private Repositoryを使う',
            'description' => null,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
        $repository = $this->repository(
            $plan,
            $user,
            connected: true,
            writeReady: false,
        );

        $service = app(\App\Services\ExecutionGitHubHandoffService::class);

        $this->expectException(
            \Illuminate\Validation\ValidationException::class,
        );

        $service->prepare(
            request(),
            $plan,
            $task,
            $repository,
            'README.md',
            'content',
            'update readme',
            'Update README',
            null,
            $user,
        );
    }

    private function plan(User $user): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Private Development',
            'description' => 'Private Repository connection',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }

    private function repository(
        Plan $plan,
        User $user,
        bool $connected = false,
        bool $writeReady = false,
    ): PlanArtifact {
        return PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'github',
            'artifact_type' => 'repository',
            'title' => 'secret-repo',
            'url' => 'https://github.com/private-org/secret-repo',
            'metadata' => $connected
                ? [
                    'github_app_connection' => [
                        'status' => 'connected',
                        'installation_id' => 777,
                        'account_login' => 'private-org',
                        'read_ready' => true,
                        'write_ready' => $writeReady,
                        'permissions' => [
                            'contents' => $writeReady
                                ? 'write'
                                : 'read',
                            'pull_requests' => $writeReady
                                ? 'write'
                                : 'read',
                            'issues' => 'read',
                        ],
                    ],
                ]
                : null,
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
