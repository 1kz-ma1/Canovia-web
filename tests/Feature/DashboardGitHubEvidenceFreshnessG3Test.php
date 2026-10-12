<?php

namespace Tests\Feature;

use App\Data\UserBehaviorBaselineData;
use App\Data\UserStateData;
use App\Enums\EvidenceSource;
use App\Enums\ProductKey;
use App\Enums\UserBehaviorState;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\DashboardPresentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A current Dashboard/Situation snapshot must not upgrade retained GitHub
 * history into a fresh signal after disconnect, reconnect or source expiry.
 */
final class DashboardGitHubEvidenceFreshnessG3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled',
        ]);
    }

    public function test_dashboard_hides_revoked_and_cross_epoch_github_evidence_but_keeps_native_signals(): void
    {
        [$user, $plan, $task, $root] = $this->scenario();
        $native = $this->evidence($task, 'artifact_state_observed', EvidenceSource::Native, [
            'version_label' => 'reviewed-by-human',
        ], now()->subHour());

        $github = [];
        // More revoked rows than the old eight-row eager loading limit.
        // Native evidence must still be discovered when GitHub is disconnected.
        foreach (range(1, 10) as $n) {
            $github[] = $this->evidence($task, 'pull_request_ci_observed', EvidenceSource::GitHub, [
                'repo_full_name' => '1kz-ma1/Canovia-web',
                'github_repository_artifact_id' => (int) $root->id,
                'github_source_epoch' => data_get($root->metadata, 'github_app_connection.source_epoch'),
                'pull_request_number' => $n,
                'ci_state' => 'success',
            ], now()->subMinutes($n));
        }

        $active = $this->dashboard($user, $plan);
        $this->assertCount(3, $active['recent_evidence']);
        $this->assertSame('GitHub', $active['recent_evidence']->first()['source_label']);
        $this->assertSame(3, $active['situation']['recent_evidence_count']);

        $meta = (array) $root->metadata;
        $meta['github_app_connection']['status'] = 'revoked';
        $root->update(['metadata' => $meta]);

        $revoked = $this->dashboard($user, $plan);
        $this->assertSame([$native->id], $revoked['recent_evidence']->pluck('id')->all());
        $this->assertSame(1, $revoked['situation']['recent_evidence_count']);
        $this->assertTrue($revoked['situation']['delivery_has_artifact_evidence']);

        // Same installation number does not authorize older source epochs.
        $meta['github_app_connection']['status'] = 'connected';
        $meta['github_app_connection']['source_epoch'] = (string) Str::uuid();
        $root->update(['metadata' => $meta]);
        $reconnected = $this->dashboard($user, $plan);
        $this->assertSame([$native->id], $reconnected['recent_evidence']->pluck('id')->all());

        // Only an individually re-fetched/re-signed GitHub row becomes current.
        $refetched = $github[0];
        $refetchedMeta = (array) $refetched->metadata;
        $refetchedMeta['github_source_epoch'] = $meta['github_app_connection']['source_epoch'];
        $refetched->update(['metadata' => $refetchedMeta]);

        $unsigned = $this->evidence($task, 'github_issue_observed', EvidenceSource::GitHub, [
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'github_repository_artifact_id' => (int) $root->id,
            'issue_number' => 300,
        ], now()->addMinute());

        $verified = $this->dashboard($user, $plan);
        $this->assertSame([$refetched->id, $native->id], $verified['recent_evidence']->pluck('id')->all());
        $this->assertSame(2, $verified['situation']['recent_evidence_count']);
        $this->assertDatabaseHas('task_evidences', ['id' => $unsigned->id]);
        $this->assertDatabaseHas('task_evidences', ['id' => $github[9]->id]);
        $this->assertDatabaseHas('task_evidences', ['id' => $native->id]);
    }

    private function dashboard(User $user, Plan $plan): array
    {
        $plan = $plan->fresh()->load(['tasks', 'artifacts', 'resources', 'workLogs']);
        $baseline = new UserBehaviorBaselineData(0, 0, 9, 0.0, 0.0, 0.0, 0, 0.0);
        $state = new UserStateData(50, 50, 50, 50, UserBehaviorState::Normal, [], 0.0);
        $result = app(DashboardPresentationService::class)->build(
            collect([$plan]),
            'user:'.$user->id,
            $baseline,
            $state,
            editablePlanIds: [$plan->id],
            actor: $user,
            workSessionContext: ['pending_plan_updates' => collect(), 'active_work_session' => null],
        );

        return $result['plan_tabs']->first();
    }

    private function scenario(): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        UserProductGrant::query()->create([
            'user_id' => $user->id,
            'product_key' => ProductKey::AllAccess,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
        ]);
        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Dashboard source freshness',
            'description' => 'GitHub and native evidence',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Inspect release status',
            'description' => 'Review current evidence',
            'status' => 'doing',
            'progress_percent' => 20,
            'priority' => 1,
            'activation_cost' => 2,
            'estimated_minutes' => 60,
            'remaining_minutes' => 45,
            'sort_order' => 1,
        ]);
        $root = PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'github',
            'artifact_type' => 'repository',
            'title' => 'Canovia-web',
            'url' => 'https://github.com/1kz-ma1/Canovia-web',
            'metadata' => ['github_app_connection' => [
                'status' => 'connected',
                'installation_id' => 777,
                'source_epoch' => '278734cc-02a1-4c4d-889d-0b58afe0e4cd',
            ]],
        ]);

        return [$user, $plan, $task, $root];
    }

    private function evidence(Task $task, string $type, EvidenceSource $source, array $metadata, \DateTimeInterface $when): TaskEvidence
    {
        return TaskEvidence::query()->create([
            'plan_id' => $task->plan_id,
            'task_id' => $task->id,
            'user_id' => $task->plan->user_id,
            'source' => $source->value,
            'type' => $type,
            'external_key' => $source->value.':'.Str::uuid(),
            'confidence' => 1.0,
            'occurred_at' => $when,
            'metadata' => $metadata,
        ]);
    }
}
