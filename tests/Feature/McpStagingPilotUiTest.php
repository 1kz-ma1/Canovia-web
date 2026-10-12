<?php

namespace Tests\Feature;

use App\Http\Middleware\CloseUnopenedMcpStaging;
use App\Models\User;
use App\Services\McpStagingSyntheticActorBootstrap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class McpStagingPilotUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Separate tests already prove the pinned PostgreSQL closed/open gate.
        // This suite exercises only Blade/route semantics without a live DB.
        $this->withoutMiddleware(CloseUnopenedMcpStaging::class);
        config([
            'app.env' => 'staging',
            'canovia_staging.isolated' => true,
            'canovia_staging.login_pilot_enabled' => true,
            'canovia_staging.account_link_pilot_enabled' => false,
            'canovia_mcp.discovery_enabled' => false,
            'canovia_mcp.account_link_enabled' => false,
            'canovia_mcp.auth0_jwt_verification_enabled' => false,
            'canovia_mcp.plan_consent_enabled' => false,
            'canovia_mcp.tools_enabled' => false,
        ]);
    }

    public function test_stage_login_renders_self_contained_styled_form_without_product_shell(): void
    {
        $response = $this->get('/login')->assertOk();
        $response->assertSee('検証用アカウントにログイン')
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('type="submit"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('font-family:', false)
            ->assertSee('MCP STAGING')
            ->assertDontSee('resources/js/app.js')
            ->assertDontSee('/build/assets/')
            ->assertDontSee('Canoviaにログイン')
            ->assertDontSee('アカウント作成');
    }

    public function test_synthetic_actor_can_login_and_see_bounded_account_and_logout(): void
    {
        $password = 'stage-strong-test-only-password-123';
        User::factory()->create([
            'email' => McpStagingSyntheticActorBootstrap::EMAIL,
            'password' => Hash::make($password),
            'first_run_completed_at' => now(),
        ]);
        $this->post('/login', [
            'email' => McpStagingSyntheticActorBootstrap::EMAIL,
            'password' => $password,
            'remember' => '1',
        ])->assertRedirect('/account');

        $this->get('/account')->assertOk()
            ->assertSee('ログインできました')
            ->assertSee(McpStagingSyntheticActorBootstrap::EMAIL)
            ->assertSee('Auth0の本人IDを確認')
            ->assertSee('まだ無効です')
            ->assertSee('ログアウト')
            ->assertDontSee('アカウントと本人所有データを削除')
            ->assertDontSee('プライバシーポリシー')
            ->assertDontSee('resources/js/app.js')
            ->assertDontSee('name="duration_days"', false)
            ->assertDontSee('Auth0で本人確認を開始');
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_stage_link_actions_obey_the_independent_pilot_route_gate(): void
    {
        $user = User::factory()->create([
            'email' => McpStagingSyntheticActorBootstrap::EMAIL,
            'first_run_completed_at' => now(),
        ]);

        // Deliberately configure a complete link provider while leaving the
        // independent stage-link approval OFF. Credentials/configuration
        // alone must not offer a button whose POST would be denied.
        config([
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.resource_url' => 'https://canovia-mcp-staging.onrender.com/api/mcp',
            'canovia_mcp.oauth_issuer' => 'https://canovia-mcp-dev.jp.auth0.com/',
            'canovia_mcp.read_scope' => 'canovia.development.read',
            'canovia_mcp.token_verification_driver' => 'auth0_jwt',
            'canovia_mcp.token_introspection_enabled' => false,
            'canovia_mcp.auth0_jwt_verification_enabled' => true,
            'canovia_mcp.account_link_enabled' => true,
            'canovia_mcp.allowed_client_id' => 'tpc_synthetic-chatgpt-client',
            'canovia_mcp.account_link_client_id' => 'synthetic-stage-link-client',
            'canovia_mcp.account_link_client_secret' => 'synthetic-stage-link-secret-123456',
            'canovia_mcp.identity_fingerprint_key' => str_repeat('s', 48),
            'canovia_mcp.account_link_metadata_url' => 'https://canovia-mcp-dev.jp.auth0.com/.well-known/openid-configuration',
            'canovia_mcp.account_link_authorization_endpoint' => 'https://canovia-mcp-dev.jp.auth0.com/authorize',
            'canovia_mcp.account_link_token_endpoint' => 'https://canovia-mcp-dev.jp.auth0.com/oauth/token',
            'canovia_mcp.account_link_redirect_uri' => 'https://canovia-mcp-staging.onrender.com/account/mcp/link/callback',
        ]);

        $this->actingAs($user)->get('/account')->assertOk()
            ->assertSee('Auth0の本人確認はまだ無効です')
            ->assertDontSee('Auth0で本人確認を開始')
            ->assertDontSee('本人IDの紐付けを確定');

        config(['canovia_staging.account_link_pilot_enabled' => true]);
        $this->get('/account')->assertOk()
            ->assertSee('Auth0で本人確認を開始')
            ->assertDontSee('本人IDの紐付けを確定')
            ->assertDontSee('name="duration_days"', false);

        // Disabling the pilot must also suppress a verified-session confirm
        // action, even if another tab completed OAuth before the gate closed.
        config(['canovia_staging.account_link_pilot_enabled' => false]);
        $this->withSession(['mcp.account_link.verified' => [
            'actor_id' => (int) $user->id,
            'identity_fingerprint' => str_repeat('a', 64),
            'verified_at' => now()->timestamp,
        ]])->get('/account')->assertOk()
            ->assertSee('Auth0の本人確認はまだ無効です')
            ->assertDontSee('本人IDの紐付けを確定')
            ->assertDontSee('Auth0で本人確認を開始');

        config([
            'canovia_staging.account_link_pilot_enabled' => true,
            'canovia_mcp.tools_enabled' => true,
        ]);
        $this->get('/account')->assertOk()
            ->assertSee('Auth0の本人確認はまだ無効です')
            ->assertDontSee('本人IDの紐付けを確定')
            ->assertDontSee('Auth0で本人確認を開始');

        $this->assertDatabaseCount('mcp_linked_subjects', 0);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
    }

    public function test_production_login_view_is_unchanged(): void
    {
        config([
            'app.env' => 'production',
            'canovia_staging.login_pilot_enabled' => false,
        ]);
        $this->withoutVite();
        $this->get('/login')->assertOk()
            ->assertSee('Canoviaにログイン')
            ->assertDontSee('STAGING / SYNTHETIC USER ONLY');
    }
}
