<?php

namespace Tests\Feature;

use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\Plan;
use App\Models\User;
use App\Services\McpReadOnlyStudyContextTool;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One synthetic OAuth/browser-session lifecycle on the LIVE94 schema.
 * This test never contacts Auth0, Render, Aiven or the actual ChatGPT service.
 */
final class McpApLive94OAuthLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://synthetic-prod.jp.auth0.com/';
    private const RESOURCE = 'https://synthetic-canovia.example.test/api/mcp';
    private const LINK_CLIENT = 'synthetic-firstparty-link';
    private const CHATGPT_CLIENT = 'tpc_synthetic-thirdparty';
    private const SUBJECT = 'github|654321789';

    private string $privateKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Several synthetic callbacks run in one in-process minute; production throttling stays intact.
        $this->withoutMiddleware(ThrottleRequests::class);
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
            'canovia_mcp.delegated_policy_enabled' => true,
            'canovia_mcp.plan_consent_enabled' => true,
            'canovia_mcp.account_link_enabled' => true,
            'canovia_mcp.account_link_github_only_enabled' => true,
            'canovia_mcp.account_link_client_id' => self::LINK_CLIENT,
            'canovia_mcp.account_link_client_secret' => 'synthetic-private-link-secret',
            'canovia_mcp.account_link_metadata_url' => self::ISSUER.'.well-known/openid-configuration',
            'canovia_mcp.account_link_authorization_endpoint' => self::ISSUER.'authorize',
            'canovia_mcp.account_link_token_endpoint' => self::ISSUER.'oauth/token',
            'canovia_mcp.account_link_redirect_uri' =>
                'https://synthetic-canovia.example.test/account/mcp/link/callback',
            'canovia_mcp.identity_fingerprint_key' => str_repeat('h', 48),
        ]);

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertNotFalse($key);
        $pem = '';
        $this->assertTrue(openssl_pkey_export($key, $pem));
        $this->privateKey = $pem;
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $b64 = fn ($raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        Http::fake([
            self::ISSUER.'.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'authorize',
                'token_endpoint' => self::ISSUER.'oauth/token',
                'authorization_response_iss_parameter_supported' => true,
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
                'scopes_supported' => ['openid'],
            ], 200, ['Content-Type' => 'application/json']),
            self::ISSUER.'.well-known/jwks.json' => Http::response(['keys' => [[
                'kid' => 'synthetic-lifecycle', 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig',
                'n' => $b64($rsa['n']), 'e' => $b64($rsa['e']),
            ]]], 200, ['Content-Type' => 'application/json']),
            self::ISSUER.'oauth/token' => Http::response([
                'token_type' => 'Bearer',
                'access_token' => $this->token(self::LINK_CLIENT),
                'expires_in' => 900,
            ], 200, ['Content-Type' => 'application/json']),
        ]);
    }

    private function token(string $client, string $sub = self::SUBJECT): string
    {
        return JWT::encode([
            'iss' => self::ISSUER, 'sub' => $sub, 'aud' => self::RESOURCE,
            'azp' => $client, 'scope' => 'canovia.development.read',
            'iat' => now()->timestamp - 30, 'nbf' => now()->timestamp - 10,
            'exp' => now()->timestamp + 900,
        ], $this->privateKey, 'RS256', 'synthetic-lifecycle');
    }

    /** @return array<string,string> */
    private function begin(string $url, array $post = []): array
    {
        $response = $this->post($url, $post)->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(self::ISSUER.'authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('github', $query['connection']);
        $this->assertSame('S256', $query['code_challenge_method']);
        return $query;
    }

    private function finishOAuthRedirect(string $state): void
    {
        $this->get(route('auth.account.mcp_link.callback').'?'.http_build_query([
            'state' => $state,
            'code' => 'synthetic-auth-code-abcdef123456',
            'iss' => self::ISSUER,
        ]))->assertRedirect(route('auth.account'));
    }

    private function read(int $id, string $token): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => [
                'name' => McpReadOnlyStudyContextTool::NAME,
                'arguments' => ['plan_id' => $id],
            ],
        ], [
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => 'Bearer '.$token,
        ]);
    }

    public function test_fresh_github_link_consent_read_and_revoke_lifecycle(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        config(['canovia_mcp.ap_pilot_owner_id' => (string) $owner->id]);
        $plan = Plan::query()->create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Synthetic AP Session',
            'category' => '資格学習',
            'priority' => 1, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(14),
            'is_public' => false, 'is_collaborative' => false,
        ]);

        $this->actingAs($owner);
        $linkQuery = $this->begin(route('auth.account.mcp_link.start'));
        $this->finishOAuthRedirect($linkQuery['state']);
        $this->assertDatabaseCount('mcp_linked_subjects', 0);
        $this->post(route('auth.account.mcp_link.confirm'))->assertRedirect(route('auth.account'));
        $this->assertDatabaseCount('mcp_linked_subjects', 1);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);

        $grantQuery = $this->begin(
            route('auth.account.mcp_plan_consent.start', ['plan' => $plan->id]),
            ['scope' => 'overview', 'duration_days' => 1],
        );
        $this->finishOAuthRedirect($grantQuery['state']);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
        $this->post(route('auth.account.mcp_plan_consent.confirm'))
            ->assertRedirect(route('auth.account'))
            ->assertSessionHas('status', 'AP学習Planの概要を1日間共有する許可を保存しました。ChatGPT側の接続とMCP読み取り機能が有効な場合に取得できます。');
        $grant = McpDelegatedGrant::query()->sole();
        $this->assertSame($owner->id, $grant->user_id);
        $this->assertSame('overview', $grant->scope);
        $this->assertTrue($grant->expires_at->between(
            now()->addDay()->subMinute(), now()->addDay()->addMinute()
        ));

        // The owner receives the exact Plan ID without asking the model to guess.
        $this->get(route('auth.account'))
            ->assertOk()->assertSee('ChatGPTで指定するPlan ID')
            ->assertSee('data-mcp-ap-plan-id', false)
            ->assertSee((string) $plan->id);

        $chatgptJwt = $this->token(self::CHATGPT_CLIENT);
        $this->read($plan->id, $chatgptJwt)->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.plan.title', 'Synthetic AP Session')
            ->assertJsonPath('result.structuredContent.tasks', []);
        $this->delete(route('auth.account.mcp_grant.revoke', ['grant' => $grant->id]))
            ->assertRedirect(route('auth.account'));
        $this->read($plan->id, $chatgptJwt)->assertOk()
            ->assertJsonPath('result.isError', true);
        $this->assertDatabaseCount('mcp_linked_subjects', 1);
        $this->assertDatabaseCount('mcp_delegated_grants', 1);
    }

    public function test_nonowner_cannot_start_private_ap_link(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);
        config(['canovia_mcp.ap_pilot_owner_id' => (string) $owner->id]);
        $this->actingAs($other);
        $this->post(route('auth.account.mcp_link.start'))->assertForbidden();
        $this->assertDatabaseCount('mcp_linked_subjects', 0);
        Http::assertNothingSent();
    }


    public function test_wrong_issuer_callback_cannot_be_replayed_to_link_identity(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        config(['canovia_mcp.ap_pilot_owner_id' => (string) $owner->id]);
        $this->actingAs($owner);
        $query = $this->begin(route('auth.account.mcp_link.start'));

        $this->get(route('auth.account.mcp_link.callback').'?'.http_build_query([
            'state' => $query['state'],
            'code' => 'synthetic-auth-code-abcdef123456',
            'iss' => 'https://different.example.test/',
        ]))->assertRedirect(route('auth.account'));
        $this->finishOAuthRedirect($query['state']);
        $this->post(route('auth.account.mcp_link.confirm'))
            ->assertRedirect(route('auth.account'));

        $this->assertDatabaseCount('mcp_linked_subjects', 0);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
        Http::assertSentCount(1);
    }


    public function test_verified_consent_does_not_survive_plan_visibility_change(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        config(['canovia_mcp.ap_pilot_owner_id' => (string) $owner->id]);
        $plan = Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Private AP plan',
            'category' => '資格学習',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(14),
            'is_public' => false,
            'is_collaborative' => false,
        ]);

        $this->actingAs($owner);
        $link = $this->begin(route('auth.account.mcp_link.start'));
        $this->finishOAuthRedirect($link['state']);
        $this->post(route('auth.account.mcp_link.confirm'))
            ->assertRedirect(route('auth.account'));

        $consent = $this->begin(
            route('auth.account.mcp_plan_consent.start', ['plan' => $plan->id]),
            ['scope' => 'overview', 'duration_days' => 1]
        );
        $this->finishOAuthRedirect($consent['state']);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);

        $plan->forceFill(['is_collaborative' => true])->save();
        $this->post(route('auth.account.mcp_plan_consent.confirm'))
            ->assertRedirect(route('auth.account'));
        $this->assertDatabaseCount('mcp_delegated_grants', 0);

        $plan->forceFill(['is_collaborative' => false])->save();
        $this->post(route('auth.account.mcp_plan_consent.confirm'))
            ->assertRedirect(route('auth.account'));
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
    }

}
