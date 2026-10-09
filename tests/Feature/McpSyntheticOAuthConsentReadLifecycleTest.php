<?php

namespace Tests\Feature;

use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\McpReadOnlyContextTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Full Canovia flow with stubbed HTTPS IdP responses, NOT real ChatGPT E2E.
 * Independent Keycloak CI validates the real issuer/sub/client/aud contract.
 */
final class McpSyntheticOAuthConsentReadLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://idp.example.test/realms/synthetic';
    private const RESOURCE = 'https://canovia.example.test/api/mcp';
    private const METADATA = 'https://idp.example.test/realms/synthetic/.well-known/openid-configuration';
    private const AUTHORIZE = 'https://idp.example.test/realms/synthetic/protocol/openid-connect/auth';
    private const TOKEN = 'https://idp.example.test/realms/synthetic/protocol/openid-connect/token';
    private const INTROSPECT = 'https://idp.example.test/realms/synthetic/protocol/openid-connect/token/introspect';
    private const LINK_CLIENT = 'disposable-account-link';
    private const AGENT_CLIENT = 'disposable-second-client';
    private const LINK_BEARER = 'synthetic-link-access-token-not-real';
    private const AGENT_BEARER = 'synthetic-agent-access-token-not-real';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::preventStrayRequests();
        config([
            'native_ai.driver' => 'disabled',
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.resource_url' => self::RESOURCE,
            'canovia_mcp.oauth_issuer' => self::ISSUER,
            'canovia_mcp.token_introspection_enabled' => true,
            'canovia_mcp.introspection_url' => self::INTROSPECT,
            'canovia_mcp.introspection_client_id' => self::RESOURCE,
            'canovia_mcp.introspection_client_secret' => 'synthetic-introspection-secret',
            'canovia_mcp.allowed_client_id' => self::AGENT_CLIENT,
            'canovia_mcp.identity_fingerprint_key' => str_repeat('q', 48),
            'canovia_mcp.account_link_enabled' => true,
            'canovia_mcp.account_link_client_id' => self::LINK_CLIENT,
            'canovia_mcp.account_link_client_secret' => 'synthetic-link-client-secret',
            'canovia_mcp.account_link_metadata_url' => self::METADATA,
            'canovia_mcp.account_link_authorization_endpoint' => self::AUTHORIZE,
            'canovia_mcp.account_link_token_endpoint' => self::TOKEN,
            'canovia_mcp.account_link_redirect_uri' => 'https://canovia.example.test/account/mcp/link/callback',
            'canovia_mcp.plan_consent_enabled' => true,
            'canovia_mcp.delegated_policy_enabled' => true,
            'canovia_mcp.tools_enabled' => true,
        ]);
        Http::fake(function (ProviderRequest $r) {
            if ($r->url() === self::METADATA) {
                return Http::response([
                    'issuer' => self::ISSUER,
                    'authorization_endpoint' => self::AUTHORIZE,
                    'token_endpoint' => self::TOKEN,
                    'authorization_response_iss_parameter_supported' => true,
                    'code_challenge_methods_supported' => ['S256'],
                    'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
                    'scopes_supported' => ['canovia.development.read'],
                ], 200, ['Content-Type' => 'application/json']);
            }
            if ($r->url() === self::TOKEN) {
                return Http::response([
                    'token_type' => 'Bearer',
                    'access_token' => self::LINK_BEARER,
                    'expires_in' => 1200,
                ], 200, ['Content-Type' => 'application/json']);
            }
            if ($r->url() === self::INTROSPECT) {
                $client = match ($r['token'] ?? '') {
                    self::LINK_BEARER => self::LINK_CLIENT,
                    self::AGENT_BEARER => self::AGENT_CLIENT,
                    default => null,
                };
                if ($client === null) {
                    return Http::response(['active' => false], 200, ['Content-Type' => 'application/json']);
                }
                return Http::response([
                    'active' => true,
                    'iss' => self::ISSUER,
                    'sub' => 'immutable-disposable-person',
                    'client_id' => $client,
                    'aud' => self::RESOURCE,
                    'token_type' => 'Bearer',
                    'exp' => now()->timestamp + 1200,
                    'iat' => now()->timestamp - 60,
                    'nbf' => now()->timestamp - 5,
                    'scope' => 'canovia.development.read',
                ], 200, ['Content-Type' => 'application/json']);
            }
            return Http::response('', 404);
        });
    }

    public function test_link_then_explicit_consent_then_scoped_read_and_revocation(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, 'SYNTHETIC_APPROVED_PLAN');
        $unapproved = $this->plan($owner, 'SYNTHETIC_UNAPPROVED_PLAN');
        $foreign = $this->plan($other, 'FOREIGN_PRIVATE_PLAN');
        Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'SYNTHETIC_TASK_VISIBLE_ON_CONSENT',
            'description' => 'SECRET_NEVER_EXPORTED',
            'status' => 'doing',
            'progress_percent' => 35,
            'priority' => 1,
            'sort_order' => 1,
        ]);

        // Even an active token has no power without a linked subject + grant.
        $this->mcp($plan->id)->assertOk()->assertJsonPath('result.isError', true);
        $this->callback($this->begin($owner, route('auth.account.mcp_link.start')))
            ->assertRedirect(route('auth.account'));
        $this->assertDatabaseCount('mcp_linked_subjects', 0);
        $this->actingAs($owner)->post(route('auth.account.mcp_link.confirm'))
            ->assertRedirect(route('auth.account'));
        $link = McpLinkedSubject::query()->sole();
        $this->assertSame($owner->id, $link->user_id);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
        $this->mcp($plan->id)->assertOk()->assertJsonPath('result.isError', true);

        $start = route('auth.account.mcp_plan_consent.start', ['plan' => $plan->id]);
        $this->callback($this->begin($owner, $start, ['scope' => 'tasks', 'duration_days' => 1]))
            ->assertRedirect(route('auth.account'));
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
        $this->mcp($plan->id, 'tasks')->assertOk()->assertJsonPath('result.isError', true);
        $this->actingAs($owner)->post(route('auth.account.mcp_plan_consent.confirm'))
            ->assertRedirect(route('auth.account'));

        $grant = McpDelegatedGrant::query()->sole();
        $this->assertSame($link->id, $grant->subject_link_id);
        $this->assertSame($plan->id, $grant->plan_id);
        $this->assertSame('active', $grant->status);
        $this->assertSame('tasks', $grant->scope);

        $this->mcp($plan->id, 'tasks')->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.plan.title', 'SYNTHETIC_APPROVED_PLAN')
            ->assertJsonPath('result.structuredContent.tasks.0.title', 'SYNTHETIC_TASK_VISIBLE_ON_CONSENT')
            ->assertDontSee('SECRET_NEVER_EXPORTED')
            ->assertDontSee('owner_token');
        foreach ([$unapproved, $foreign] as $notShared) {
            $this->mcp($notShared->id)->assertOk()
                ->assertJsonPath('result.isError', true)
                ->assertDontSee($notShared->title);
        }
        $this->assertDatabaseHas('mcp_delegated_access_events', [
            'actor_user_id' => $owner->id,
            'grant_id' => $grant->id,
            'event_kind' => 'context_read',
            'scope' => 'tasks',
        ]);

        $this->actingAs($owner)->delete(
            route('auth.account.mcp_grant.revoke', ['grant' => $grant->id])
        )->assertRedirect(route('auth.account'));
        $this->assertSame('revoked', $grant->fresh()->status);
        $this->mcp($plan->id, 'tasks')->assertOk()->assertJsonPath('result.isError', true);

        // A consumed user confirmation is incapable of reviving consent.
        $this->actingAs($owner)->post(route('auth.account.mcp_plan_consent.confirm'))
            ->assertRedirect(route('auth.account'));
        $this->assertSame('revoked', $grant->fresh()->status);
        $this->actingAs($other)->delete(
            route('auth.account.mcp_subject.revoke', ['subject' => $link->id])
        )->assertNotFound();
        $this->actingAs($owner)->delete(
            route('auth.account.mcp_subject.revoke', ['subject' => $link->id])
        )->assertRedirect(route('auth.account'));
        $this->assertSame('revoked', $link->fresh()->status);
        $this->mcp($plan->id)->assertOk()->assertJsonPath('result.isError', true);
        $this->assertDatabaseCount('plans', 3);
        $this->assertDatabaseCount('tasks', 1);
        Http::assertSent(static fn (ProviderRequest $r) =>
            $r->url() === self::TOKEN && $r['grant_type'] === 'authorization_code'
            && $r['resource'] === self::RESOURCE
        );
    }

    public function test_consent_without_prior_verified_identity_never_grants(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, 'SYNTHETIC_NOT_LINKED');
        $this->actingAs($owner)->post(
            route('auth.account.mcp_plan_consent.start', ['plan' => $plan->id]),
            ['scope' => 'tasks', 'duration_days' => 7]
        )->assertRedirect(route('auth.account'));
        $this->actingAs($owner)->post(route('auth.account.mcp_plan_consent.confirm'))
            ->assertRedirect(route('auth.account'));
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
        $this->mcp($plan->id)->assertOk()->assertJsonPath('result.isError', true);
    }

    private function begin(User $owner, string $route, array $params = []): string
    {
        $response = $this->actingAs($owner)->post($route, $params)->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(self::AUTHORIZE.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method'] ?? null);
        $this->assertSame(self::RESOURCE, $query['resource'] ?? null);
        $this->assertSame(self::LINK_CLIENT, $query['client_id'] ?? null);
        return (string) $query['state'];
    }

    private function callback(string $state): \Illuminate\Testing\TestResponse
    {
        return $this->get(route('auth.account.mcp_link.callback').'?'
            .http_build_query([
                'state' => $state,
                'iss' => self::ISSUER,
                'code' => 'synthetic-oauth-code-valid-length-0123',
            ]));
    }

    private function mcp(int $id, string $scope = 'overview'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 17, 'method' => 'tools/call',
            'params' => [
                'name' => McpReadOnlyContextTool::NAME,
                'arguments' => ['plan_id' => $id, 'scope' => $scope],
            ],
        ], [
            'Authorization' => 'Bearer '.self::AGENT_BEARER,
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
        ]);
    }

    private function plan(User $owner, string $title): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(14),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }
}
