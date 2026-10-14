<?php

namespace Tests\Unit;

use App\Models\DevelopmentOperationalProfile;
use App\Models\Plan;
use App\Models\Task;
use App\Services\DevelopmentOperationalEvidenceService;
use App\Services\DevelopmentPrelaunchPreparationService;
use PHPUnit\Framework\TestCase;

class DevelopmentOperationalProfileV3Test extends TestCase
{
    private function plan(string $title = '開発'): Plan
    {
        $p = new Plan(['title' => $title]);
        $p->id = 321;
        $p->setRelation('tasks', collect());
        return $p;
    }

    private function check(array $result, string $key): array
    {
        foreach ($result['checks'] as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }
        self::fail('Missing check: '.$key);
    }

    public function test_profile_fields_are_strictly_normalized_and_not_inferred(): void
    {
        $m = new DevelopmentOperationalProfile([
            'stage' => 'live',
            'runtime' => 'ios',
            'data_store' => 'none',
        ]);
        $this->assertSame(
            ['stage' => 'live', 'runtime' => 'ios', 'data_store' => 'none', 'data_store_revision' => 0],
            $m->summary()
        );

        $m->stage = 'unrecognized';
        $m->runtime = 'unknown';
        $m->data_store = 'unrecognized';
        $this->assertSame(
            ['stage' => 'unknown', 'runtime' => 'unknown', 'data_store' => 'unknown', 'data_store_revision' => 0],
            $m->summary()
        );
    }

    public function test_declared_no_persistence_skips_backup_and_restore_without_claiming_verification(): void
    {
        $service = new DevelopmentOperationalEvidenceService();
        $result = $service->assess($this->plan(), null, false, [
            'stage' => 'public_launch', 'runtime' => 'web', 'data_store' => 'none',
        ]);

        $this->assertSame('declared', $this->check($result, 'data')['status']);
        $this->assertSame('not_applicable', $this->check($result, 'backup')['status']);
        $this->assertSame('not_applicable', $this->check($result, 'restore')['status']);
        $this->assertSame('unknown', $this->check($result, 'environment')['status']);
        $this->assertSame('unknown', $this->check($result, 'ci')['status']);
        $this->assertSame('unknown', $this->check($result, 'deploy')['status']);
        $this->assertStringContainsString('自己申告', $this->check($result, 'restore')['source']);
        $this->assertSame('environment', $result['primary']['key']);
    }

    public function test_server_database_still_needs_independent_backup_and_restore_checks(): void
    {
        $s = new DevelopmentOperationalEvidenceService();
        $x = $s->assess($this->plan(), null, false, [
            'stage' => 'live', 'runtime' => 'hybrid', 'data_store' => 'server_db',
        ]);
        $this->assertSame('declared', $this->check($x, 'data')['status']);
        $this->assertSame('unknown', $this->check($x, 'backup')['status']);
        $this->assertSame('unknown', $this->check($x, 'restore')['status']);
        $this->assertStringContainsString('配信審査', $this->check($x, 'deploy')['detail']);
        $this->assertStringContainsString('対象DB', $this->check($x, 'data')['detail']);
    }

    public function test_local_device_requires_device_recovery_not_mysql_restore(): void
    {
        $x = (new DevelopmentOperationalEvidenceService())->assess(
            $this->plan(), null, false,
            ['stage' => 'preview', 'runtime' => 'ios', 'data_store' => 'local_device']
        );
        $this->assertSame('unknown', $this->check($x, 'backup')['status']);
        $this->assertStringContainsString('MySQLバックアップは前提にしません', $this->check($x, 'backup')['detail']);
        $this->assertStringContainsString('サーバーDB復元は前提にしません', $this->check($x, 'restore')['detail']);
    }

    public function test_unknown_profile_values_never_skip_recovery(): void
    {
        $x = (new DevelopmentOperationalEvidenceService())->assess(
            $this->plan(), null, false,
            ['stage' => 'invalid', 'runtime' => 'invalid', 'data_store' => 'not-really-none']
        );
        $this->assertSame('unknown', $this->check($x, 'data')['status']);
        $this->assertSame('unknown', $this->check($x, 'backup')['status']);
        $this->assertSame('unknown', $this->check($x, 'restore')['status']);
    }

    public function test_prototype_suppresses_nag_but_preserves_manual_opt_in(): void
    {
        $plan = $this->plan('本番公開するサービス');
        $service = new DevelopmentPrelaunchPreparationService();
        $profile = ['stage' => 'prototype'];

        $this->assertNull($service->suggest($plan, false, $profile));
        $this->assertSame('unknown', $service->suggest($plan, true, $profile)['status']);
    }

    public function test_live_stage_triggers_suggestion_without_keyword_cue(): void
    {
        $plan = $this->plan('何か作る');
        $s = new DevelopmentPrelaunchPreparationService();
        $result = $s->suggest($plan, false, ['stage' => 'live']);

        $this->assertSame('unknown', $result['status']);
        $this->assertStringContainsString('自己申告', $result['basis']);
    }
}
