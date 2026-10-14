<?php

namespace Tests\Unit;

use App\Models\DevelopmentOperationalTarget;
use App\Models\Plan;
use App\Services\DevelopmentOperationalAttestationService;
use App\Services\DevelopmentOperationalOperatorReadinessService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class DevelopmentOperationalOperatorReadinessV54Test extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(int $id = 42, int $owner = 7): Plan
    {
        $plan = new Plan(['title' => 'Development']);
        $plan->id = $id;
        $plan->user_id = $owner;
        return $plan;
    }

    private function target(): DevelopmentOperationalTarget
    {
        return new DevelopmentOperationalTarget([
            'plan_id' => 42,
            'provider' => 'render',
            'environment' => 'production',
            'resource_ref' => 'srv-safe12345',
            'revision' => 1,
        ]);
    }

    private function summarize(?array $profile, ?DevelopmentOperationalTarget $target, ?Plan $plan = null): ?array
    {
        return (new DevelopmentOperationalOperatorReadinessService(
            new DevelopmentOperationalAttestationService(),
        ))->summarize($plan ?? $this->plan(), $profile, $target);
    }

    private function checkStatus(array $summary, string $key): string
    {
        foreach ($summary['checks'] as $check) {
            if ($check['key'] === $key) {
                return $check['status'];
            }
        }
        self::fail('Missing summary check: '.$key);
    }

    public function test_unknown_storage_must_be_classified_before_recovery_checks(): void
    {
        $summary = $this->summarize(null, $this->target());
        $this->assertCount(3, $summary['checks']);
        $this->assertSame('storage', $summary['next']['key']);
        $this->assertSame('unknown', $this->checkStatus($summary, 'service'));
        $this->assertSame('unknown', $this->checkStatus($summary, 'backup'));
        $this->assertSame('unknown', $this->checkStatus($summary, 'restore'));
        $this->assertStringContainsString('自己申告', $summary['basis']);
    }

    public function test_no_persistent_data_excludes_backup_and_restore_without_fake_proof(): void
    {
        $summary = $this->summarize(['data_store' => 'none'], $this->target());
        $this->assertSame('service', $summary['next']['key']);
        $this->assertSame('not_applicable', $this->checkStatus($summary, 'backup'));
        $this->assertSame('not_applicable', $this->checkStatus($summary, 'restore'));
    }

    public function test_guidance_advances_from_service_to_backup_to_restore_without_release_go(): void
    {
        Carbon::setTestNow('2026-10-14 18:00:00');
        $target = $this->target();
        $profile = ['data_store' => 'server_db', 'data_store_revision' => 1];
        $this->assertSame('service', $this->summarize($profile, $target)['next']['key']);

        $target->service_checked_at = Carbon::now();
        $target->service_checked_revision = 1;
        $target->service_checked_environment = 'production';
        $this->assertSame('backup', $this->summarize($profile, $target)['next']['key']);

        $target->backup_confirmed_at = Carbon::now();
        $target->backup_revision = 1;
        $target->backup_store = 'server_db';
        $target->backup_store_revision = 1;
        $this->assertSame('restore', $this->summarize($profile, $target)['next']['key']);

        $target->restore_confirmed_at = Carbon::now();
        $target->restore_revision = 1;
        $target->restore_store = 'server_db';
        $target->restore_store_revision = 1;
        $summary = $this->summarize($profile, $target);
        $this->assertSame('release_review', $summary['next']['key']);
        $this->assertSame('operator_confirmed', $this->checkStatus($summary, 'service'));
        $this->assertSame('operator_confirmed', $this->checkStatus($summary, 'backup'));
        $this->assertSame('operator_confirmed', $this->checkStatus($summary, 'restore'));
        $this->assertStringContainsString('独立検証', $summary['next']['detail']);
        $this->assertArrayNotHasKey('release_approved', $summary);
        $this->assertArrayNotHasKey('verified', $summary);
    }

    public function test_stale_and_changed_store_propagate_without_reviving_old_claims(): void
    {
        Carbon::setTestNow('2026-10-14 18:00:00');
        $target = $this->target();
        $target->service_checked_at = Carbon::now()->subDays(8);
        $target->service_checked_revision = 1;
        $target->service_checked_environment = 'production';
        $target->backup_confirmed_at = Carbon::now();
        $target->backup_revision = 1;
        $target->backup_store = 'server_db';
        $target->backup_store_revision = 1;

        $summary = $this->summarize(['data_store' => 'server_db', 'data_store_revision' => 1], $target);
        $this->assertSame('stale', $this->checkStatus($summary, 'service'));
        $this->assertSame('service', $summary['next']['key']);

        $target->service_checked_at = Carbon::now();
        $target->revision = 2;
        $summary = $this->summarize(['data_store' => 'server_db', 'data_store_revision' => 1], $target);
        $this->assertSame('stale', $this->checkStatus($summary, 'service'));
        $this->assertSame('stale', $this->checkStatus($summary, 'backup'));

        $target->service_checked_revision = 2;
        $target->backup_revision = 2;
        $summary = $this->summarize(['data_store' => 'cloud_files', 'data_store_revision' => 1], $target);
        $this->assertSame('stale', $this->checkStatus($summary, 'backup'));
        $this->assertSame('backup', $summary['next']['key']);
    }

    public function test_wrong_plan_or_untrusted_render_target_cannot_be_summarized(): void
    {
        $this->assertNull($this->summarize(null, null));
        $this->assertNull($this->summarize(null, $this->target(), $this->plan(99)));
        $this->assertNull($this->summarize(null, $this->target(), $this->plan(42, 0)));
        $other = $this->target();
        $other->provider = 'other';
        $this->assertNull($this->summarize(['data_store' => 'server_db', 'data_store_revision' => 1], $other));
        $other->provider = 'render';
        $other->resource_ref = 'https://example.com/secret';
        $this->assertNull($this->summarize(['data_store' => 'server_db', 'data_store_revision' => 1], $other));
    }

    public function test_future_dated_v4_recovery_claim_is_stale_in_summary(): void
    {
        Carbon::setTestNow('2026-10-14 18:00:00');
        $target = $this->target();
        $target->backup_confirmed_at = Carbon::now()->addHours(2);
        $target->backup_revision = 1;
        $target->backup_store = 'server_db';
        $target->backup_store_revision = 1;
        $summary = $this->summarize(['data_store' => 'server_db', 'data_store_revision' => 1], $target);
        $this->assertSame('stale', $this->checkStatus($summary, 'backup'));
    }
}
