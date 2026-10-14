<?php

namespace Tests\Feature;

use App\Http\Middleware\CloseUnopenedMcpStaging;
use App\Models\User;
use App\Services\McpStagingOAuthFailureDiagnostic;
use App\Services\McpStagingSyntheticActorBootstrap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class McpStagingOAuthFailureDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_allowlisted_code_is_logged_when_isolated_link_pilot_is_running(): void
    {
        config([
            'app.env' => 'staging',
            'canovia_staging.isolated' => true,
            'canovia_staging.login_pilot_enabled' => true,
            'canovia_staging.account_link_pilot_enabled' => true,
        ]);
        Log::spy();

        $diagnostic = app(McpStagingOAuthFailureDiagnostic::class);
        $diagnostic->record('link_callback_state');
        // Attacker-controlled diagnostics never appear in logs.
        $diagnostic->record('state=SECRET&code=SECRET');
        $diagnostic->record('link_callback_state'."\n".'access_token=SECRET');

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Canovia MCP staging OAuth failure', [
                'stage_code' => 'link_callback_state',
            ]);
    }

    public function test_plan_consent_callback_codes_are_fixed_and_stage_only(): void
    {
        config([
            'app.env' => 'staging',
            'canovia_staging.isolated' => true,
            'canovia_staging.login_pilot_enabled' => true,
            'canovia_staging.account_link_pilot_enabled' => true,
        ]);

        Log::spy();
        $allowed = [
            'link_callback_plan_fixture_missing',
            'link_callback_plan_stage_guard',
            'link_callback_plan_consent_disabled',
            'link_callback_plan_scope_invalid',
            'link_callback_plan_owner',
            'link_callback_plan_link_mismatch',
        ];
        $diag = app(McpStagingOAuthFailureDiagnostic::class);
        foreach ($allowed as $code) {
            $diag->record($code);
            Log::shouldHaveReceived('warning')
                ->once()
                ->with('Canovia MCP staging OAuth failure', ['stage_code' => $code]);
        }

        // No dynamic identifiers, state, token, subjects or URL fragments
        // can ever become diagnostic codes or leak in message context.
        $diag->record('link_callback_plan_link_mismatch:sub=SECRET');
        $diag->record('link_callback_plan_scope_invalid?code=SECRET');
        $diag->record('https://chatgpt.com/oauth/client.json');
        Log::shouldHaveReceived('warning')->times(count($allowed));

        // Suppressed entirely on non-isolated stage and in production.
        config(['canovia_staging.isolated' => false]);
        $diag->record('link_callback_plan_link_mismatch');
        config(['canovia_staging.isolated' => true, 'app.env' => 'production']);
        $diag->record('link_callback_plan_link_mismatch');
        Log::shouldHaveReceived('warning')->times(count($allowed));
    }

    public function test_disabled_pilot_production_and_wrong_isolation_never_log_diagnostics(): void
    {
        Log::spy();
        $diagnostic = app(McpStagingOAuthFailureDiagnostic::class);
        foreach ([
            ['app.env' => 'production', 'canovia_staging.isolated' => true,
                'canovia_staging.login_pilot_enabled' => true,
                'canovia_staging.account_link_pilot_enabled' => true],
            ['app.env' => 'staging', 'canovia_staging.isolated' => false,
                'canovia_staging.login_pilot_enabled' => true,
                'canovia_staging.account_link_pilot_enabled' => true],
            ['app.env' => 'staging', 'canovia_staging.isolated' => true,
                'canovia_staging.login_pilot_enabled' => true,
                'canovia_staging.account_link_pilot_enabled' => false],
        ] as $unsafe) {
            config($unsafe);
            $diagnostic->record('link_callback_token');
        }

        Log::shouldNotHaveReceived('warning');
    }

    public function test_real_staged_callback_failure_keeps_generic_response_and_safe_log(): void
    {
        $this->withoutMiddleware(CloseUnopenedMcpStaging::class);
        config([
            'app.env' => 'staging',
            'canovia_staging.isolated' => true,
            'canovia_staging.login_pilot_enabled' => true,
            'canovia_staging.account_link_pilot_enabled' => true,
            // No IdP client configuration is present; fail before contacting
            // Auth0 or reading request code/state content.
            'canovia_mcp.account_link_enabled' => false,
        ]);
        $actor = User::factory()->create([
            'email' => McpStagingSyntheticActorBootstrap::EMAIL,
            'first_run_completed_at' => now(),
        ]);
        Log::spy();
        $this->actingAs($actor)->get('/account/mcp/link/callback?state=fake&code=fake')
            ->assertRedirect(route('auth.account'));
        $this->get('/account')
            ->assertOk()
            ->assertSee('外部IDの本人確認を完了できませんでした。')
            ->assertDontSee('link_callback_configuration');

        Log::shouldHaveReceived('warning')->once()->with(
            'Canovia MCP staging OAuth failure',
            ['stage_code' => 'link_callback_configuration'],
        );
    }
}
