<?php

namespace Tests\Unit;

use App\Models\DevelopmentOperationalTarget;
use App\Models\Plan;
use App\Services\DevelopmentOperationalAttestationService;
use App\Services\DevelopmentOperationalEvidenceService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class DevelopmentOperationalAttestationV4Test extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(int $id = 42): Plan
    {
        $plan = new Plan(['title' => 'Development']);
        $plan->id = $id;
        return $plan;
    }

    private function target(): DevelopmentOperationalTarget
    {
        return new DevelopmentOperationalTarget([
            'plan_id' => 42,
            'provider' => 'render',
            'environment' => 'production',
            'resource_ref' => 'srv-sandbox123',
            'revision' => 1,
        ]);
    }

    private function check(array $result, string $key): array
    {
        foreach ($result['checks'] as $check) {
            if ($check['key'] === $key) {
                return $check;
            }
        }
        self::fail('Missing check '.$key);
    }

    public function test_absent_or_cross_plan_target_never_attests_environment(): void
    {
        $s = new DevelopmentOperationalAttestationService();
        $profile = ['data_store' => 'server_db'];
        $this->assertSame([], $s->project($this->plan(), $profile, null));
        $this->assertSame([], $s->project($this->plan(99), $profile, $this->target()));

        $good = $s->project($this->plan(), $profile, $this->target());
        $this->assertSame('declared', $good['environment']['status']);
        $this->assertArrayNotHasKey('backup', $good);
        $this->assertArrayNotHasKey('restore', $good);
    }

    public function test_backup_and_restore_are_distinct_owner_confirmations_not_provider_verification(): void
    {
        Carbon::setTestNow('2026-10-14 08:00:00');
        $target = $this->target();
        $target->backup_confirmed_at = Carbon::now()->subHours(2);
        $target->backup_revision = 1;
        $target->backup_store = 'server_db';

        $projection = (new DevelopmentOperationalAttestationService())
            ->project($this->plan(), ['data_store' => 'server_db'], $target);

        $this->assertSame('operator_confirmed', $projection['backup']['status']);
        $this->assertArrayNotHasKey('restore', $projection);
        $this->assertStringContainsString('独立検証なし', $projection['backup']['source']);

        $result = (new DevelopmentOperationalEvidenceService())->assess(
            $this->plan(), null, false, ['data_store' => 'server_db'], $projection,
        );
        $this->assertSame('operator_confirmed', $this->check($result, 'backup')['status']);
        $this->assertSame('unknown', $this->check($result, 'restore')['status']);
        $this->assertSame('declared', $this->check($result, 'environment')['status']);
        $this->assertSame('unknown', $this->check($result, 'ci')['status']);
        $this->assertSame('unknown', $this->check($result, 'deploy')['status']);
    }

    public function test_changed_target_revision_or_data_store_invalidates_prior_confirmation(): void
    {
        Carbon::setTestNow('2026-10-14 08:00:00');
        $target = $this->target();
        $target->restore_confirmed_at = Carbon::now();
        $target->restore_revision = 1;
        $target->restore_store = 'server_db';

        $target->revision = 2;
        $stale = (new DevelopmentOperationalAttestationService())
            ->project($this->plan(), ['data_store' => 'server_db'], $target);
        $this->assertSame('stale', $stale['restore']['status']);

        $target->revision = 1;
        $changedStore = (new DevelopmentOperationalAttestationService())
            ->project($this->plan(), ['data_store' => 'cloud_files'], $target);
        $this->assertSame('stale', $changedStore['restore']['status']);
    }

    public function test_old_confirmation_expires_without_becoming_verified(): void
    {
        Carbon::setTestNow('2026-10-14 08:00:00');
        $target = $this->target();
        $target->backup_confirmed_at = Carbon::now()->subDays(8);
        $target->backup_revision = 1;
        $target->backup_store = 'server_db';
        $target->restore_confirmed_at = Carbon::now()->subDays(91);
        $target->restore_revision = 1;
        $target->restore_store = 'server_db';

        $evidence = (new DevelopmentOperationalAttestationService())
            ->project($this->plan(), ['data_store' => 'server_db'], $target);
        $this->assertSame('stale', $evidence['backup']['status']);
        $this->assertSame('stale', $evidence['restore']['status']);

        $result = (new DevelopmentOperationalEvidenceService())->assess(
            $this->plan(), null, false, ['data_store' => 'server_db'], $evidence
        );
        $this->assertSame('backup', $result['primary']['key']);
        $this->assertSame('stale', $this->check($result, 'backup')['status']);
    }

    public function test_manual_service_check_is_independent_of_release_and_backup_proof(): void
    {
        Carbon::setTestNow('2026-10-14 18:00:00');
        $plan = $this->plan();
        $plan->user_id = 7;
        $target = $this->target();
        $target->service_checked_at = Carbon::now()->subHour();
        $target->service_checked_revision = 1;
        $target->service_checked_environment = 'production';

        $attestation = new DevelopmentOperationalAttestationService();
        $manual = $attestation->projectServiceIdentity($plan, $target);
        $this->assertSame('operator_confirmed', $manual['status']);
        $this->assertStringContainsString('外部API検証なし', $manual['source']);
        $this->assertArrayNotHasKey('verified', $manual);

        $v4 = $attestation->project($plan, ['data_store' => 'server_db'], $target);
        $this->assertSame('declared', $v4['environment']['status']);
        $this->assertArrayNotHasKey('service_identity', $v4);
        $result = (new DevelopmentOperationalEvidenceService())->assess(
            $plan, null, false, ['data_store' => 'server_db'], $v4,
        );
        $this->assertSame('unknown', $this->check($result, 'deploy')['status']);
        $this->assertSame('unknown', $this->check($result, 'ci')['status']);
        $this->assertSame('unknown', $this->check($result, 'backup')['status']);
        $this->assertSame('unknown', $this->check($result, 'restore')['status']);
        $this->assertSame('declared', $this->check($result, 'environment')['status']);
    }

    public function test_manual_service_confirmation_stales_on_change_age_and_future_timestamp(): void
    {
        Carbon::setTestNow('2026-10-14 18:00:00');
        $plan = $this->plan();
        $plan->user_id = 7;
        $target = $this->target();
        $s = new DevelopmentOperationalAttestationService();
        $this->assertSame('unknown', $s->projectServiceIdentity($plan, $target)['status']);

        $target->service_checked_at = Carbon::now()->subHours(2);
        $target->service_checked_revision = 1;
        $target->service_checked_environment = 'production';
        $this->assertSame('operator_confirmed', $s->projectServiceIdentity($plan, $target)['status']);

        $target->revision = 2;
        $this->assertSame('stale', $s->projectServiceIdentity($plan, $target)['status']);
        $target->revision = 1;
        $target->environment = 'staging';
        $this->assertSame('stale', $s->projectServiceIdentity($plan, $target)['status']);
        $target->environment = 'production';
        $target->service_checked_at = Carbon::now()->subDays(8);
        $this->assertSame('stale', $s->projectServiceIdentity($plan, $target)['status']);
        $target->service_checked_at = Carbon::now()->addMinutes(5);
        $this->assertSame('stale', $s->projectServiceIdentity($plan, $target)['status']);
    }

    public function test_service_check_projection_requires_owned_exact_render_target(): void
    {
        $plan = $this->plan();
        $plan->user_id = 7;
        $target = $this->target();
        $s = new DevelopmentOperationalAttestationService();
        $this->assertNull($s->projectServiceIdentity($plan, null));
        $this->assertNull($s->projectServiceIdentity($this->plan(88), $target));

        $target->provider = 'other';
        $this->assertNull($s->projectServiceIdentity($plan, $target));
        $target->provider = 'render';
        $target->resource_ref = 'https://attacker.test';
        $this->assertNull($s->projectServiceIdentity($plan, $target));
        $target->resource_ref = 'srv-safe000';
        $plan->user_id = 0;
        $this->assertNull($s->projectServiceIdentity($plan, $target));
    }

    public function test_no_persistent_data_is_never_upgraded_to_verified_recovery(): void
    {
        Carbon::setTestNow('2026-10-14 08:00:00');
        $target = $this->target();
        $target->backup_confirmed_at = Carbon::now();
        $target->backup_revision = 1;
        $target->backup_store = 'server_db';

        $evidence = (new DevelopmentOperationalAttestationService())
            ->project($this->plan(), ['data_store' => 'none'], $target);
        $this->assertArrayNotHasKey('backup', $evidence);

        $result = (new DevelopmentOperationalEvidenceService())->assess(
            $this->plan(), null, false, ['data_store' => 'none'], $evidence
        );
        $this->assertSame('not_applicable', $this->check($result, 'backup')['status']);
        $this->assertSame('not_applicable', $this->check($result, 'restore')['status']);
    }
}
