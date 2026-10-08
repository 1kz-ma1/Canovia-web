<?php

namespace Tests\Feature;

use App\Models\McpLinkedSubject;
use App\Models\User;
use App\Services\McpDelegatedRevocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class McpOAuthAccountLinkTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://auth.example.test/tenant';
    private const RESOURCE = 'https://canovia.example.test/api/mcp';
    private const METADATA = 'https://auth.example.test/.well-known/oauth-authorization-server';
    private const AUTHORIZE = 'https://auth.example.test/oauth/authorize';
    private const TOKEN_ENDPOINT = 'https://auth.example.test/oauth/token';
    private const INTROSPECT = 'https://auth.example.test/oauth/introspect';
    private const CLIENT = 'canovia-account-link-client';
    private const ACCESS_TOKEN = 'opaque-confidential-oauth-token-registered';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'native_ai.driver' => 'disabled',
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.resource_url' => self::RESOURCE,
            'canovia_mcp.oauth_issuer' => self::ISSUER,
            'canovia_mcp.read_scope' => 'canovia.development.read',
            'canovia_mcp.token_introspection_enabled' => true,
            'canovia_mcp.introspection_url' => self::INTROSPECT,
            'canovia_mcp.introspection_client_id' => 'canovia-api-verifier',
            'canovia_mcp.introspection_client_secret' => 'server-introspection-only-secret',
            'canovia_mcp.allowed_client_id' => 'https://chatgpt.com/oauth/client.json',
            'canovia_mcp.delegated_policy_enabled' => false,
            'canovia_mcp.identity_fingerprint_key' => str_repeat('v', 48),
            'canovia_mcp.account_link_enabled' => true,
            'canovia_mcp.account_link_client_id' => self::CLIENT,
            'canovia_mcp.account_link_client_secret' => 'test-account-link-client-secret',
            'canovia_mcp.account_link_metadata_url' => self::METADATA,
            'canovia_mcp.account_link_authorization_endpoint' => self::AUTHORIZE,
            'canovia_mcp.account_link_token_endpoint' => self::TOKEN_ENDPOINT,
            'canovia_mcp.account_link_redirect_uri' => 'https://canovia.example.test/account/mcp/link/callback',
        ]);
        Http::preventStrayRequests();
    }

    public function test_off_by_default_or_misconfigured_link_client_cannot_redirect_or_query_idp(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $variants = [
            ['canovia_mcp.account_link_enabled' => false],
            ['canovia_mcp.discovery_enabled' => false],
            ['canovia_mcp.account_link_client_id' => 'https://chatgpt.com/oauth/client.json'],
            ['canovia_mcp.account_link_client_secret' => 'short'],
            ['canovia_mcp.account_link_token_endpoint' => 'http://auth.example.test/oauth/token'],
            ['canovia_mcp.account_link_authorization_endpoint' => 'https://evil.example.test/oauth/authorize'],
            ['canovia_mcp.account_link_metadata_url' => 'https://auth.example.test/m?x=evil'],
            ['canovia_mcp.account_link_redirect_uri' => 'https://evil.example.test/account/mcp/link/callback'],
            ['canovia_mcp.account_link_redirect_uri' => 'https://canovia.example.test/account/mcp/link/callback?code=foo'],
            ['canovia_mcp.oauth_issuer' => 'http://auth.example.test/tenant'],
            ['canovia_mcp.identity_fingerprint_key' => 'weak'],
        ];

        foreach ($variants as $variant) {
            $previous = [];
            foreach ($variant as $key => $_) {
                $previous[$key] = config($key);
            }
            config($variant);
            $this->actingAs($owner)
                ->post(route('auth.account.mcp_link.start'))
                ->assertRedirect(route('auth.account'));
            config($previous);
        }

        $this->assertDatabaseCount('mcp_linked_subjects', 0);
        Http::assertNothingSent();
    }

    public function test_actor_must_explicitly_start_pkce_then_verify_subject_then_confirm_without_plan_grant(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $this->stubProvider();

        $this->actingAs($owner)
            ->get(route('auth.account'))
            ->assertOk()
            ->assertSee('認証プロバイダーで本人確認を開始')
            ->assertDontSee('本人IDの紐付けを確定');

        $url = $this->start($owner);
        $query = $this->query($url);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame(self::CLIENT, $query['client_id']);
        $this->assertSame(self::RESOURCE, $query['resource']);
        $this->assertSame('canovia.development.read', $query['scope']);
        $this->assertSame('https://canovia.example.test/account/mcp/link/callback', $query['redirect_uri']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['code_challenge']);
        $this->assertDatabaseCount('mcp_linked_subjects', 0);

        $pending = session('mcp.account_link.pending');
        $this->assertIsArray($pending);
        $this->assertSame($owner->id, $pending['actor_id']);
        $this->assertSame(hash('sha256', $query['state']), $pending['state_hash']);
        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '=');
        $this->assertSame($expectedChallenge, $query['code_challenge']);

        $this->oauthCallback($query['state'])
            ->assertRedirect(route('auth.account'));

        $this->assertNull(session('mcp.account_link.pending'));
        $this->assertDatabaseCount('mcp_linked_subjects', 0);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);

        $this->actingAs($owner)
            ->get(route('auth.account'))
            ->assertOk()
            ->assertSee('本人IDの紐付けを確定')
            ->assertSee('今回の紐付けを中止')
            ->assertDontSee('immutable-test-issuer-subject')
            ->assertDontSee(self::ACCESS_TOKEN)
            ->assertDontSee('test-account-link-client-secret');

        $this->actingAs($owner)
            ->post(route('auth.account.mcp_link.confirm'))
            ->assertRedirect(route('auth.account'));

        $this->assertDatabaseCount('mcp_linked_subjects', 1);
        $this->assertDatabaseHas('mcp_linked_subjects', [
            'user_id' => $owner->id,
            'provider_key' => 'chatgpt',
            'status' => 'linked',
        ]);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
        $this->assertDatabaseHas('mcp_delegated_access_events', [
            'actor_user_id' => $owner->id,
            'event_kind' => 'subject_linked',
            'grant_id' => null,
            'scope' => null,
        ]);

        $this->actingAs($owner)
            ->post(route('auth.account.mcp_link.confirm'))
            ->assertRedirect(route('auth.account'));

        $this->assertDatabaseCount('mcp_delegated_access_events', 1);
        Http::assertSentCount(3);
        Http::assertSent(static fn (ClientRequest $r) =>
            $r->url() === self::TOKEN_ENDPOINT
            && $r->method() === 'POST'
            && $r['grant_type'] === 'authorization_code'
            && $r['resource'] === self::RESOURCE
            && $r['redirect_uri'] === 'https://canovia.example.test/account/mcp/link/callback'
            && $r['code_verifier'] === $pending['verifier']
            && $r->hasHeader('Authorization', 'Basic '.base64_encode(self::CLIENT.':test-account-link-client-secret'))
        );
        Http::assertSent(static fn (ClientRequest $r) =>
            $r->url() === self::INTROSPECT
            && $r['token'] === self::ACCESS_TOKEN
        );
    }

    public function test_bad_issuer_state_expiry_actor_switch_or_provider_error_never_calls_token_exchange(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);
        $this->stubProvider();

        foreach ([
            ['state' => 'wrong'],
            ['iss' => 'https://other-issuer.example.test'],
            ['iss' => null],
            ['error' => 'access_denied'],
        ] as $change) {
            $query = $this->query($this->start($owner));
            $this->oauthCallback(
                $change['state'] ?? $query['state'],
                $change['iss'] ?? (array_key_exists('iss', $change) ? null : self::ISSUER),
                $change['error'] ?? null,
            )->assertRedirect(route('auth.account'));

            $this->assertNull(session('mcp.account_link.pending'));
            $this->assertNull(session('mcp.account_link.verified'));
        }

        $query = $this->query($this->start($owner));
        $this->travel(6)->minutes();
        $this->oauthCallback($query['state'])->assertRedirect(route('auth.account'));
        $this->travelBack();

        $query = $this->query($this->start($owner));
        $this->actingAs($other);
        $this->oauthCallback($query['state'])->assertRedirect(route('auth.account'));

        $this->assertDatabaseCount('mcp_linked_subjects', 0);
        $this->assertDatabaseCount('mcp_delegated_access_events', 0);
        // One discovery query per attempt; none should redeem a code.
        Http::assertNotSent(static fn (ClientRequest $r) => $r->url() === self::TOKEN_ENDPOINT);
        Http::assertNotSent(static fn (ClientRequest $r) => $r->url() === self::INTROSPECT);
    }

    public function test_incompatible_metadata_provider_and_forged_token_claims_fail_closed(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);

        $invalid = $this->metadata();
        $invalid['code_challenge_methods_supported'] = ['plain'];
        $this->stubProvider(metadata: $invalid);
        $this->actingAs($owner)
            ->post(route('auth.account.mcp_link.start'))
            ->assertRedirect(route('auth.account'));
        $this->assertNull(session('mcp.account_link.pending'));

        $this->stubProvider(claims: array_replace($this->claims(), [
            'client_id' => 'https://chatgpt.com/oauth/client.json',
        ]));
        $state = $this->query($this->start($owner))['state'];
        $this->oauthCallback($state)->assertRedirect(route('auth.account'));
        $this->assertNull(session('mcp.account_link.verified'));

        $this->stubProvider(claims: array_replace($this->claims(), [
            'aud' => 'https://other.example.test/api/mcp',
        ]));
        $state = $this->query($this->start($owner))['state'];
        $this->oauthCallback($state)->assertRedirect(route('auth.account'));
        $this->assertNull(session('mcp.account_link.verified'));

        $this->assertDatabaseCount('mcp_linked_subjects', 0);
    }

    public function test_cancel_and_one_time_confirmation_expiry_never_create_subject(): void
    {
        $owner = User::factory()->create();
        $this->stubProvider();

        $this->oauthCallback($this->query($this->start($owner))['state'])
            ->assertRedirect(route('auth.account'));
        $this->actingAs($owner)
            ->post(route('auth.account.mcp_link.cancel'))
            ->assertRedirect(route('auth.account'));
        $this->actingAs($owner)
            ->post(route('auth.account.mcp_link.confirm'))
            ->assertRedirect(route('auth.account'));

        $this->oauthCallback($this->query($this->start($owner))['state'])
            ->assertRedirect(route('auth.account'));
        $this->travel(6)->minutes();
        $this->actingAs($owner)
            ->post(route('auth.account.mcp_link.confirm'))
            ->assertRedirect(route('auth.account'));
        $this->travelBack();
        $this->assertDatabaseCount('mcp_linked_subjects', 0);
    }

    public function test_cross_user_duplicate_identity_denied_and_relink_does_not_revive_previous_grants(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->stubProvider();

        $this->oauthCallback($this->query($this->start($first))['state']);
        $this->actingAs($first)->post(route('auth.account.mcp_link.confirm'))->assertRedirect();
        $link = McpLinkedSubject::query()->firstOrFail();

        $this->oauthCallback($this->query($this->start($second))['state']);
        $this->actingAs($second)->post(route('auth.account.mcp_link.confirm'))->assertRedirect();
        $this->assertSame($first->id, $link->fresh()->user_id);
        $this->assertDatabaseCount('mcp_linked_subjects', 1);

        app(McpDelegatedRevocationService::class)->revokeSubject($first, $link->id);
        $this->assertSame('revoked', $link->fresh()->status);

        $this->oauthCallback($this->query($this->start($first))['state']);
        $this->actingAs($first)->post(route('auth.account.mcp_link.confirm'))->assertRedirect();
        $this->assertSame('linked', $link->fresh()->status);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
        $this->assertDatabaseHas('mcp_delegated_access_events', [
            'actor_user_id' => $first->id,
            'event_kind' => 'subject_relinked',
        ]);
    }

    private function start(User $user): string
    {
        $response = $this->actingAs($user)
            ->post(route('auth.account.mcp_link.start'))
            ->assertRedirect();
        return (string) $response->headers->get('Location');
    }

    private function oauthCallback(
        string $state,
        ?string $issuer = self::ISSUER,
        ?string $error = null,
    ): \Illuminate\Testing\TestResponse {
        $query = ['state' => $state, 'code' => 'valid-provider-auth-code-123456'];
        if ($issuer !== null) {
            $query['iss'] = $issuer;
        }
        if ($error !== null) {
            $query['error'] = $error;
        }
        return $this->get(route('auth.account.mcp_link.callback').'?'.http_build_query($query));
    }

    /** @return array<string, string> */
    private function query(string $url): array
    {
        $this->assertStringStartsWith(self::AUTHORIZE.'?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        return $query;
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::AUTHORIZE,
            'token_endpoint' => self::TOKEN_ENDPOINT,
            'authorization_response_iss_parameter_supported' => true,
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
            'scopes_supported' => ['canovia.development.read'],
        ];
    }

    /** @return array<string, mixed> */
    private function claims(): array
    {
        return [
            'active' => true,
            'iss' => self::ISSUER,
            'sub' => 'immutable-test-issuer-subject',
            'client_id' => self::CLIENT,
            'aud' => self::RESOURCE,
            'token_type' => 'Bearer',
            'exp' => now()->timestamp + 1200,
            'iat' => now()->timestamp - 60,
            'nbf' => now()->timestamp - 5,
            'scope' => 'canovia.development.read',
        ];
    }

    private function stubProvider(?array $metadata = null, ?array $claims = null): void
    {
        Http::fake([
            self::METADATA => Http::response(
                $metadata ?? $this->metadata(), 200, ['Content-Type' => 'application/json'],
            ),
            self::TOKEN_ENDPOINT => Http::response([
                'token_type' => 'Bearer',
                'access_token' => self::ACCESS_TOKEN,
                'expires_in' => 1200,
            ], 200, ['Content-Type' => 'application/json']),
            self::INTROSPECT => Http::response(
                $claims ?? $this->claims(), 200, ['Content-Type' => 'application/json'],
            ),
        ]);
    }
}
