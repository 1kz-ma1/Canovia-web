<?php

namespace Tests\Unit;

use App\Models\DevelopmentOperationalTarget;
use App\Models\Plan;
use App\Services\DevelopmentOperationalOperatorHandoffService;
use Tests\TestCase;

final class DevelopmentOperationalOperatorHandoffV52Test extends TestCase
{
    private function plan(int $id = 42, int $owner = 7): Plan
    {
        $plan = new Plan(['title' => 'Release preparation']);
        $plan->id = $id;
        $plan->user_id = $owner;

        return $plan;
    }

    private function target(string $provider = 'render'): DevelopmentOperationalTarget
    {
        return new DevelopmentOperationalTarget([
            'plan_id' => 42,
            'provider' => $provider,
            'environment' => 'production',
            'resource_ref' => 'srv-manual123',
            'revision' => 1,
        ]);
    }

    private function handoff(?DevelopmentOperationalTarget $target, ?array $profile = null, ?Plan $plan = null): ?array
    {
        return (new DevelopmentOperationalOperatorHandoffService())->build(
            $plan ?? $this->plan(), $target, $profile,
        );
    }

    private function keys(array $handoff): array
    {
        return array_column($handoff['steps'], 'key');
    }

    public function test_render_target_provides_no_credentials_and_no_automatic_evidence(): void
    {
        $result = $this->handoff($this->target(), ['data_store' => 'server_db']);
        $this->assertNotNull($result);
        $this->assertSame('srv-manual123', $result['resource_ref']);
        $this->assertSame('production', $result['environment']);
        $this->assertSame(
            ['service_identity', 'environment', 'deployment', 'backup', 'restore'],
            $this->keys($result),
        );
        $this->assertArrayNotHasKey('observed_at', $result);
        $this->assertArrayNotHasKey('verified', $result);
        $this->assertArrayNotHasKey('api_key', $result);
        $this->assertArrayNotHasKey('url', $result);
    }

    public function test_nonpersistent_store_has_no_forced_backup_or_restore(): void
    {
        $result = $this->handoff($this->target(), ['data_store' => 'none']);
        $this->assertCount(3, $result['steps']);
        $this->assertNotContains('backup', $this->keys($result));
        $this->assertNotContains('restore', $this->keys($result));
    }

    public function test_recovery_guidance_matches_reported_storage(): void
    {
        foreach ([
            'mixed' => ['backup', 'restore'],
            'local_device' => ['device_recovery'],
            'cloud_files' => ['file_recovery'],
            'unknown' => ['classify_store'],
        ] as $store => $expected) {
            $result = $this->handoff($this->target(), ['data_store' => $store]);
            $this->assertSame($expected, array_slice($this->keys($result), 3), $store);
        }
        $result = $this->handoff($this->target(), null);
        $this->assertSame(['classify_store'], array_slice($this->keys($result), 3));
    }

    public function test_missing_or_unrelated_or_invalid_targets_never_leak_other_plan_service(): void
    {
        $this->assertNull($this->handoff(null));
        $this->assertNull($this->handoff($this->target(), null, $this->plan(99)));
        $this->assertNull($this->handoff($this->target(), null, $this->plan(42, 0)));
        $this->assertNull($this->handoff($this->target('other')));

        $changed = $this->target();
        $changed->resource_ref = 'https://example.com/?token=secret';
        $this->assertNull($this->handoff($changed));

        $changed = $this->target();
        $changed->environment = 'unexpected';
        $this->assertNull($this->handoff($changed));

        $changed = $this->target();
        $changed->revision = 0;
        $this->assertNull($this->handoff($changed));
    }
}
