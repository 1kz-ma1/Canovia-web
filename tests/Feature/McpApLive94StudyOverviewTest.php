<?php

namespace Tests\Feature;

use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\Plan;
use App\Models\User;
use App\Services\McpDelegatedIdentityFingerprintService;
use App\Services\McpDelegatedRevocationService;
use App\Services\McpExplicitPlanConsentService;
use App\Services\McpReadOnlyStudyContextTool;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Real Laravel HTTP/DB calls on the old migration schema, but synthetic JWTs,
 * subjects and Plans only; no Aiven/Render/ChatGPT/Auth0 network access.
 */
final class McpApLive94StudyOverviewTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://synthetic-prod.jp.auth0.com/';
    private const RESOURCE = 'https://synthetic-canovia.example.test/api/mcp';
    private const JWKS = self::ISSUER.'.well-known/jwks.json';
    private const CHATGPT_CLIENT = 'tpc_synthetic_chatgpt';
    private const SUBJECT = 'github|123456789';

    private string $privateKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        Http::preventStrayRequests();
        config([
            'native_ai.driver' => 'disabled',
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.tools_enabled' => true,
            'canovia_mcp.ap_pilot_enabled' => true,
            'canovia_mcp.study_read_enabled' => true,
            'canovia_mcp.ap_pilot_owner_id' => '',
            'canovia_mcp.oauth_issuer' => self::ISSUER,
            'canovia_mcp.resource_url' => self::RESOURCE,
            'canovia_mcp.read_scope' => 'canovia.development.read',
            'canovia_mcp.allowed_client_id' => self::CHATGPT_CLIENT,
            'canovia_mcp.token_verification_driver' => 'auth0_jwt',
            'canovia_mcp.auth0_jwt_verification_enabled' => true,
            'canovia_mcp.token_introspection_enabled' => false,
            'canovia_mcp.account_link_enabled' => true,
            'canovia_mcp.account_link_github_only_enabled' => true,
            'canovia_mcp.account_link_client_id' => 'synthetic-confidential-link-client',
            'canovia_mcp.account_link_client_secret' => 'synthetic-secret-never-production',
            'canovia_mcp.account_link_metadata_url' => self::ISSUER.'.well-known/openid-configuration',
            'canovia_mcp.account_link_authorization_endpoint' => self::ISSUER.'authorize',
            'canovia_mcp.account_link_token_endpoint' => self::ISSUER.'oauth/token',
            'canovia_mcp.account_link_redirect_uri' =>
                'https://synthetic-canovia.example.test/account/mcp/link/callback',
            'canovia_mcp.plan_consent_enabled' => true,
            'canovia_mcp.delegated_policy_enabled' => true,
            'canovia_mcp.identity_fingerprint_key' => str_repeat('x', 48),
        ]);

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertNotFalse($key);
        $privateKey = '';
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        $this->privateKey = $privateKey;
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $b64 = fn ($raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        Http::fake([self::JWKS => Http::response(['keys' => [[
            'kid' => 'synthetic-ap-key', 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig',
            'n' => $b64($rsa['n']), 'e' => $b64($rsa['e']),
        ]]], 200, ['Content-Type' => 'application/json'])]);
    }

    private function token(string $sub = self::SUBJECT, string $client = self::CHATGPT_CLIENT): string
    {
        return JWT::encode([
            'iss' => self::ISSUER, 'sub' => $sub, 'aud' => self::RESOURCE,
            'azp' => $client, 'scope' => 'canovia.development.read',
            'iat' => now()->timestamp - 30, 'nbf' => now()->timestamp - 10,
            'exp' => now()->timestamp + 900,
        ], $this->privateKey, 'RS256', 'synthetic-ap-key');
    }

    private function rpc(string $method, array $params = [], ?string $token = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method,
            'params' => $params,
        ], array_filter([
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => $token === null ? null : 'Bearer '.$token,
        ]));
    }

    private function plan(User $owner, string $category = '資格学習', bool $collaborative = false): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Synthetic AP Study Only',
            'category' => $category,
            'priority' => 1, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(20),
            'is_public' => false, 'is_collaborative' => $collaborative,
        ]);
    }

    private function link(User $owner): McpLinkedSubject
    {
        return McpLinkedSubject::query()->create([
            'user_id' => $owner->id,
            'provider_key' => 'chatgpt',
            'identity_fingerprint' => app(McpDelegatedIdentityFingerprintService::class)
                ->subject(self::ISSUER, self::SUBJECT),
            'status' => 'linked', 'linked_at' => now(),
        ]);
    }

    private function grant(User $owner, Plan $plan, McpLinkedSubject $link): McpDelegatedGrant
    {
        return McpDelegatedGrant::query()->create([
            'subject_link_id' => $link->id, 'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'client_resource_fingerprint' => app(McpDelegatedIdentityFingerprintService::class)
                ->clientResource(self::CHATGPT_CLIENT, self::RESOURCE),
            'scope' => 'overview', 'status' => 'active',
            'consented_at' => now(), 'expires_at' => now()->addDay(),
        ]);
    }

    public function test_only_one_personal_study_overview_is_listed_and_read(): void
    {
        $owner = User::factory()->create();
        config(['canovia_mcp.ap_pilot_owner_id' => (string) $owner->id]);
        $study = $this->plan($owner);
        $foreign = $this->plan(User::factory()->create());
        $dev = $this->plan($owner, '個人開発');
        $link = $this->link($owner);
        $this->grant($owner, $study, $link);
        $this->grant($owner, $dev, $link);

        $this->rpc('tools/list', token: $this->token())
            ->assertOk()
            ->assertJsonCount(1, 'result.tools')
            ->assertJsonPath('result.tools.0.name', McpReadOnlyStudyContextTool::NAME);
        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyContextTool::NAME,
            'arguments' => ['plan_id' => $study->id, 'scope' => 'overview'],
        ], $this->token())
            ->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.plan.title', 'Synthetic AP Study Only')
            ->assertJsonPath('result.structuredContent.tasks', [])
            ->assertDontSee('owner_token');

        foreach ([
            ['plan_id' => $foreign->id],
            ['plan_id' => $dev->id],
            ['plan_id' => $study->id, 'scope' => 'tasks'],
        ] as $args) {
            $this->rpc('tools/call', [
                'name' => McpReadOnlyStudyContextTool::NAME, 'arguments' => $args,
            ], $this->token())->assertOk()->assertJsonPath('result.isError', true);
        }
        $this->rpc('tools/call', [
            'name' => 'canovia_get_development_plan_context',
            'arguments' => ['plan_id' => $dev->id],
        ], $this->token())->assertStatus(400)->assertDontSee('Synthetic AP Study Only');
        $this->assertDatabaseCount('mcp_delegated_access_events', 1);
    }

    public function test_wrong_client_wrong_subject_and_revocation_deny_the_same_plan(): void
    {
        $owner = User::factory()->create();
        config(['canovia_mcp.ap_pilot_owner_id' => (string) $owner->id]);
        $plan = $this->plan($owner);
        $link = $this->link($owner);
        $grant = $this->grant($owner, $plan, $link);

        $args = [
            'name' => McpReadOnlyStudyContextTool::NAME,
            'arguments' => ['plan_id' => $plan->id],
        ];
        $this->rpc('tools/call', $args, $this->token(client: 'tpc_foreign_client'))
            ->assertUnauthorized();
        $this->rpc('tools/call', $args, $this->token(sub: 'github|987654321'))
            ->assertOk()->assertJsonPath('result.isError', true);
        $this->rpc('tools/call', $args, $this->token(sub: 'auth0|ordinary'))
            ->assertOk()->assertJsonPath('result.isError', true);

        $this->rpc('tools/call', $args, $this->token())->assertOk()
            ->assertJsonPath('result.isError', false);
        $this->assertTrue(app(McpDelegatedRevocationService::class)->revokeGrant($owner, $grant->id));
        $this->rpc('tools/call', $args, $this->token())->assertOk()
            ->assertJsonPath('result.isError', true);
        $this->assertDatabaseCount('mcp_delegated_access_events', 2);
    }

    public function test_one_day_overview_only_consent_rejects_other_scopes_and_plans(): void
    {
        $owner = User::factory()->create();
        config(['canovia_mcp.ap_pilot_owner_id' => (string) $owner->id]);
        $study = $this->plan($owner);
        $dev = $this->plan($owner, '個人開発');
        $link = $this->link($owner);
        $consent = app(McpExplicitPlanConsentService::class);
        $fingerprint = $link->identity_fingerprint;

        $this->assertTrue($consent->isEnabled());
        $this->assertFalse($consent->grant($owner, $study->id, $fingerprint, 'tasks', 1));
        $this->assertFalse($consent->grant($owner, $study->id, $fingerprint, 'overview', 7));
        $this->assertFalse($consent->grant($owner, $dev->id, $fingerprint, 'overview', 1));
        $this->assertTrue($consent->grant($owner, $study->id, $fingerprint, 'overview', 1));
        $this->assertDatabaseCount('mcp_delegated_grants', 1);
        $this->assertSame('overview', McpDelegatedGrant::query()->sole()->scope);
        $this->assertNotNull(McpDelegatedGrant::query()->sole()->expires_at);

        config(['canovia_mcp.ap_pilot_owner_id' => '']);
        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyContextTool::NAME,
            'arguments' => ['plan_id' => $study->id],
        ], $this->token())->assertNotFound();
    }
}
