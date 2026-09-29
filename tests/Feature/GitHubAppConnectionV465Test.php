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

class GitHubAppConnectionV465Test extends TestCase
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

    public function test_connect_flow_adds_one_time_state_and_marks_repository_connecting(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        $repo = $this->repository($plan, $user);
        $this->grantAllAccess($user);

        $response = $this->actingAs($user)
            ->post(route('github_workflow.app.connect', $repo))
            ->assertRedirect();

        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://github.com/apps/canovia/installations/new?state=', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $state = (string) ($query['state'] ?? '');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $state);

        $repo->refresh();

        $this->assertSame('connecting', data_get($repo->metadata, 'github_app_connection.status'));
        $this->assertSame($user->id, data_get($repo->metadata, 'github_app_connection.requested_by_user_id'));
        $this->assertNotNull(session('github_app_install_state.'.hash('sha256', $state)));
    }

    public function test_setup_callback_verifies_exact_repository_installation_before_marking_connected(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        $repo = $this->repository($plan, $user);
        $this->grantAllAccess($user);

        $state = $this->beginConnection($user, $repo);

        Http::fake([
            'https://api.github.com/repos/1kz-ma1/HINANEX/installation' => Http::response([
                'id' => 777,
                'account' => [
                    'login' => '1kz-ma1',
                    'type' => 'User',
                ],
                'target_type' => 'User',
                'repository_selection' => 'selected',
                'permissions' => [
                    'metadata' => 'read',
                    'contents' => 'write',
                    'pull_requests' => 'write',
                ],
                'html_url' => 'https://github.com/settings/installations/777',
            ], 200),
        ]);

        $this->actingAs($user)
            ->get(route('github_workflow.app.setup', [
                'state' => $state,
                'installation_id' => 777,
                'setup_action' => 'install',
            ]))
            ->assertRedirect(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertSessionHas(
                'success',
                'GitHub Repositoryとの接続を確認しました。Canoviaからレビュー用PRを作成できます。',
            );

        $repo->refresh();
        $connection = data_get($repo->metadata, 'github_app_connection');

        $this->assertSame('connected', data_get($connection, 'status'));
        $this->assertSame(777, data_get($connection, 'installation_id'));
        $this->assertSame('1kz-ma1', data_get($connection, 'account_login'));
        $this->assertSame('selected', data_get($connection, 'repository_selection'));
        $this->assertSame('write', data_get($connection, 'permissions.contents'));
        $this->assertSame('write', data_get($connection, 'permissions.pull_requests'));
        $this->assertSame('https://github.com/settings/installations/777', data_get($connection, 'management_url'));
        $this->assertArrayNotHasKey('token', $connection);
        $this->assertArrayNotHasKey('private_key', $connection);
        $this->assertNull(session('github_app_install_state.'.hash('sha256', $state)));

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request) =>
            $request->method() === 'GET'
            && $request->url() === 'https://api.github.com/repos/1kz-ma1/HINANEX/installation'
        );
    }

    public function test_spoofed_callback_installation_id_is_not_trusted(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        $repo = $this->repository($plan, $user);
        $this->grantAllAccess($user);

        $state = $this->beginConnection($user, $repo);

        Http::fake([
            'https://api.github.com/repos/1kz-ma1/HINANEX/installation' => Http::response([
                'id' => 777,
                'account' => ['login' => '1kz-ma1', 'type' => 'User'],
                'target_type' => 'User',
                'repository_selection' => 'selected',
                'permissions' => [
                    'contents' => 'write',
                    'pull_requests' => 'write',
                ],
            ], 200),
        ]);

        $this->actingAs($user)
            ->get(route('github_workflow.app.setup', [
                'state' => $state,
                'installation_id' => 999,
                'setup_action' => 'install',
            ]))
            ->assertRedirect(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertSessionHas(
                'status',
                'GitHubから返された接続情報と対象Repositoryが一致しません。',
            );

        $repo->refresh();

        $this->assertSame('verification_failed', data_get($repo->metadata, 'github_app_connection.status'));
        $this->assertNull(data_get($repo->metadata, 'github_app_connection.installation_id'));
    }

    public function test_missing_installation_is_shown_as_pending_for_organization_approval(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        $repo = $this->repository($plan, $user);
        $this->grantAllAccess($user);

        Http::fake([
            'https://api.github.com/repos/1kz-ma1/HINANEX/installation' => Http::response([
                'message' => 'Not Found',
            ], 404),
        ]);

        $this->actingAs($user)
            ->post(route('github_workflow.app.check', $repo))
            ->assertRedirect(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertSessionHas(
                'status',
                'GitHub AppはまだこのRepositoryへ接続されていません。OrganizationではOwner承認待ちの可能性があります。',
            );

        $repo->refresh();
        $this->assertSame('pending', data_get($repo->metadata, 'github_app_connection.status'));

        $this->actingAs($user)
            ->get(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('GitHub側の接続完了を待っています')
            ->assertSee('Owner承認が必要な場合があります')
            ->assertSee('接続状態を確認');
    }

    public function test_manual_check_discovers_existing_installation_without_callback(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        $repo = $this->repository($plan, $user);
        $this->grantAllAccess($user);

        Http::fake([
            'https://api.github.com/repos/1kz-ma1/HINANEX/installation' => Http::response([
                'id' => 888,
                'account' => [
                    'login' => 'example-org',
                    'type' => 'Organization',
                ],
                'target_type' => 'Organization',
                'repository_selection' => 'selected',
                'permissions' => [
                    'metadata' => 'read',
                    'contents' => 'write',
                    'pull_requests' => 'write',
                ],
                'html_url' => 'https://github.com/organizations/example-org/settings/installations/888',
            ], 200),
        ]);

        $this->actingAs($user)
            ->post(route('github_workflow.app.check', $repo))
            ->assertRedirect(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertSessionHas('success', 'GitHub Appの接続状態を確認しました。');

        $repo->refresh();

        $this->assertSame('connected', data_get($repo->metadata, 'github_app_connection.status'));
        $this->assertSame('example-org', data_get($repo->metadata, 'github_app_connection.account_login'));
        $this->assertSame('Organization', data_get($repo->metadata, 'github_app_connection.target_type'));
    }

    public function test_installation_without_required_write_permissions_is_not_write_ready(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        $repo = $this->repository($plan, $user);
        $this->grantAllAccess($user);

        Http::fake([
            'https://api.github.com/repos/1kz-ma1/HINANEX/installation' => Http::response([
                'id' => 777,
                'account' => ['login' => '1kz-ma1', 'type' => 'User'],
                'target_type' => 'User',
                'repository_selection' => 'selected',
                'permissions' => [
                    'metadata' => 'read',
                    'contents' => 'read',
                    'pull_requests' => 'write',
                ],
            ], 200),
        ]);

        $this->actingAs($user)
            ->post(route('github_workflow.app.check', $repo))
            ->assertRedirect(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertSessionHas(
                'status',
                'GitHub Appは存在しますが、必要なwrite権限がまだ承認されていません。',
            );

        $repo->refresh();
        $this->assertSame(
            'permission_update_required',
            data_get($repo->metadata, 'github_app_connection.status'),
        );

        $this->actingAs($user)
            ->get(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('GitHub Appのwrite権限承認が必要です')
            ->assertDontSee('＋ 変更をレビューに出す');
    }

    public function test_callback_state_is_one_time_and_bound_to_the_canovia_user(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);
        $repo = $this->repository($plan, $owner);
        $this->grantAllAccess($owner);
        $this->grantAllAccess($other);

        $state = $this->beginConnection($owner, $repo);
        Http::fake();

        $this->actingAs($other)
            ->get(route('github_workflow.app.setup', [
                'state' => $state,
                'installation_id' => 777,
                'setup_action' => 'install',
            ]))
            ->assertRedirect(route('github_workflow.index'))
            ->assertSessionHas(
                'status',
                'GitHub接続の確認情報が一致しません。もう一度接続を開始してください。',
            );

        Http::assertNothingSent();
    }

    private function beginConnection(User $user, PlanArtifact $repo): string
    {
        $response = $this->actingAs($user)
            ->post(route('github_workflow.app.connect', $repo))
            ->assertRedirect();

        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return (string) ($query['state'] ?? '');
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
            'description' => 'GitHub App接続UX',
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
