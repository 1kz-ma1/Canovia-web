<?php

namespace Tests\Feature;

use App\Models\DevelopmentAiSharingPreference;
use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\Plan;
use App\Models\User;
use App\Services\McpDelegatedIdentityFingerprintService;
use App\Services\McpDelegatedPlanAccessPolicy;
use App\Services\McpVerifiedTokenPrincipal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class McpDelegatedPlanAccessPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.token_introspection_enabled' => true,
            'canovia_mcp.delegated_policy_enabled' => false,
            'canovia_mcp.resource_url' => 'https://canovia.example.test/api/mcp',
            'canovia_mcp.oauth_issuer' => 'https://auth.example.test/tenant',
            'canovia_mcp.allowed_client_id' => 'https://chatgpt.com/oauth/client.json',
            'canovia_mcp.read_scope' => 'canovia.development.read',
            'canovia_mcp.identity_fingerprint_key' => str_repeat('s', 48),
        ]);
    }

    public function test_no_link_or_only_prepared_preference_never_grants_read(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $principal = $this->principal();

        DevelopmentAiSharingPreference::query()->create([
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'provider_key' => 'chatgpt',
            'scope' => 'tasks',
            'status' => 'prepared',
            'expires_at' => now()->addDays(7),
        ]);

        $this->assertFalse($this->policy()->allows($principal, $plan, 'overview'));
        $this->assertFalse($this->policy()->allows($principal, $plan, 'tasks'));

        config(['canovia_mcp.delegated_policy_enabled' => true]);
        $this->assertFalse($this->policy()->allows($principal, $plan, 'overview'));
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
        $this->assertDatabaseCount('mcp_linked_subjects', 0);
    }

    public function test_every_condition_required_for_active_private_development_read(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $principal = $this->principal();

        [$link, $grant] = $this->linkAndGrant($owner, $plan, $principal, 'overview');

        // The policy is off even if a future grant table was populated.
        $this->assertFalse($this->policy()->allows($principal, $plan));
        config(['canovia_mcp.delegated_policy_enabled' => true]);

        $this->assertTrue($this->policy()->allows($principal, $plan, 'overview'));
        $this->assertFalse($this->policy()->allows($principal, $plan, 'tasks'));
        $this->assertFalse($this->policy()->allows($principal, $plan, 'write'));

        $grant->update(['scope' => 'tasks']);
        $this->assertTrue($this->policy()->allows($principal, $plan, 'overview'));
        $this->assertTrue($this->policy()->allows($principal, $plan, 'tasks'));

        $grant->update(['revoked_at' => now(), 'status' => 'revoked']);
        $this->assertFalse($this->policy()->allows($principal, $plan, 'overview'));

        $grant->update(['revoked_at' => null, 'status' => 'active']);
        $link->update(['revoked_at' => now(), 'status' => 'revoked']);
        $this->assertFalse($this->policy()->allows($principal, $plan, 'overview'));

        $this->assertDatabaseCount('mcp_delegated_grants', 1);
        $this->assertDatabaseCount('mcp_linked_subjects', 1);
    }

    public function test_expiry_unconsented_grant_owner_transfer_and_collaboration_fail_closed(): void
    {
        config(['canovia_mcp.delegated_policy_enabled' => true]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $plan = $this->plan($owner);
        $principal = $this->principal();

        [, $grant] = $this->linkAndGrant($owner, $plan, $principal, 'tasks');

        $grant->update(['expires_at' => now()->subMinute()]);
        $this->assertFalse($this->policy()->allows($principal, $plan, 'overview'));

        $grant->update(['expires_at' => now()->addDays(2), 'consented_at' => null]);
        $this->assertFalse($this->policy()->allows($principal, $plan, 'overview'));

        $grant->update(['consented_at' => now()]);
        $plan->update(['is_collaborative' => true]);
        $this->assertFalse($this->policy()->allows($principal, $plan, 'overview'));

        $plan->update(['is_collaborative' => false, 'category' => '資格学習']);
        $this->assertFalse($this->policy()->allows($principal, $plan, 'overview'));

        $plan->update(['category' => '個人開発', 'user_id' => $other->id]);
        $this->assertFalse($this->policy()->allows($principal, $plan, 'overview'));
    }

    public function test_wrong_issuer_subject_client_audience_token_scope_or_rotation_fail_closed(): void
    {
        config(['canovia_mcp.delegated_policy_enabled' => true]);
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $principal = $this->principal();
        $this->linkAndGrant($owner, $plan, $principal);

        $changes = [
            ['issuer' => 'https://evil.example.test'],
            ['subject' => 'a-different-subject'],
            ['clientId' => 'https://other.example.test/client.json'],
            ['audience' => 'https://other.example.test/api/mcp'],
            ['scopes' => ['other.read']],
            ['expiresAt' => now()->timestamp - 1],
            ['expiresAt' => now()->timestamp + 7200],
        ];

        foreach ($changes as $attributes) {
            $candidate = $this->principal(...$attributes);
            $this->assertFalse($this->policy()->allows($candidate, $plan, 'overview'));
        }

        config(['canovia_mcp.identity_fingerprint_key' => str_repeat('z', 48)]);
        $this->assertFalse($this->policy()->allows($principal, $plan, 'overview'));

        config(['canovia_mcp.identity_fingerprint_key' => 'weak']);
        $this->assertFalse($this->policy()->allows($principal, $plan, 'overview'));

        config(['canovia_mcp.identity_fingerprint_key' => str_repeat('s', 48)]);
        config(['canovia_mcp.token_introspection_enabled' => false]);
        $this->assertFalse($this->policy()->allows($principal, $plan, 'overview'));

        config(['canovia_mcp.token_introspection_enabled' => true]);
        config(['canovia_mcp.discovery_enabled' => false]);
        $this->assertFalse($this->policy()->allows($principal, $plan, 'overview'));
    }

    public function test_raw_identity_never_stored_and_account_link_is_unique_across_users(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $plan = $this->plan($owner);
        $principal = $this->principal();
        [$link, $grant] = $this->linkAndGrant($owner, $plan, $principal);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $link->identity_fingerprint);
        $this->assertNotSame($principal->subject, $link->identity_fingerprint);
        $this->assertNotSame($principal->clientId, $grant->client_resource_fingerprint);
        $this->assertSame(64, strlen($grant->client_resource_fingerprint));

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        McpLinkedSubject::query()->create([
            'user_id' => $other->id,
            'identity_fingerprint' => $link->identity_fingerprint,
            'provider_key' => 'chatgpt',
            'status' => 'linked',
            'linked_at' => now(),
        ]);
    }

    private function policy(): McpDelegatedPlanAccessPolicy
    {
        return app(McpDelegatedPlanAccessPolicy::class);
    }

    private function principal(
        string $issuer = 'https://auth.example.test/tenant',
        string $subject = 'stable-external-identity',
        string $clientId = 'https://chatgpt.com/oauth/client.json',
        string $audience = 'https://canovia.example.test/api/mcp',
        array $scopes = ['canovia.development.read'],
        ?int $expiresAt = null,
    ): McpVerifiedTokenPrincipal {
        return new McpVerifiedTokenPrincipal(
            $issuer, $subject, $clientId, $audience, $scopes,
            $expiresAt ?? now()->timestamp + 1200,
        );
    }

    /** @return array{McpLinkedSubject,McpDelegatedGrant} */
    private function linkAndGrant(
        User $owner,
        Plan $plan,
        McpVerifiedTokenPrincipal $principal,
        string $scope = 'tasks',
    ): array {
        $fingerprint = app(McpDelegatedIdentityFingerprintService::class);

        $link = McpLinkedSubject::query()->create([
            'user_id' => $owner->id,
            'identity_fingerprint' => $fingerprint->subject($principal->issuer, $principal->subject),
            'provider_key' => 'chatgpt',
            'status' => 'linked',
            'linked_at' => now(),
        ]);
        $grant = McpDelegatedGrant::query()->create([
            'subject_link_id' => $link->id,
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'client_resource_fingerprint' => $fingerprint->clientResource($principal->clientId, $principal->audience),
            'scope' => $scope,
            'status' => 'active',
            'consented_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        return [$link, $grant];
    }

    private function plan(User $owner): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Sensitive Development Plan',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(20),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }
}
