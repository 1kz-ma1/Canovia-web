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

    public function test_allowlist_includes_only_fixed_rate_limit_and_github_subject_codes(): void
    {
        config([
            'app.env' => 'staging',
            'canovia_staging.isolated' => true,
            'canovia_staging.login_pilot_enabled' => true,
            'canovia_staging.account_link_pilot_enabled' => true,
        ]);
        Log::spy();
        $diagnostic = app(McpStagingOAuthFailureDiagnostic::class);
        foreach ([
            'link_provider_metadata_rate_limited',
            'link_provider_token_rate_limited',
            'link_provider_github_subject_rejected',
        ] as $code) {
            $diagnostic->record($code);
        }
        $diagnostic->record('link_provider_token_rate_limited=access_token:private');
        $diagnostic->record('link_provider_github_subject_rejected'.chr(10).'secret');
        $observed = [];
        Log::shouldHaveReceived('warning')->withArgs(
            static function ($message, $context) use (&$observed): bool {
                if ($message !== 'Canovia MCP staging OAuth failure'
                    || ! is_array($context)
                    || array_keys($context) !== ['stage_code']
                    || ! is_string($context['stage_code'])) {
                    return false;
                }
                $observed[] = $context['stage_code'];
                return true;
            },
        )->times(3);
        $this->assertSame([
            'link_provider_metadata_rate_limited',
            'link_provider_token_rate_limited',
            'link_provider_github_subject_rejected',
        ], $observed);
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
            ->assertSee('本人確認を完了できませんでした。')
            ->assertDontSee('link_callback_configuration');

        Log::shouldHaveReceived('warning')->once()->with(
            'Canovia MCP staging OAuth failure',
            ['stage_code' => 'link_callback_configuration'],
        );
    }
}
