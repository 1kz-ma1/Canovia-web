<?php

namespace Tests\Feature;

use App\Models\DevelopmentAiSharingPreference;
use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\McpDelegatedIdentityFingerprintService;
use App\Services\McpReadOnlyContextTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class McpReadOnlyResourceTest extends TestCase
{
    use RefreshDatabase;

    private const RESOURCE = 'https://canovia.example.test/api/mcp';
    private const ISSUER = 'https://auth.example.test/tenant';
    private const CHATGPT_CLIENT = 'https://chatgpt.com/oauth/client.json';
    private const TOKEN = 'opaque-mcp-test-access-token-no-secrets';
    private const INTROSPECT = 'https://auth.example.test/oauth/introspect';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'native_ai.driver' => 'disabled',
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.tools_enabled' => true,
            'canovia_mcp.allowed_origins' => '',
            'canovia_mcp.resource_url' => self::RESOURCE,
            'canovia_mcp.oauth_issuer' => self::ISSUER,
            'canovia_mcp.read_scope' => 'canovia.development.read',
            'canovia_mcp.token_introspection_enabled' => true,
            'canovia_mcp.introspection_url' => self::INTROSPECT,
            'canovia_mcp.introspection_client_id' => 'resource-introspector',
            'canovia_mcp.introspection_client_secret' => 'resource-introspector-secret',
            'canovia_mcp.allowed_client_id' => self::CHATGPT_CLIENT,
            'canovia_mcp.delegated_policy_enabled' => true,
            'canovia_mcp.identity_fingerprint_key' => str_repeat('k', 48),
            'canovia_mcp.account_link_enabled' => true,
            'canovia_mcp.account_link_client_id' => 'canovia-account-link-client',
            'canovia_mcp.account_link_client_secret' => 'canovia-account-link-secret',
            'canovia_mcp.account_link_metadata_url' => 'https://auth.example.test/.well-known/oauth-authorization-server',
            'canovia_mcp.account_link_authorization_endpoint' => 'https://auth.example.test/oauth/authorize',
            'canovia_mcp.account_link_token_endpoint' => 'https://auth.example.test/oauth/token',
            'canovia_mcp.account_link_redirect_uri' => 'https://canovia.example.test/account/mcp/link/callback',
            'canovia_mcp.plan_consent_enabled' => true,
        ]);
        Http::preventStrayRequests();
    }

    public function test_off_switch_and_incomplete_provider_keep_the_previous_deny_all_gate(): void
    {
        $this->idp();
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        $this->linkAndGrant($user, $plan);

        config(['canovia_mcp.tools_enabled' => false]);
        $this->rpc('tools/list')->assertUnauthorized()
            ->assertJsonPath('error', 'authorization_required');
        $this->rpc('tools/call', [
            'name' => McpReadOnlyContextTool::NAME,
            'arguments' => ['plan_id' => $plan->id],
        ])->assertUnauthorized();
        Http::assertNothingSent();

        config(['canovia_mcp.tools_enabled' => true, 'canovia_mcp.plan_consent_enabled' => false]);
        $this->rpc('tools/list')->assertNotFound();
        config(['canovia_mcp.plan_consent_enabled' => true, 'canovia_mcp.oauth_issuer' => '']);
        $this->rpc('tools/list')->assertNotFound();

        config(['canovia_mcp.oauth_issuer' => self::ISSUER, 'canovia_mcp.discovery_enabled' => false]);
        $this->rpc('tools/list')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_every_request_requires_real_bearer_even_with_canovia_session_and_prepared_record(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);
        DevelopmentAiSharingPreference::query()->create([
            'user_id' => $owner->id, 'plan_id' => $plan->id,
            'provider_key' => 'chatgpt', 'scope' => 'tasks',
            'status' => 'prepared', 'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($owner);
        $this->rpc('tools/list', [], 1, '')->assertUnauthorized()
            ->assertHeader('WWW-Authenticate',
                'Bearer resource_metadata="https://canovia.example.test/.well-known/oauth-protected-resource", scope="canovia.development.read"');
        $this->rpc('tools/list', [], 1, 'forged-bearer-token')->assertUnauthorized()
            ->assertHeader('WWW-Authenticate',
                'Bearer resource_metadata="https://canovia.example.test/.well-known/oauth-protected-resource", scope="canovia.development.read", error="invalid_token"');
        $this->assertDatabaseCount('mcp_delegated_access_events', 0);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
        $this->assertDatabaseCount('mcp_linked_subjects', 0);
    }

    public function test_valid_chatgpt_idp_token_can_handshake_and_discover_only_one_read_only_tool(): void
    {
        $this->idp();
        $this->rpc('initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => []])
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-11-25')
            ->assertJsonPath('result.capabilities.tools.listChanged', false)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->rpc('ping')->assertOk()
            ->assertJsonPath('jsonrpc', '2.0');
        $this->rpc('tools/list')
            ->assertOk()
            ->assertJsonCount(1, 'result.tools')
            ->assertJsonPath('result.tools.0.name', McpReadOnlyContextTool::NAME)
            ->assertJsonPath('result.tools.0.annotations.readOnlyHint', true)
            ->assertJsonPath('result.tools.0.inputSchema.additionalProperties', false);

        // Stateless notifications are valid but never return a private body.
        $notification = $this->rpc('notifications/initialized', [], null);
        $notification->assertStatus(202)->assertDontSee('Sensitive');

        $this->assertDatabaseCount('mcp_delegated_access_events', 0);
        Http::assertSentCount(4);
        Http::assertSent(static fn (ClientRequest $req) =>
            $req->url() === self::INTROSPECT
            && $req->method() === 'POST'
            && $req['token'] === self::TOKEN
        );
    }

    public function test_overview_and_tasks_scope_return_only_approved_columns_and_audit_metadata(): void
    {
        $this->idp();
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $link = $this->linkAndGrant($owner, $plan, 'tasks');

        Task::query()->create([
            'plan_id' => $plan->id, 'title' => 'Recorded task one',
            'description' => 'SENSITIVE_DESCRIPTION_NOT_EXPORTED',
            'status' => 'doing', 'progress_percent' => 27,
            'priority' => 1, 'sort_order' => 1,
        ]);
        Task::query()->create([
            'plan_id' => $plan->id, 'title' => 'Recorded task two',
            'description' => 'SENSITIVE_SECOND_DESCRIPTION',
            'status' => 'todo', 'progress_percent' => 0,
            'priority' => 2, 'sort_order' => 2,
        ]);

        $overview = $this->rpc('tools/call', [
            'name' => McpReadOnlyContextTool::NAME,
            'arguments' => ['plan_id' => $plan->id],
        ]);
        $overview->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.scope', 'overview')
            ->assertJsonPath('result.structuredContent.plan.title', 'Sensitive Development Plan')
            ->assertJsonCount(0, 'result.structuredContent.tasks')
            ->assertDontSee('SENSITIVE_DESCRIPTION_NOT_EXPORTED')
            ->assertDontSee('Recorded task one');

        $tasks = $this->rpc('tools/call', [
            'name' => McpReadOnlyContextTool::NAME,
            'arguments' => ['plan_id' => $plan->id, 'scope' => 'tasks', 'limit' => 1],
        ]);
        $tasks->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.scope', 'tasks')
            ->assertJsonPath('result.structuredContent.tasks.0.title', 'Recorded task one')
            ->assertJsonPath('result.structuredContent.tasks.0.recorded_progress_percent', 27)
            ->assertJsonPath('result.structuredContent.truncated', true)
            ->assertJsonPath('result.structuredContent.completion', 'unverified')
            ->assertJsonCount(1, 'result.structuredContent.tasks')
            ->assertDontSee('SENSITIVE_DESCRIPTION_NOT_EXPORTED')
            ->assertDontSee('SENSITIVE_SECOND_DESCRIPTION')
            ->assertDontSee('Recorded task two')
            ->assertDontSee('owner_token')
            ->assertDontSee('session');
        $this->assertSame(
            json_decode($tasks->json('result.content.0.text'), true),
            $tasks->json('result.structuredContent'),
        );

        $this->assertDatabaseCount('mcp_delegated_access_events', 2);
        $this->assertDatabaseHas('mcp_delegated_access_events', [
            'subject_link_id' => $link->id,
            'actor_user_id' => $owner->id,
            'event_kind' => 'context_read', 'scope' => 'tasks',
        ]);
        $this->assertDatabaseCount('mcp_delegated_grants', 1);
        $this->assertDatabaseCount('tasks', 2);
    }

    public function test_wrong_owner_missing_consent_insufficient_scope_and_non_development_all_look_unavailable(): void
    {
        $this->idp();
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $this->linkAndGrant($owner, $plan, 'overview');

        $other = User::factory()->create();
        $otherPlan = $this->plan($other);
        $absent = 190001;

        foreach ([
            ['plan_id' => $otherPlan->id],
            ['plan_id' => $absent],
            ['plan_id' => $plan->id, 'scope' => 'tasks'],
        ] as $args) {
            $this->rpc('tools/call', [
                'name' => McpReadOnlyContextTool::NAME,
                'arguments' => $args,
            ])->assertOk()
                ->assertJsonPath('result.isError', true)
                ->assertJsonPath('result.content.0.text', 'Plan unavailable or not authorized for the requested scope.')
                ->assertDontSee('Sensitive Development Plan');
        }

        $plan->update(['is_collaborative' => true]);
        $this->deniedPlan($plan->id);

        $plan->update(['is_collaborative' => false, 'category' => '資格学習']);
        $this->deniedPlan($plan->id);

        $plan->update(['category' => '個人開発', 'user_id' => $other->id]);
        $this->deniedPlan($plan->id);

        $this->assertDatabaseCount('mcp_delegated_access_events', 0);
    }

    public function test_revoked_expired_and_wrong_client_tokens_can_never_read(): void
    {
        $this->idp();
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $link = $this->linkAndGrant($owner, $plan);
        $grant = McpDelegatedGrant::query()->firstOrFail();
        $grant->update(['expires_at' => now()->subMinute()]);
        $this->deniedPlan($plan->id);

        $grant->update(['expires_at' => now()->addDay(), 'status' => 'revoked', 'revoked_at' => now()]);
        $this->deniedPlan($plan->id);

        $grant->update(['status' => 'active', 'revoked_at' => null]);
        $link->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->deniedPlan($plan->id);

        $link->update(['status' => 'linked', 'revoked_at' => null]);
        $this->assertDatabaseCount('mcp_delegated_access_events', 0);
    }

    public function test_idp_token_for_wrong_oauth_client_is_rejected_before_any_private_plan_access(): void
    {
        // Laravel Http::fake stubs persist within one test; configure this
        // negative IdP claim in its own fresh isolated test process.
        $this->idp(client: 'wrong-client-id');
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $this->linkAndGrant($owner, $plan);

        $this->rpc('tools/call', [
            'name' => McpReadOnlyContextTool::NAME,
            'arguments' => ['plan_id' => $plan->id],
        ])->assertUnauthorized();

        $this->assertDatabaseCount('mcp_delegated_access_events', 0);
    }

    public function test_malformed_protocol_arguments_and_origin_do_not_return_any_plan(): void
    {
        $this->idp();
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $this->linkAndGrant($owner, $plan);

        foreach ([
            ['plan_id' => (string) $plan->id],
            ['plan_id' => $plan->id, 'scope' => 'write'],
            ['plan_id' => $plan->id, 'limit' => 500],
            ['plan_id' => $plan->id, 'include_descriptions' => true],
            [],
        ] as $arguments) {
            $this->rpc('tools/call', [
                'name' => McpReadOnlyContextTool::NAME,
                'arguments' => $arguments,
            ])->assertStatus(400)
                ->assertJsonPath('error.code', -32602)
                ->assertDontSee('Sensitive Development Plan');
        }

        $this->rpc('tools/call', [
            'name' => 'create_task',
            'arguments' => ['plan_id' => $plan->id],
        ])->assertStatus(400)->assertDontSee('Sensitive');

        $this->rpc('tools/call', [
            'name' => McpReadOnlyContextTool::NAME,
            'arguments' => ['plan_id' => $plan->id],
        ], 1, self::TOKEN, 'https://evil.example.test')
            ->assertStatus(403)
            ->assertJsonPath('error', 'origin_not_allowed');

        config(['canovia_mcp.allowed_origins' => 'https://chatgpt.com']);
        $this->rpc('ping', [], 1, self::TOKEN, 'https://chatgpt.com')->assertOk();
        $this->rpc('ping', [], 1, self::TOKEN, 'https://evil.example.test')->assertStatus(403);

        $this->assertDatabaseCount('mcp_delegated_access_events', 0);
    }

    public function test_transport_is_read_only_without_sse_sessions_or_mutation(): void
    {
        $this->idp();
        $this->withHeaders(['Authorization' => 'Bearer '.self::TOKEN])
            ->getJson('/api/mcp')->assertStatus(405)->assertHeader('Allow', 'POST');
        $this->withHeaders(['Authorization' => 'Bearer '.self::TOKEN])
            ->deleteJson('/api/mcp')->assertStatus(405)->assertHeader('Allow', 'POST');

        $this->rpc('tasks/update', ['plan_id' => 5])->assertStatus(400)
            ->assertJsonPath('error.code', -32601);
        $this->rpc('resources/list')->assertStatus(400)
            ->assertJsonPath('error.code', -32601);
        $this->assertDatabaseCount('mcp_delegated_access_events', 0);
    }

    /** @param array<string, mixed> $params */
    private function rpc(
        string $method,
        array $params = [],
        int|string|null $id = 1,
        string $token = self::TOKEN,
        ?string $origin = null,
    ): \Illuminate\Testing\TestResponse {
        $headers = [
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
        ];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }
        if ($origin !== null) {
            $headers['Origin'] = $origin;
        }
        $body = ['jsonrpc' => '2.0', 'method' => $method];
        if ($id !== null) {
            $body['id'] = $id;
        }
        if ($params !== []) {
            $body['params'] = $params;
        }

        return $this->postJson('/api/mcp', $body, $headers);
    }

    private function deniedPlan(int $id, bool $expectError = true): void
    {
        $request = $this->rpc('tools/call', [
            'name' => McpReadOnlyContextTool::NAME,
            'arguments' => ['plan_id' => $id],
        ]);
        $request->assertOk()
            ->assertJsonPath('result.isError', $expectError);
    }

    private function plan(User $owner): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Sensitive Development Plan',
            'category' => '個人開発',
            'priority' => 1, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(7),
            'is_public' => false, 'is_collaborative' => false,
        ]);
    }

    private function linkAndGrant(
        User $owner,
        Plan $plan,
        string $scope = 'tasks',
    ): McpLinkedSubject {
        $fp = app(McpDelegatedIdentityFingerprintService::class);
        $link = McpLinkedSubject::query()->create([
            'user_id' => $owner->id,
            'provider_key' => 'chatgpt',
            'identity_fingerprint' => $fp->subject(self::ISSUER, 'verified-owner-subject'),
            'status' => 'linked',
            'linked_at' => now(),
        ]);
        McpDelegatedGrant::query()->create([
            'subject_link_id' => $link->id,
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'client_resource_fingerprint' => $fp->clientResource(
                self::CHATGPT_CLIENT, self::RESOURCE,
            ),
            'scope' => $scope,
            'status' => 'active',
            'consented_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        return $link;
    }

    private function idp(string $client = self::CHATGPT_CLIENT): void
    {
        Http::fake([self::INTROSPECT => Http::response([
            'active' => true,
            'iss' => self::ISSUER,
            'sub' => 'verified-owner-subject',
            'client_id' => $client,
            'aud' => self::RESOURCE,
            'token_type' => 'Bearer',
            'exp' => now()->timestamp + 1200,
            'iat' => now()->timestamp - 60,
            'nbf' => now()->timestamp - 5,
            'scope' => 'canovia.development.read',
        ], 200, ['Content-Type' => 'application/json'])]);
    }
}
