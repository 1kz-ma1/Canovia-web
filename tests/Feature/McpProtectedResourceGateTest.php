<?php

namespace Tests\Feature;

use App\Models\DevelopmentAiSharingPreference;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class McpProtectedResourceGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'canovia_mcp.discovery_enabled' => false,
            'canovia_mcp.resource_url' => '',
            'canovia_mcp.oauth_issuer' => '',
            'canovia_mcp.read_scope' => 'canovia.development.read',
        ]);
    }

    public function test_default_disabled_metadata_and_mcp_do_not_accept_sessions_or_tokens(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource')->assertNotFound();
        $this->getJson('/.well-known/oauth-protected-resource/api/mcp')->assertNotFound();
        $this->getJson('/api/mcp')->assertNotFound();
        $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
        ])->assertNotFound();

        $this->withHeader('Authorization', 'Bearer forged-token')
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            ])->assertNotFound();
    }

    public function test_https_resource_metadata_matches_configured_oauth_issuer_and_scope_only(): void
    {
        $this->enableDiscovery();

        foreach ([
            '/.well-known/oauth-protected-resource',
            '/.well-known/oauth-protected-resource/api/mcp',
        ] as $path) {
            $this->getJson($path)
                ->assertOk()
                ->assertExactJson([
                    'resource' => 'https://canovia.example.test/api/mcp',
                    'authorization_servers' => ['https://auth.example.test/tenant'],
                    'scopes_supported' => ['canovia.development.read'],
                ])
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
        }

        // The HTTP Host header cannot poison resource URIs in discovery.
        $this->withHeader('Host', 'attacker.example.test')
            ->getJson('/.well-known/oauth-protected-resource')
            ->assertOk()
            ->assertJsonPath('resource', 'https://canovia.example.test/api/mcp');
    }

    public function test_enabled_probe_rejects_anonymous_and_forged_bearer_requests_with_oauth_challenge(): void
    {
        $this->enableDiscovery();

        $challenge = 'Bearer resource_metadata="https://canovia.example.test/.well-known/oauth-protected-resource", scope="canovia.development.read"';

        $this->getJson('/api/mcp')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'authorization_required')
            ->assertHeader('WWW-Authenticate', $challenge)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->withHeader('Authorization', 'Bearer TOP_SECRET_FORGED')
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0', 'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'read_private_plan'],
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error', 'authorization_required')
            ->assertHeader('WWW-Authenticate', $challenge.', error="invalid_token"')
            ->assertDontSee('TOP_SECRET_FORGED');

        $this->deleteJson('/api/mcp')
            ->assertUnauthorized();
    }

    public function test_valid_canovia_session_and_prepared_chatgpt_preference_are_not_external_mcp_authorization(): void
    {
        $this->enableDiscovery();
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'PRIVATE_CANOVIA_PLAN',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(14),
            'is_public' => false,
        ]);
        Task::query()->create([
            'plan_id' => $plan->id, 'title' => 'PRIVATE_TASK_NAME',
            'status' => 'doing', 'priority' => 1, 'progress_percent' => 27,
        ]);

        DevelopmentAiSharingPreference::query()->create([
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'provider_key' => 'chatgpt',
            'scope' => 'tasks',
            'status' => 'prepared',
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($owner)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0', 'id' => 9,
                'method' => 'tools/call',
                'params' => ['name' => 'get_development_context', 'arguments' => ['plan_id' => $plan->id]],
            ])
            ->assertUnauthorized()
            ->assertDontSee('PRIVATE_CANOVIA_PLAN')
            ->assertDontSee('PRIVATE_TASK_NAME')
            ->assertDontSee('27');

        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('development_ai_sharing_preferences', 1);
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_bad_issuer_or_resource_never_advertises_an_authorization_provider(): void
    {
        $invalid = [
            ['https://canovia.example.test/api/mcp', ''],
            ['https://canovia.example.test/api/mcp', 'http://auth.example.test'],
            ['https://canovia.example.test/api/mcp?token=abc', 'https://auth.example.test'],
            ['http://canovia.example.test/api/mcp', 'https://auth.example.test'],
            ['https://canovia.example.test/api/mcp/other', 'https://auth.example.test'],
            ["https://canovia.example.test/api/mcp\r\nX-Attack: yes", 'https://auth.example.test'],
            ['https://canovia.example.test/api/mcp', 'https://user:pass@auth.example.test'],
            ['https://canovia.example.test/api/mcp', 'https://auth.example.test/#bad'],
            ['https://localhost/api/mcp', 'https://auth.example.test'],
        ];

        foreach ($invalid as [$resource, $issuer]) {
            $this->enableDiscovery();
            config([
                'canovia_mcp.resource_url' => $resource,
                'canovia_mcp.oauth_issuer' => $issuer,
            ]);

            $this->getJson('/.well-known/oauth-protected-resource')
                ->assertNotFound();
            $this->postJson('/api/mcp', [
                'jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1,
            ])->assertNotFound();
        }
    }

    public function test_even_configured_idp_introspection_never_authorizes_mcp_without_real_consent_and_tools(): void
    {
        $this->enableDiscovery();
        config([
            'canovia_mcp.token_introspection_enabled' => true,
            'canovia_mcp.introspection_url' => 'https://auth.example.test/token/introspect',
            'canovia_mcp.introspection_client_id' => 'server-verifier',
            'canovia_mcp.introspection_client_secret' => 'configured-server-only-secret',
            'canovia_mcp.allowed_client_id' => 'https://chatgpt.com/oauth/client.json',
        ]);
        Http::fake();

        $this->withHeader('Authorization', 'Bearer a-realistic-looking-but-unverified-token')
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0', 'id' => 2,
                'method' => 'tools/call',
                'params' => ['name' => 'get_development_context', 'arguments' => ['plan_id' => 1]],
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error', 'authorization_required');

        // The isolated validator is deliberately NOT wired to this handler
        // until session-independent consent and actor-Plan grants are ready.
        Http::assertNothingSent();
    }

    private function enableDiscovery(): void
    {
        config([
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.resource_url' => 'https://canovia.example.test/api/mcp',
            'canovia_mcp.oauth_issuer' => 'https://auth.example.test/tenant',
            'canovia_mcp.read_scope' => 'canovia.development.read',
        ]);
    }
}
