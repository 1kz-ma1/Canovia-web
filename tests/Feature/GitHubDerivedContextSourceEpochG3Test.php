<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Enums\ProductKey;
use App\Models\FutureMemo;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\CompanionContextService;
use App\Services\ExecutionOrchestrationContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GitHub-derived facts remain historical when the source is no longer
 * currently verifiable. User-authored memories and native evidence survive.
 */
final class GitHubDerivedContextSourceEpochG3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled',
        ]);
    }

    public function test_companion_ignores_unsigned_revoked_and_cross_epoch_evidence_but_keeps_native_history(): void
    {
        [$user, $plan, $task, $root] = $this->scenario();
        $old = $this->evidence($task, 'github_issue_observed', [
            'issue_number' => 91, 'issue_state' => 'closed',
        ]);
        $native = TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native->value,
            'type' => 'guided_execution_reflected',
            'external_key' => 'native:g3:one',
            'occurred_at' => now()->subMinute(),
            'metadata' => [
                'intent' => '本人確認',
                'actual_outcome' => '人が実際に確認した結果',
            ],
        ]);

        FutureMemo::query()->create([
            'user_id' => $user->id,
            'kind' => 'note',
            'category' => 'other',
            'content' => '本人が明示したメモ',
            'source' => 'manual',
            'use_for_ai' => true,
            'sort_order' => 1,
        ]);

        $service = app(CompanionContextService::class);
        $active = $service->snapshot($user, $plan, $task, ['entry_type' => 'guided_execution']);
        $this->assertSame(2, count($active['recent_evidence']));
        $this->assertSame('github_issue_observed', data_get($active, 'entry.latest_evidence.type'));
        $this->assertSame('本人が明示したメモ', data_get($active, 'memory.0.content'));

        $metadata = (array) $root->metadata;
        $metadata['github_app_connection']['status'] = 'revoked';
        $root->update(['metadata' => $metadata]);
        $revoked = $service->snapshot($user, $plan, $task, ['entry_type' => 'guided_execution']);
        $this->assertCount(1, $revoked['recent_evidence']);
        $this->assertSame('guided_execution_reflected', data_get($revoked, 'entry.latest_evidence.type'));
        $this->assertSame('本人が明示したメモ', data_get($revoked, 'memory.0.content'));

        $metadata['github_app_connection']['status'] = 'connected';
        $metadata['github_app_connection']['source_epoch'] = (string) Str::uuid();
        $root->update(['metadata' => $metadata]);
        $reconnected = $service->snapshot($user, $plan, $task);
        $this->assertCount(1, $reconnected['recent_evidence']);
        $this->assertSame('guided_execution_reflected', $reconnected['recent_evidence'][0]['type']);
        $this->assertDatabaseHas('task_evidences', ['id' => $old->id]);
        $this->assertDatabaseHas('task_evidences', ['id' => $native->id]);

        // Re-reading from an authoritative GitHub provider is what permits
        // a fresh epoch, not merely toggling the connection to connected.
        $freshMeta = (array) $old->metadata;
        $freshMeta['github_source_epoch'] = $metadata['github_app_connection']['source_epoch'];
        $old->update(['metadata' => $freshMeta]);
        $refetched = $service->snapshot($user, $plan, $task);
        $this->assertCount(2, $refetched['recent_evidence']);

        $legacy = $this->evidence($task, 'github_commit_observed', [
            'commit_sha' => str_repeat('a', 40),
        ]);
        $unsigned = (array) $legacy->metadata;
        unset($unsigned['github_source_epoch']);
        $legacy->update(['metadata' => $unsigned]);
        $history = $service->snapshot($user, $plan, $task);
        $this->assertCount(2, $history['recent_evidence']);
        $this->assertDatabaseHas('task_evidences', ['id' => $legacy->id]);
    }

    public function test_execution_context_and_packet_fingerprint_change_on_revoke_and_reconnect(): void
    {
        [$user, $plan, $task, $root] = $this->scenario();
        $evidence = $this->evidence($task, 'pull_request_ci_observed', [
            'pull_request_number' => 44, 'ci_state' => 'failure',
        ]);

        $service = app(ExecutionOrchestrationContextService::class);
        $active = $this->executionSnapshot($service, $user, $plan, $task);
        $this->assertSame('pull_request_ci_observed', $active['recent_evidence'][0]['type']);
        $this->assertNotEmpty($active['github_source_lineage_hash']);

        $metadata = (array) $root->metadata;
        $metadata['github_app_connection']['status'] = 'revoked';
        $root->update(['metadata' => $metadata]);
        $revoked = $this->executionSnapshot($service, $user, $plan, $task);
        $this->assertSame([], $revoked['recent_evidence']);
        $this->assertNotSame($active['context_fingerprint'], $revoked['context_fingerprint']);

        $metadata['github_app_connection']['status'] = 'connected';
        $metadata['github_app_connection']['source_epoch'] = (string) Str::uuid();
        $root->update(['metadata' => $metadata]);
        $reconnected = $this->executionSnapshot($service, $user, $plan, $task);
        $this->assertSame([], $reconnected['recent_evidence']);
        $this->assertNotSame($active['context_fingerprint'], $reconnected['context_fingerprint']);
        $this->assertNotSame($revoked['context_fingerprint'], $reconnected['context_fingerprint']);

        $refresh = (array) $evidence->metadata;
        $refresh['github_source_epoch'] = $metadata['github_app_connection']['source_epoch'];
        $evidence->update(['metadata' => $refresh]);
        $verified = $this->executionSnapshot($service, $user, $plan, $task);
        $this->assertCount(1, $verified['recent_evidence']);
        $this->assertNotSame($reconnected['context_fingerprint'], $verified['context_fingerprint']);
        $this->assertSame('failure', $verified['recent_evidence'][0]['type'] === 'pull_request_ci_observed'
            ? 'failure' : 'unexpected');
    }

    public function test_no_github_repository_keeps_non_github_context_intact(): void
    {
        [$user, $plan, $task, $root] = $this->scenario();
        $root->delete();
        $native = TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native->value,
            'type' => 'guided_execution_reflected',
            'external_key' => 'native:g3:two',
            'occurred_at' => now(),
            'metadata' => ['intent' => '手動の実績'],
        ]);

        $companion = app(CompanionContextService::class)->snapshot($user, $plan, $task);
        $execution = $this->executionSnapshot(app(ExecutionOrchestrationContextService::class), $user, $plan, $task);
        $this->assertSame('guided_execution_reflected', $companion['recent_evidence'][0]['type']);
        $this->assertSame('guided_execution_reflected', $execution['recent_evidence'][0]['type']);
        $this->assertDatabaseHas('task_evidences', ['id' => $native->id]);
    }

    private function executionSnapshot(ExecutionOrchestrationContextService $service, User $user, Plan $plan, Task $task): array
    {
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);

        return $service->snapshot($request, $plan->fresh(), $task->fresh());
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
            'title' => 'Canovia Development',
            'description' => 'GitHub source lineage',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Review GitHub signals',
            'description' => 'release state',
            'status' => 'doing',
            'progress_percent' => 30,
            'priority' => 1,
            'activation_cost' => 2,
            'estimated_minutes' => 120,
            'remaining_minutes' => 70,
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

    private function evidence(Task $task, string $type, array $facts): TaskEvidence
    {
        $root = PlanArtifact::query()->where('plan_id', $task->plan_id)
            ->where('artifact_type', 'repository')->firstOrFail();

        return TaskEvidence::query()->create([
            'plan_id' => $task->plan_id,
            'task_id' => $task->id,
            'user_id' => $task->plan?->user_id,
            'source' => EvidenceSource::GitHub->value,
            'type' => $type,
            'external_key' => $type.':'.Str::uuid(),
            'occurred_at' => now(),
            'confidence' => 1,
            'metadata' => [
                ...$facts,
                'repo_full_name' => '1kz-ma1/Canovia-web',
                'github_repository_artifact_id' => (int) $root->id,
                'github_source_epoch' => data_get($root->metadata, 'github_app_connection.source_epoch'),
            ],
        ]);
    }
}
