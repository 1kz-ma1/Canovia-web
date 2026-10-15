<?php

namespace Tests\Feature;

use App\Models\DevelopmentAiSharingPreference;
use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\McpStudyResultProposal;
use App\Services\McpProposeStudyResultTool;
use App\Models\Task;
use App\Models\User;
use App\Services\McpDelegatedIdentityFingerprintService;
use App\Services\McpReadOnlyContextTool;
use App\Services\McpReadOnlyStudyContextTool;
use App\Services\McpReadOnlyStudyPracticeSummaryTool;
use App\Services\McpDelegatedRevocationService;
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
            ->assertJsonCount(1, 'result.tools.0.securitySchemes')
            ->assertJsonPath('result.tools.0.securitySchemes.0.type', 'oauth2')
            ->assertJsonPath('result.tools.0.securitySchemes.0.scopes', ['canovia.development.read'])
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

    public function test_grant_revoked_during_token_verification_cannot_return_private_plan_or_read_audit(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $this->linkAndGrant($owner, $plan);
        $grant = McpDelegatedGrant::query()->sole();

        // An explicitly approved until-revoked grant must fail just as
        // firmly as a finite grant once its owner revokes it.
        $grant->update(['expires_at' => null]);
        $tokenChecks = 0;
        Http::fake(function (ClientRequest $request) use ($owner, $grant, &$tokenChecks) {
            $this->assertSame(self::INTROSPECT, $request->url());
            $tokenChecks++;
            app(McpDelegatedRevocationService::class)->revokeGrant($owner, $grant->id);

            return Http::response([
                'active' => true, 'iss' => self::ISSUER,
                'sub' => 'verified-owner-subject',
                'client_id' => self::CHATGPT_CLIENT,
                'aud' => self::RESOURCE, 'token_type' => 'Bearer',
                'exp' => now()->timestamp + 1200,
                'iat' => now()->timestamp - 60,
                'nbf' => now()->timestamp - 5,
                'scope' => 'canovia.development.read',
            ], 200, ['Content-Type' => 'application/json']);
        });

        $this->rpc('tools/call', [
            'name' => McpReadOnlyContextTool::NAME,
            'arguments' => ['plan_id' => $plan->id, 'scope' => 'overview'],
        ])->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'Plan unavailable or not authorized for the requested scope.')
            ->assertDontSee('Sensitive Development Plan');
        $this->assertSame(1, $tokenChecks);
        $this->assertSame('revoked', $grant->fresh()->status);
        $this->assertDatabaseHas('mcp_delegated_access_events', [
            'grant_id' => $grant->id, 'event_kind' => 'grant_revoked',
        ]);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('mcp_delegated_access_events')
            ->where('event_kind', 'context_read')->count());
    }

    public function test_identity_unlinked_during_token_verification_cannot_return_private_plan(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $link = $this->linkAndGrant($owner, $plan);
        $grant = McpDelegatedGrant::query()->sole();

        Http::fake(function (ClientRequest $request) use ($owner, $link) {
            $this->assertSame(self::INTROSPECT, $request->url());
            app(McpDelegatedRevocationService::class)->revokeSubject($owner, $link->id);
            return Http::response([
                'active' => true, 'iss' => self::ISSUER,
                'sub' => 'verified-owner-subject',
                'client_id' => self::CHATGPT_CLIENT,
                'aud' => self::RESOURCE, 'token_type' => 'Bearer',
                'exp' => now()->timestamp + 1200,
                'iat' => now()->timestamp - 60,
                'nbf' => now()->timestamp - 5,
                'scope' => 'canovia.development.read',
            ], 200, ['Content-Type' => 'application/json']);
        });

        $this->rpc('tools/call', [
            'name' => McpReadOnlyContextTool::NAME,
            'arguments' => ['plan_id' => $plan->id, 'scope' => 'tasks'],
        ])->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertDontSee('Sensitive Development Plan');
        $this->assertSame('revoked', $link->fresh()->status);
        $this->assertNull($link->fresh()->active_identity_fingerprint);
        $this->assertSame('revoked', $grant->fresh()->status);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('mcp_delegated_access_events')
            ->where('event_kind', 'context_read')->count());
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

    public function test_opt_in_study_tool_reads_only_approved_private_plan_and_task_fields(): void
    {
        $this->idp();
        $owner = User::factory()->create();
        config(['canovia_mcp.ap_pilot_owner_id' => (string) $owner->id]);
        $study = $this->plan($owner);
        $study->update(['category' => '資格学習', 'title' => 'SYNTHETIC_AP_LEARNING']);
        $this->linkAndGrant($owner, $study, 'overview');
        $grant = McpDelegatedGrant::query()->sole();
        $task = Task::query()->create([
            'plan_id' => $study->id,
            'title' => 'Network practice',
            'description' => 'PRIVATE_ANSWER_DO_NOT_EXPORT',
            'status' => 'doing',
            'progress_percent' => 35,
            'priority' => 1,
            'sort_order' => 1,
        ]);

        // No tool discovery, read or OAuth consent before the independent
        // switch is enabled; the development tool must NOT cross domains.
        $this->rpc('tools/list')->assertOk()->assertJsonCount(1, 'result.tools');
        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyContextTool::NAME,
            'arguments' => ['plan_id' => $study->id],
        ])->assertStatus(400)->assertJsonPath('error.code', -32602);
        $this->rpc('tools/call', [
            'name' => McpReadOnlyContextTool::NAME,
            'arguments' => ['plan_id' => $study->id],
        ])->assertOk()->assertJsonPath('result.isError', true);

        config(['canovia_mcp.study_read_enabled' => true]);
        $this->assertTrue(app(\App\Services\McpExplicitPlanConsentService::class)
            ->isEligibleMcpPlanOwner($owner, $study));
        $this->rpc('tools/list')->assertOk()
            ->assertJsonCount(2, 'result.tools')
            ->assertJsonPath('result.tools.1.name', McpReadOnlyStudyContextTool::NAME)
            ->assertJsonPath('result.tools.1.annotations.readOnlyHint', true);

        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyContextTool::NAME,
            'arguments' => ['plan_id' => $study->id],
        ])->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.plan.title', 'SYNTHETIC_AP_LEARNING')
            ->assertJsonPath('result.structuredContent.plan.recorded_deadline',
                today()->addDays(7)->toDateString())
            ->assertJsonCount(0, 'result.structuredContent.tasks')
            ->assertDontSee('Network practice');

        // A prior overview grant cannot silently expose Task details.
        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyContextTool::NAME,
            'arguments' => ['plan_id' => $study->id, 'scope' => 'tasks'],
        ])->assertOk()->assertJsonPath('result.isError', true)
            ->assertDontSee('Network practice');

        // Mimics a distinct user-approved Tasks grant, not an MCP write.
        $grant->update(['scope' => 'tasks']);
        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyContextTool::NAME,
            'arguments' => ['plan_id' => $study->id, 'scope' => 'tasks'],
        ])->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.tasks.0.task_id', $task->id)
            ->assertJsonPath('result.structuredContent.tasks.0.title', 'Network practice')
            ->assertJsonPath('result.structuredContent.tasks.0.recorded_progress_percent', 35)
            ->assertDontSee('PRIVATE_ANSWER_DO_NOT_EXPORT');
        $this->rpc('tools/call', [
            'name' => McpReadOnlyContextTool::NAME,
            'arguments' => ['plan_id' => $study->id],
        ])->assertOk()->assertJsonPath('result.isError', true)
            ->assertDontSee('SYNTHETIC_AP_LEARNING');

        $grant->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyContextTool::NAME,
            'arguments' => ['plan_id' => $study->id],
        ])->assertOk()->assertJsonPath('result.isError', true)
            ->assertDontSee('SYNTHETIC_AP_LEARNING');
    }

    public function test_study_domain_always_denied_on_synthetic_staging_even_when_switch_is_set(): void
    {
        $owner = User::factory()->create();
        config(['canovia_mcp.ap_pilot_owner_id' => (string) $owner->id]);
        $study = $this->plan($owner);
        $study->update(['category' => '資格学習', 'title' => 'DO_NOT_EXPOSE']);
        $principal = new \App\Services\McpVerifiedTokenPrincipal(
            self::ISSUER,
            'verified-owner-subject',
            self::CHATGPT_CLIENT,
            self::RESOURCE,
            ['canovia.development.read'],
            now()->timestamp + 1200,
        );
        $this->linkAndGrant($owner, $study);
        config([
            'app.env' => 'staging',
            'canovia_mcp.study_read_enabled' => true,
        ]);
        $this->assertFalse(app(\App\Services\McpExplicitPlanConsentService::class)
            ->isEligibleMcpPlanOwner($owner, $study));
        $this->assertNull(app(McpReadOnlyStudyContextTool::class)
            ->read($principal, $study->id, 'overview', 5));
    }

    public function test_ap_practice_summary_requires_separate_progress_consent_and_excludes_private_answers(): void
    {
        $this->idp();
        $owner = User::factory()->create();
        config(['canovia_mcp.ap_pilot_owner_id' => (string) $owner->id]);
        $study = $this->plan($owner);
        $study->update(['category' => '資格学習', 'title' => 'Synthetic AP Exam Plan']);
        $this->linkAndGrant($owner, $study, 'tasks');
        $grant = McpDelegatedGrant::query()->sole();
        $task = Task::query()->create([
            'plan_id' => $study->id,
            'title' => 'AP network basics', 'status' => 'doing',
            'progress_percent' => 25, 'priority' => 1, 'sort_order' => 1,
        ]);

        $record = function (int $score, array $weak, bool $applied, ?int $userId) use ($study, $task): void {
            StudyPracticeAttempt::query()->create([
                'plan_id' => $study->id,
                'task_id' => $task->id,
                'user_id' => $userId,
                'request_hash' => bin2hex(random_bytes(32)),
                'exercise_title' => 'SECRET_ATTEMPT_TITLE_NOT_EXPORTED',
                'questions' => [['body' => 'SECRET_QUESTION_NOT_EXPORTED']],
                'answers' => [['text' => 'SECRET_ANSWER_NOT_EXPORTED']],
                'assessment' => $score === 80
                    ? [
                        'rationale' => 'SECRET_GRADING_DETAIL_NOT_EXPORTED',
                        'question_feedback' => [[
                            'correctness' => 'incorrect',
                            'weakness_topics' => ['ネットワーク'],
                            'explanation' => 'SECRET_MODERN_FEEDBACK_NOT_EXPORTED',
                        ]],
                    ]
                    : ['rationale' => 'SECRET_GRADING_DETAIL_NOT_EXPORTED'],
                'score_percent' => $score,
                'recommended_task_progress_percent' => 30,
                'weaknesses' => $weak,
                'applied_at' => $applied ? now() : null,
            ]);
        };
        $record(60, ['ネットワーク', 'データベース'], true, $owner->id);
        $record(80, ['LEGACY_LABEL_IGNORED_FOR_TYPED_FEEDBACK'], true, $owner->id);
        $record(100, ['PRIVATE_UNAPPLIED_WEAKNESS'], false, $owner->id);
        $record(0, ['PRIVATE_OTHER_USER_WEAKNESS'], true, User::factory()->create()->id);

        config(['canovia_mcp.study_read_enabled' => true]);
        $this->rpc('tools/list')->assertOk()->assertJsonCount(2, 'result.tools');
        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyPracticeSummaryTool::NAME,
            'arguments' => ['plan_id' => $study->id],
        ])->assertStatus(400)->assertJsonPath('error.code', -32602);

        config(['canovia_mcp.study_summary_read_enabled' => true]);
        $this->rpc('tools/list')->assertOk()
            ->assertJsonCount(3, 'result.tools')
            ->assertJsonPath('result.tools.2.name', McpReadOnlyStudyPracticeSummaryTool::NAME)
            ->assertJsonPath('result.tools.2.annotations.readOnlyHint', true);

        // Existing Tasks consent and a signed bearer are NOT sufficient.
        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyPracticeSummaryTool::NAME,
            'arguments' => ['plan_id' => $study->id],
        ])->assertOk()->assertJsonPath('result.isError', true)
            ->assertDontSee('Synthetic AP Exam Plan');
        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyPracticeSummaryTool::NAME,
            'arguments' => ['plan_id' => $study->id, 'scope' => 'overview'],
        ])->assertStatus(400)->assertJsonPath('error.code', -32602);

        // Separate explicit owner approval simulated by a scoped fixture
        // grant, not by granting access through the MCP client.
        $grant->update(['scope' => 'study_progress']);
        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyPracticeSummaryTool::NAME,
            'arguments' => ['plan_id' => $study->id],
        ])->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.scope', 'study_progress')
            ->assertJsonPath('result.structuredContent.plan.title', 'Synthetic AP Exam Plan')
            ->assertJsonPath('result.structuredContent.sample.applied_attempts_considered', 2)
            ->assertJsonPath('result.structuredContent.sample.average_score_percent', 70)
            ->assertJsonPath('result.structuredContent.sample.latest_score_percent', 80)
            ->assertJsonPath('result.structuredContent.weak_topics.0.topic', 'ネットワーク')
            ->assertJsonPath('result.structuredContent.weak_topics.0.mentions', 2)
            ->assertDontSee('SECRET_QUESTION_NOT_EXPORTED')
            ->assertDontSee('SECRET_ANSWER_NOT_EXPORTED')
            ->assertDontSee('SECRET_GRADING_DETAIL_NOT_EXPORTED')
            ->assertDontSee('SECRET_MODERN_FEEDBACK_NOT_EXPORTED')
            ->assertDontSee('LEGACY_LABEL_IGNORED_FOR_TYPED_FEEDBACK')
            ->assertDontSee('PRIVATE_OTHER_USER_WEAKNESS')
            ->assertDontSee('PRIVATE_UNAPPLIED_WEAKNESS');
        // A stronger scope must not be reusable in Development context.
        $this->rpc('tools/call', [
            'name' => McpReadOnlyContextTool::NAME,
            'arguments' => ['plan_id' => $study->id],
        ])->assertOk()->assertJsonPath('result.isError', true);
        // Progress grants may also read the tasks named in the consent UI.
        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyContextTool::NAME,
            'arguments' => ['plan_id' => $study->id, 'scope' => 'tasks'],
        ])->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.tasks.0.title', 'AP network basics');

        $grant->update(['scope' => 'overview']);
        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyPracticeSummaryTool::NAME,
            'arguments' => ['plan_id' => $study->id],
        ])->assertOk()->assertJsonPath('result.isError', true);
        $grant->update(['scope' => 'study_progress', 'status' => 'revoked', 'revoked_at' => now()]);
        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyPracticeSummaryTool::NAME,
            'arguments' => ['plan_id' => $study->id],
        ])->assertOk()->assertJsonPath('result.isError', true)
            ->assertDontSee('Synthetic AP Exam Plan');
    }

    public function test_ap_summary_never_appears_on_synthetic_staging_even_when_enabled(): void
    {
        config([
            'canovia_mcp.study_read_enabled' => true,
            'canovia_mcp.study_summary_read_enabled' => true,
            'app.env' => 'staging',
        ]);
        $owner = User::factory()->create();
        config(['canovia_mcp.ap_pilot_owner_id' => (string) $owner->id]);
        $study = $this->plan($owner);
        $study->update(['category' => '資格学習']);
        $this->linkAndGrant($owner, $study, 'study_progress');
        $principal = new \App\Services\McpVerifiedTokenPrincipal(
            self::ISSUER, 'verified-owner-subject', self::CHATGPT_CLIENT,
            self::RESOURCE, ['canovia.development.read'], now()->timestamp + 1200,
        );
        $this->assertNull(app(McpReadOnlyStudyPracticeSummaryTool::class)
            ->read($principal, $study->id, 8));
        $this->assertFalse(app(\App\Services\McpExplicitPlanConsentService::class)
            ->isScopeEligibleForPlan($study, 'study_progress'));
        $this->assertDatabaseCount('mcp_delegated_access_events', 0);
    }

    public function test_mcp_study_result_requires_distinct_write_bearer_and_owner_approval(): void
    {
        $this->idp(write: true);
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        config(['canovia_mcp.ap_pilot_owner_id' => (string) $owner->id]);
        $study = $this->plan($owner);
        $study->update(['category' => '資格学習', 'title' => 'SYNTHETIC_AP_OWNER_APPROVAL']);
        $task = Task::query()->create([
            'plan_id' => $study->id, 'title' => 'AP network',
            'status' => 'doing', 'progress_percent' => 10,
            'priority' => 1, 'sort_order' => 1,
        ]);
        $this->linkAndGrant($owner, $study, 'study_progress');
        $proposalArgs = [
            'plan_id' => $study->id,
            'task_id' => $task->id,
            'idempotency_key' => str_repeat('a', 64),
            'question_count' => 10,
            'correct_count' => 7,
            'weaknesses' => ['ネットワーク', 'DNS'],
        ];
        config([
            'canovia_mcp.study_read_enabled' => true,
            'canovia_mcp.study_summary_read_enabled' => true,
        ]);
        $this->rpc('tools/call', [
            'name' => McpProposeStudyResultTool::NAME,
            'arguments' => $proposalArgs,
        ])->assertStatus(400);
        config(['canovia_mcp.study_result_proposals_enabled' => true]);
        $this->rpc('tools/list')->assertOk()
            ->assertJsonCount(4, 'result.tools')
            ->assertJsonPath('result.tools.3.name', McpProposeStudyResultTool::NAME)
            ->assertJsonPath('result.tools.3.annotations.readOnlyHint', false);
        $first = $this->rpc('tools/call', [
            'name' => McpProposeStudyResultTool::NAME,
            'arguments' => $proposalArgs,
        ]);
        $first->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.status', 'pending')
            ->assertJsonPath('result.structuredContent.recorded_in_learning_history', false);
        $id = (int) $first->json('result.structuredContent.proposal_id');
        $this->assertGreaterThan(0, $id);
        $this->assertDatabaseCount('mcp_study_result_proposals', 1);

        // Retries are idempotent. A reused key with altered content is denied.
        $this->rpc('tools/call', [
            'name' => McpProposeStudyResultTool::NAME,
            'arguments' => $proposalArgs,
        ])->assertOk()->assertJsonPath('result.structuredContent.proposal_id', $id);
        $altered = [...$proposalArgs, 'correct_count' => 8];
        $this->rpc('tools/call', [
            'name' => McpProposeStudyResultTool::NAME,
            'arguments' => $altered,
        ])->assertOk()->assertJsonPath('result.isError', true);
        $this->assertDatabaseCount('mcp_study_result_proposals', 1);

        // Pending submissions are NEVER counted as verified study history.
        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyPracticeSummaryTool::NAME,
            'arguments' => ['plan_id' => $study->id],
        ])->assertOk()
            ->assertJsonPath('result.structuredContent.sample.applied_attempts_considered', 0);
        $this->actingAs($owner)->get(route('auth.account'))->assertOk()
            ->assertSee('data-mcp-study-result-proposal', false)
            ->assertSee('SYNTHETIC_AP_OWNER_APPROVAL')
            ->assertSee('7 / 10');
        $this->actingAs($owner)->post(route('auth.account.mcp_study_result.decide', [
            'proposal' => $id,
        ]), ['decision' => 'approve'])->assertRedirect(route('auth.account'));
        $this->assertSame(McpStudyResultProposal::APPROVED,
            McpStudyResultProposal::query()->findOrFail($id)->status);
        $this->assertSame(10, $task->fresh()->progress_percent);
        $this->assertDatabaseCount('study_practice_attempts', 0);
        // A repeated confirmation cannot commit a second result.
        $this->post(route('auth.account.mcp_study_result.decide', [
            'proposal' => $id,
        ]), ['decision' => 'approve'])->assertRedirect(route('auth.account'));
        $this->assertDatabaseCount('mcp_study_result_proposals', 1);
        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyPracticeSummaryTool::NAME,
            'arguments' => ['plan_id' => $study->id],
        ])->assertOk()
            ->assertJsonPath('result.structuredContent.sample.applied_attempts_considered', 1)
            ->assertJsonPath('result.structuredContent.sample.owner_approved_mcp_results_considered', 1)
            ->assertJsonPath('result.structuredContent.sample.latest_score_percent', 70)
            ->assertJsonPath('result.structuredContent.weak_topics.0.topic', 'DNS');
    }

    public function test_read_only_bearer_cannot_propose_and_no_unapproved_result_leaks(): void
    {
        $this->idp(); // Valid token but lacks independent write scope.
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        config(['canovia_mcp.ap_pilot_owner_id' => (string) $owner->id]);
        $study = $this->plan($owner);
        $study->update(['category' => '資格学習']);
        $task = Task::query()->create([
            'plan_id' => $study->id, 'title' => 'AP DB',
            'status' => 'doing', 'progress_percent' => 0,
            'priority' => 1, 'sort_order' => 1,
        ]);
        $this->linkAndGrant($owner, $study, 'study_progress');
        config([
            'canovia_mcp.study_read_enabled' => true,
            'canovia_mcp.study_summary_read_enabled' => true,
            'canovia_mcp.study_result_proposals_enabled' => true,
        ]);
        $this->rpc('tools/list')->assertOk()->assertJsonCount(3, 'result.tools');
        $this->rpc('tools/call', [
            'name' => McpProposeStudyResultTool::NAME,
            'arguments' => [
                'plan_id' => $study->id, 'task_id' => $task->id,
                'question_count' => 10, 'correct_count' => 6,
                'idempotency_key' => str_repeat('b', 64),
                'weaknesses' => ['DB'],
            ],
        ])->assertOk()->assertJsonPath('result.isError', true);
        $this->assertDatabaseCount('mcp_study_result_proposals', 0);
    }

    public function test_study_result_rejected_expired_wrong_task_and_wrong_owner_never_affect_scores(): void
    {
        $this->idp(write: true);
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        config(['canovia_mcp.ap_pilot_owner_id' => (string) $owner->id]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);
        $study = $this->plan($owner);
        $study->update(['category' => '資格学習', 'title' => 'SECURE_AP_TEST']);
        $task = Task::query()->create([
            'plan_id' => $study->id, 'title' => 'AP database',
            'status' => 'doing', 'progress_percent' => 5,
            'priority' => 1, 'sort_order' => 1,
        ]);
        $otherStudy = $this->plan($other);
        $otherStudy->update(['category' => '資格学習']);
        $foreignTask = Task::query()->create([
            'plan_id' => $otherStudy->id, 'title' => 'Other account confidential Task',
            'status' => 'todo', 'progress_percent' => 0,
            'priority' => 1, 'sort_order' => 1,
        ]);
        $this->linkAndGrant($owner, $study, 'study_progress');
        config([
            'canovia_mcp.study_read_enabled' => true,
            'canovia_mcp.study_summary_read_enabled' => true,
            'canovia_mcp.study_result_proposals_enabled' => true,
        ]);
        $args = [
            'plan_id' => $study->id, 'task_id' => $task->id,
            'question_count' => 10, 'correct_count' => 4,
            'idempotency_key' => str_repeat('c', 64),
            'weaknesses' => ['データベース'],
        ];
        $this->rpc('tools/call', [
            'name' => McpProposeStudyResultTool::NAME,
            'arguments' => [...$args, 'task_id' => $foreignTask->id],
        ])->assertOk()->assertJsonPath('result.isError', true)
            ->assertDontSee('Other account confidential Task');
        $first = $this->rpc('tools/call', [
            'name' => McpProposeStudyResultTool::NAME,
            'arguments' => $args,
        ]);
        $first->assertOk()->assertJsonPath('result.isError', false);
        $id = (int) $first->json('result.structuredContent.proposal_id');

        $this->actingAs($other)->post(route('auth.account.mcp_study_result.decide', [
            'proposal' => $id,
        ]), ['decision' => 'approve'])->assertNotFound();
        $this->assertSame('pending', McpStudyResultProposal::query()->findOrFail($id)->status);

        $this->actingAs($owner)->post(route('auth.account.mcp_study_result.decide', [
            'proposal' => $id,
        ]), ['decision' => 'reject'])->assertRedirect();
        $this->assertSame('rejected', McpStudyResultProposal::query()->findOrFail($id)->status);
        $this->post(route('auth.account.mcp_study_result.decide', [
            'proposal' => $id,
        ]), ['decision' => 'approve'])->assertRedirect();
        $this->assertSame('rejected', McpStudyResultProposal::query()->findOrFail($id)->status);

        $second = $this->rpc('tools/call', [
            'name' => McpProposeStudyResultTool::NAME,
            'arguments' => [...$args, 'idempotency_key' => str_repeat('d', 64)],
        ]);
        $second->assertOk()->assertJsonPath('result.isError', false);
        $secondId = (int) $second->json('result.structuredContent.proposal_id');
        McpStudyResultProposal::query()->whereKey($secondId)
            ->update(['expires_at' => now()->subMinute()]);
        $this->post(route('auth.account.mcp_study_result.decide', [
            'proposal' => $secondId,
        ]), ['decision' => 'approve'])->assertRedirect();
        $this->assertSame('pending', McpStudyResultProposal::query()->findOrFail($secondId)->status);

        // A signed ChatGPT bearer still cannot make rejected or expired
        // proposals appear in the aggregated, owner-approved results.
        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyPracticeSummaryTool::NAME,
            'arguments' => ['plan_id' => $study->id],
        ])->assertOk()
            ->assertJsonPath('result.structuredContent.sample.applied_attempts_considered', 0);
        $this->assertSame(5, $task->fresh()->progress_percent);
    }

    public function test_ap_pilot_denies_real_study_even_when_other_flags_and_grants_are_enabled(): void
    {
        $this->idp(write: true);
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);
        $study = $this->plan($owner);
        $study->update(['category' => '資格学習', 'title' => 'PRIVATE_AP_PILOT_ONLY']);
        $task = Task::query()->create([
            'plan_id' => $study->id,
            'title' => 'Private AP Network Task',
            'status' => 'doing',
            'progress_percent' => 10,
            'priority' => 1,
            'sort_order' => 1,
        ]);
        $this->linkAndGrant($owner, $study, 'study_progress');
        config([
            'canovia_mcp.study_read_enabled' => true,
            'canovia_mcp.study_summary_read_enabled' => true,
            'canovia_mcp.study_result_proposals_enabled' => true,
        ]);

        $policy = app(\\App\\Services\\McpStudyPilotAccessPolicy::class);
        foreach (['', '0', '1,2', '01', '-1', 'garbage'] as $invalid) {
            config(['canovia_mcp.ap_pilot_owner_id' => $invalid]);
            $this->assertFalse($policy->configured());
            $this->assertFalse($policy->allowsPlan($study));
        }

        // Another real user being allowlisted MUST NOT receive the original
        // owner's Plan, even with a valid ChatGPT token and active grant.
        config(['canovia_mcp.ap_pilot_owner_id' => (string) $other->id]);
        $this->assertTrue($policy->configured());
        $this->assertFalse($policy->allowsPlan($study));
        $consent = app(\\App\\Services\\McpExplicitPlanConsentService::class);
        $this->assertFalse($consent->isEligibleMcpPlanOwner($owner, $study));
        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyContextTool::NAME,
            'arguments' => ['plan_id' => $study->id, 'scope' => 'tasks'],
        ])->assertOk()->assertJsonPath('result.isError', true)
            ->assertDontSee('PRIVATE_AP_PILOT_ONLY');
        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyPracticeSummaryTool::NAME,
            'arguments' => ['plan_id' => $study->id],
        ])->assertOk()->assertJsonPath('result.isError', true)
            ->assertDontSee('PRIVATE_AP_PILOT_ONLY');
        $this->rpc('tools/call', [
            'name' => McpProposeStudyResultTool::NAME,
            'arguments' => [
                'plan_id' => $study->id,
                'task_id' => $task->id,
                'question_count' => 10,
                'correct_count' => 7,
                'idempotency_key' => str_repeat('e', 64),
                'weaknesses' => ['network'],
            ],
        ])->assertOk()->assertJsonPath('result.isError', true);
        $this->assertDatabaseCount('mcp_study_result_proposals', 0);
        $this->assertSame(10, $task->fresh()->progress_percent);

        // The existing Development domain cannot be affected by the
        // AP-only owner allowlist; signed owner authorization is independent.
        config(['canovia_mcp.ap_pilot_owner_id' => (string) $owner->id]);
        $this->assertTrue($policy->allowsPlan($study));
        $this->assertTrue($consent->isEligibleMcpPlanOwner($owner, $study));
        $this->rpc('tools/call', [
            'name' => McpReadOnlyStudyContextTool::NAME,
            'arguments' => ['plan_id' => $study->id],
        ])->assertOk()
            ->assertJsonPath('result.structuredContent.plan.title', 'PRIVATE_AP_PILOT_ONLY');
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

    private function idp(string $client = self::CHATGPT_CLIENT, bool $write = false): void
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
            'scope' => $write ? 'canovia.development.read canovia.study.write' : 'canovia.development.read',
        ], 200, ['Content-Type' => 'application/json'])]);
    }
}
