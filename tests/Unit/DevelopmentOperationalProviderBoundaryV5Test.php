<?php

namespace Tests\Unit;

use App\Models\DevelopmentOperationalTarget;
use App\Models\Plan;
use App\Services\DevelopmentOperationalProviderBoundaryService;
use DateTimeImmutable;
use Tests\TestCase;

final class DevelopmentOperationalProviderBoundaryV5Test extends TestCase
{
    private function plan(int $id = 42, int $ownerId = 7): Plan
    {
        $plan = new Plan(['title' => 'Development']);
        $plan->id = $id;
        $plan->user_id = $ownerId;

        return $plan;
    }

    private function target(): DevelopmentOperationalTarget
    {
        return new DevelopmentOperationalTarget([
            'plan_id' => 42,
            'provider' => 'render',
            'environment' => 'production',
            'resource_ref' => 'srv-sandbox123',
            'revision' => 3,
        ]);
    }

    private function grant(): array
    {
        return [
            'connection_id' => 'conn-owner123',
            'provider' => 'render',
            'owner_id' => 7,
            'plan_id' => 42,
            'workspace_id' => 'tea-owner123',
            'service_id' => 'srv-sandbox123',
            'target_revision' => 3,
            'consent_epoch' => 4,
            'identity_verified' => true,
            'state' => 'active',
            'revoked_at' => null,
            'scopes' => ['services.read'],
            'expires_at' => '2026-10-15T07:00:00+00:00',
        ];
    }

    private function observation(): array
    {
        return [
            'origin' => 'authenticated_provider_adapter',
            'provider' => 'render',
            'kind' => 'service_metadata',
            'service_exists' => true,
            'connection_id' => 'conn-owner123',
            'workspace_id' => 'tea-owner123',
            // Taken from Render's resource.ownerId, NOT from owner grant.
            'service_owner_id' => 'tea-owner123',
            'service_id' => 'srv-sandbox123',
            'target_revision' => 3,
            'consent_epoch' => 4,
            'observed_at' => '2026-10-14T06:59:00+00:00',
        ];
    }

    private function assess(
        ?array $grant = null,
        ?array $observation = null,
        ?Plan $plan = null,
        ?DevelopmentOperationalTarget $target = null,
    ): array {
        return (new DevelopmentOperationalProviderBoundaryService())->assess(
            $plan ?? $this->plan(),
            $target ?? $this->target(),
            $grant,
            $observation,
            new DateTimeImmutable('2026-10-14T07:00:00+00:00'),
        );
    }

    public function test_absent_connection_or_observation_never_claims_a_provider_check(): void
    {
        $this->assertSame('unknown', $this->assess()['status']);
        $this->assertSame('unknown', $this->assess($this->grant())['status']);
        $this->assertSame('unknown', $this->assess(null, $this->observation())['status']);
    }

    public function test_exact_active_owner_consent_and_metadata_are_only_service_presence_observation(): void
    {
        $result = $this->assess($this->grant(), $this->observation());
        $this->assertSame('observed', $result['status']);
        $this->assertSame('service_presence', $result['kind']);
        $this->assertSame('service_metadata_scope_matched', $result['reason']);
        $this->assertSame('2026-10-14T06:59:00+00:00', $result['observed_at']);
        $this->assertArrayNotHasKey('backup', $result);
        $this->assertArrayNotHasKey('restore', $result);
        $this->assertArrayNotHasKey('running_sha', $result);
    }

    public function test_foreign_plan_or_owner_cannot_reuse_another_plans_observation(): void
    {
        $this->assertSame('unknown', $this->assess(
            $this->grant(), $this->observation(), $this->plan(99),
        )['status']);
        $this->assertSame('unknown', $this->assess(
            $this->grant(), $this->observation(), $this->plan(42, 99),
        )['status']);

        $foreignTarget = $this->target();
        $foreignTarget->plan_id = 99;
        $this->assertSame('unknown', $this->assess(
            $this->grant(), $this->observation(), null, $foreignTarget,
        )['status']);
    }

    public function test_replaced_target_or_revision_fails_closed(): void
    {
        $other = $this->target();
        $other->resource_ref = 'srv-another123';
        $this->assertSame('unknown', $this->assess(
            $this->grant(), $this->observation(), null, $other,
        )['status']);

        $changed = $this->target();
        $changed->revision = 4;
        $this->assertSame('unknown', $this->assess(
            $this->grant(), $this->observation(), null, $changed,
        )['status']);
    }

    public function test_revoked_expired_or_unverified_grants_are_not_valid(): void
    {
        foreach ([
            ['state' => 'revoked'],
            ['revoked_at' => '2026-10-14T06:58:00+00:00'],
            ['identity_verified' => false],
            ['expires_at' => '2026-10-14T07:00:00+00:00'],
            ['scopes' => ['deploy.write']],
            ['owner_id' => 8],
            ['plan_id' => 43],
            ['workspace_id' => 'tea-other123'],
            ['service_id' => 'srv-other123'],
            ['consent_epoch' => 5],
            ['target_revision' => 2],
        ] as $changes) {
            $result = $this->assess(array_replace($this->grant(), $changes), $this->observation());
            $this->assertSame('unknown', $result['status'], json_encode($changes));
            $this->assertArrayNotHasKey('observed_at', $result);
        }
    }

    public function test_wrong_receipt_account_connection_service_or_epoch_cannot_cross_scope(): void
    {
        foreach ([
            ['origin' => 'browser_form'],
            ['provider' => 'github'],
            ['kind' => 'backup'],
            ['service_exists' => false],
            ['connection_id' => 'conn-other123'],
            ['workspace_id' => 'tea-other123'],
            ['service_owner_id' => 'tea-other123'],
            ['service_id' => 'srv-other123'],
            ['target_revision' => 2],
            ['consent_epoch' => 3],
        ] as $changes) {
            $result = $this->assess($this->grant(), array_replace($this->observation(), $changes));
            $this->assertSame('unknown', $result['status'], json_encode($changes));
        }
    }

    public function test_expired_observation_is_stale_not_verified(): void
    {
        $old = array_replace($this->observation(), [
            'observed_at' => '2026-10-14T06:44:59+00:00',
        ]);
        $this->assertSame('stale', $this->assess($this->grant(), $old)['status']);
    }

    public function test_bad_or_future_timestamp_is_rejected(): void
    {
        foreach ([
            '2026-10-14T07:01:01+00:00',
            'yesterday',
            '2026-02-30T07:00:00+00:00',
            '2026-10-14',
            '2026-10-14T07:00:00',
        ] as $timestamp) {
            $result = $this->assess($this->grant(), array_replace(
                $this->observation(), ['observed_at' => $timestamp],
            ));
            $this->assertSame('unknown', $result['status'], $timestamp);
        }
    }

    public function test_unbound_or_non_render_target_is_never_promoted(): void
    {
        $other = $this->target();
        $other->provider = 'other';
        $this->assertSame('unknown', $this->assess(
            $this->grant(), $this->observation(), null, $other,
        )['status']);
    }

    public function test_operator_confirmation_alone_cannot_become_provider_observation(): void
    {
        $humanClaim = ['status' => 'operator_confirmed', 'source' => 'user'];
        $this->assertSame('unknown', $this->assess($this->grant(), $humanClaim)['status']);
    }
}
