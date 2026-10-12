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
