<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Models\Task;
use App\Services\DevelopmentOperationalEvidenceService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class DevelopmentOperationalEvidenceServiceTest extends TestCase
{
    private DevelopmentOperationalEvidenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DevelopmentOperationalEvidenceService();
    }

    public function test_without_verified_source_everything_stays_unknown(): void
    {
        $result = $this->service->assess($this->plan(), null, false);

        $this->assertSame('environment', $result['primary']['key']);
        $this->assertCount(6, $result['checks']);
        $this->assertSame(
            ['environment', 'data', 'backup', 'restore', 'ci', 'deploy'],
            array_column($result['checks'], 'key'),
        );
        $this->assertSame(
            ['unknown'],
            array_values(array_unique(array_column($result['checks'], 'status'))),
        );
    }

    public function test_same_sha_github_success_is_only_observed_and_does_not_attest_live_or_restore(): void
    {
        $sha = str_repeat('a', 40);
        $result = $this->service->assess(
            $this->plan(),
            $this->context(13, $sha, [
                'state' => 'success', 'head_sha' => $sha,
                'occurred_at' => new DateTimeImmutable('2026-10-14T06:00:00Z'),
            ], [
                'production' => true, 'status' => 'success', 'sha' => $sha,
            ]),
            true,
        );

        $map = array_column($result['checks'], null, 'key');
        $this->assertSame('observed', $map['ci']['status']);
        $this->assertSame('observed', $map['deploy']['status']);
        $this->assertSame('unknown', $map['backup']['status']);
        $this->assertSame('unknown', $map['restore']['status']);
        $this->assertSame($sha, $map['ci']['sha']);
        $this->assertSame('2026-10-14 06:00', $map['ci']['observedAt']);
        $this->assertStringContainsString('別途確認', $map['ci']['detail']);
        $this->assertStringContainsString('独立して検証', $map['deploy']['detail']);
    }

    public function test_failed_ci_has_first_priority(): void
    {
        $sha = str_repeat('b', 40);
        $result = $this->service->assess(
            $this->plan(),
            $this->context(13, $sha, [
                'state' => 'failure', 'head_sha' => $sha,
            ]),
            true,
        );
        $this->assertSame('ci', $result['primary']['key']);
        $this->assertSame('attention', $result['primary']['status']);
    }

    public function test_mismatched_ci_cannot_inherit_old_success(): void
    {
        $result = $this->service->assess(
            $this->plan(),
            $this->context(13, str_repeat('b', 40), [
                'state' => 'success', 'head_sha' => str_repeat('a', 40),
            ]),
            true,
        );

        $this->assertSame('ci', $result['primary']['key']);
        $this->assertSame('stale', $result['primary']['status']);
    }

    public function test_staging_or_different_deploy_sha_never_proves_production(): void
    {
        $sha = str_repeat('c', 40);
        $plan = $this->plan();

        $staging = $this->service->assess($plan, $this->context(
            13, $sha, null, ['production' => false, 'status' => 'success', 'sha' => $sha],
        ), true);
        $this->assertSame('unknown', $this->byKey($staging, 'deploy')['status']);

        $different = $this->service->assess($plan, $this->context(
            13, $sha, null, ['production' => true, 'status' => 'success', 'sha' => str_repeat('d', 40)],
        ), true);
        $this->assertSame('review', $this->byKey($different, 'deploy')['status']);
        $this->assertSame('deploy', $this->byKey($different, 'deploy')['key']);
    }

    public function test_untrusted_or_cross_plan_context_is_not_reused(): void
    {
        $sha = str_repeat('e', 40);
        $context = $this->context(99, $sha, ['state' => 'success', 'head_sha' => $sha]);
        $plan = $this->plan();

        $otherPlan = $this->service->assess($plan, $context, true);
        $this->assertSame('unknown', $this->byKey($otherPlan, 'ci')['status']);

        $noCurrentPermission = $this->service->assess(
            $plan, $this->context(13, $sha, ['state' => 'success', 'head_sha' => $sha]), false
        );
        $this->assertSame('unknown', $this->byKey($noCurrentPermission, 'ci')['status']);

        $context['task']->plan_id = 13;
        $context['has_github_evidence'] = false;
        $noEvidence = $this->service->assess($plan, $context, true);
        $this->assertSame('unknown', $this->byKey($noEvidence, 'ci')['status']);
    }

    public function test_invalid_sha_is_not_trusted_for_success(): void
    {
        $result = $this->service->assess(
            $this->plan(), $this->context(13, 'main', [
                'state' => 'success', 'head_sha' => 'main',
            ]), true,
        );
        $this->assertSame('unknown', $this->byKey($result, 'ci')['status']);
    }

    private function plan(): Plan
    {
        $plan = new Plan(['title' => 'Development']);
        $plan->id = 13;
        return $plan;
    }

    private function context(int $planId, string $headSha, ?array $ci, ?array $deploy = null): array
    {
        $task = new Task(['title' => '公開準備']);
        $task->plan_id = $planId;
        return [
            'task' => $task,
            'has_github_evidence' => true,
            'pull_request' => ['number' => 12, 'head_sha' => $headSha],
            'ci' => $ci,
            'deployment' => $deploy,
        ];
    }

    private function byKey(array $result, string $key): array
    {
        foreach ($result['checks'] as $check) {
            if ($check['key'] === $key) {
                return $check;
            }
        }
        self::fail('Missing evidence dimension '.$key);
    }
}
