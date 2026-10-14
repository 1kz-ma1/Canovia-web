<?php

namespace Tests\Feature;

use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\Plan;
use App\Models\User;
use App\Services\McpDelegatedIdentityFingerprintService;
use App\Services\McpDelegatedPlanAccessPolicy;
use App\Services\McpReadOnlyContextTool;
use App\Services\McpStagingPlanReadDenialDiagnostic;
use App\Services\McpStagingSyntheticActorBootstrap;
use App\Services\McpStagingSyntheticPlanFixtureBootstrap;
use App\Services\McpVerifiedTokenPrincipal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class McpStagingPlanReadDenialDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.env' => 'staging',
            'canovia_staging.isolated' => true,
            'canovia_staging.synthetic_read_pilot_enabled' => true,
            'canovia_staging.mcp_explicitly_approved' => true,
            'canovia_mcp.tools_enabled' => true,
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.delegated_policy_enabled' => true,
            'canovia_mcp.token_verification_driver' => 'auth0_jwt',
            'canovia_mcp.auth0_jwt_verification_enabled' => true,
            'canovia_mcp.token_introspection_enabled' => false,
            'canovia_mcp.resource_url' => 'https://canovia.example.test/api/mcp',
            'canovia_mcp.oauth_issuer' => 'https://auth.example.test/tenant',
            'canovia_mcp.allowed_client_id' => 'synthetic-third-party-client',
            'canovia_mcp.identity_fingerprint_key' => str_repeat('d', 48),
        ]);
    }

    public function test_mismatched_verified_subject_reports_only_an_allowlisted_code(): void
    {
        [$plan, $principal] = $this->fixture();
        $this->expectCode('linked_identity_mismatch');

        $other = new McpVerifiedTokenPrincipal(
            $principal->issuer, 'different-invented-subject',
            $principal->clientId, $principal->audience,
            $principal->scopes, $principal->expiresAt,
        );
        $this->assertFalse(app(McpDelegatedPlanAccessPolicy::class)->allows($other, $plan));
    }

    public function test_existing_active_grant_for_different_client_binding_is_distinguished(): void
    {
        [$plan, $principal, $grant] = $this->fixture();
        $fingerprints = app(McpDelegatedIdentityFingerprintService::class);
        $grant->update(['client_resource_fingerprint' => $fingerprints->clientResource(
            'a-different-invented-client', $principal->audience,
        )]);
        $this->expectCode('grant_client_binding_mismatch');
        $this->assertFalse(app(McpDelegatedPlanAccessPolicy::class)->allows($principal, $plan));
    }

    public function test_inactive_or_missing_grant_is_not_confused_with_client_binding(): void
    {
        [$plan, $principal, $grant] = $this->fixture();
        $grant->update(['status' => McpDelegatedGrant::STATUS_REVOKED, 'revoked_at' => now()]);
        $this->expectCode('grant_missing_or_inactive');
        $this->assertFalse(app(McpDelegatedPlanAccessPolicy::class)->allows($principal, $plan));
    }

    public function test_overview_only_consent_rejects_tasks_but_allows_overview(): void
    {
        [$plan, $principal, $grant] = $this->fixture();
        $grant->update(['scope' => 'overview']);
        $this->expectCode('scope_denied');
        $this->assertFalse(app(McpDelegatedPlanAccessPolicy::class)->allows($principal, $plan, 'tasks'));

        // A successful read never needs to reveal a diagnostic category.
        $this->assertTrue(app(McpDelegatedPlanAccessPolicy::class)->allows($principal, $plan, 'overview'));
    }

    public function test_missing_plan_id_only_emits_a_fixed_category(): void
    {
        [, $principal] = $this->fixture();
        $this->expectCode('plan_not_found');
        $this->assertNull(app(McpReadOnlyContextTool::class)->read($principal, 999999, 'overview', 1));
    }

    public function test_production_and_unapproved_stage_emit_nothing(): void
    {
        Log::shouldReceive('channel')->never();

        config(['app.env' => 'production']);
        McpStagingPlanReadDenialDiagnostic::record('linked_identity_mismatch');

        config(['app.env' => 'staging', 'canovia_staging.isolated' => false]);
        McpStagingPlanReadDenialDiagnostic::record('linked_identity_mismatch');

        config(['canovia_staging.isolated' => true,
            'canovia_staging.synthetic_read_pilot_enabled' => false]);
        McpStagingPlanReadDenialDiagnostic::record('linked_identity_mismatch');

        config(['canovia_staging.synthetic_read_pilot_enabled' => true,
            'canovia_staging.mcp_explicitly_approved' => false]);
        McpStagingPlanReadDenialDiagnostic::record('linked_identity_mismatch');

        config(['canovia_staging.mcp_explicitly_approved' => true,
            'canovia_mcp.tools_enabled' => false]);
        McpStagingPlanReadDenialDiagnostic::record('linked_identity_mismatch');

        config(['canovia_mcp.tools_enabled' => true]);
        McpStagingPlanReadDenialDiagnostic::record('raw token value must never be emitted');
    }

    private function expectCode(string $code): void
    {
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')->once()->with(
            'mcp_stage_plan_read_denied', ['stage_code' => $code],
        );
        Log::shouldReceive('channel')->once()->with('stderr')->andReturn($logger);
    }

    /** @return array{Plan,McpVerifiedTokenPrincipal,McpDelegatedGrant} */
    private function fixture(): array
    {
        $owner = User::factory()->create(['email' => McpStagingSyntheticActorBootstrap::EMAIL]);
        $plan = Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => McpStagingSyntheticPlanFixtureBootstrap::TITLE,
            'creation_request_id' => McpStagingSyntheticPlanFixtureBootstrap::REQUEST_ID,
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(20),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
        $principal = new McpVerifiedTokenPrincipal(
            'https://auth.example.test/tenant', 'synthetic-user-subject',
            'synthetic-third-party-client', 'https://canovia.example.test/api/mcp',
            ['canovia.development.read'], now()->timestamp + 1200,
        );
        $fingerprints = app(McpDelegatedIdentityFingerprintService::class);
        $link = McpLinkedSubject::query()->create([
            'user_id' => $owner->id,
            'provider_key' => 'chatgpt',
            'identity_fingerprint' => $fingerprints->subject($principal->issuer, $principal->subject),
            'status' => McpLinkedSubject::STATUS_LINKED,
            'linked_at' => now(),
        ]);
        $grant = McpDelegatedGrant::query()->create([
            'subject_link_id' => $link->id,
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'client_resource_fingerprint' => $fingerprints->clientResource(
                $principal->clientId, $principal->audience,
            ),
            'scope' => 'tasks',
            'status' => McpDelegatedGrant::STATUS_ACTIVE,
            'consented_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        return [$plan, $principal, $grant];
    }
}
