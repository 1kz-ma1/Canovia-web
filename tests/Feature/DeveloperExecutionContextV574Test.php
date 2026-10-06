<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Intelligence\Data\ActionProposal;
use App\Intelligence\Data\Confidence;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Services\DevelopmentExecutionContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeveloperExecutionContextV574Test extends TestCase
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

    public function test_context_is_task_scoped_and_projects_repository_pr_ci_review_and_deploy(): void
    {
        [, $plan, $task, $otherTask] = $this->scenario();

        $pr = $this->artifact(
            $plan,
            $task,
            'PR #262',
            'https://github.com/1kz-ma1/Canovia-web/pull/262',
        );

        $this->evidence($task, 'github_commit_observed', [
            'plan_artifact_id' => $pr->id,
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'commit_sha' => str_repeat('a', 40),
            'branch' => 'feature/v57-4-developer-execution-context',
            'verified' => true,
        ], now()->subMinutes(5));

        $this->evidence($task, 'pull_request_observed', [
            'plan_artifact_id' => $pr->id,
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'pull_request_number' => 262,
            'pull_request_url' => $pr->url,
            'state' => 'open',
            'draft' => false,
            'merged' => false,
            'head_sha' => str_repeat('a', 40),
            'head_ref' => 'feature/v57-4-developer-execution-context',
            'base_ref' => 'main',
        ], now()->subMinutes(4));

        $this->evidence($task, 'pull_request_ci_observed', [
            'plan_artifact_id' => $pr->id,
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'pull_request_number' => 262,
            'pull_request_url' => $pr->url,
            'head_sha' => str_repeat('a', 40),
            'ci_state' => 'failure',
        ], now()->subMinutes(3));

        $this->evidence($task, 'pull_request_review_submitted', [
            'plan_artifact_id' => $pr->id,
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'pull_request_number' => 262,
            'pull_request_url' => $pr->url,
            'review_id' => 80,
            'review_state' => 'CHANGES_REQUESTED',
            'reviewer' => 'reviewer-a',
        ], now()->subMinutes(2));

        $this->evidence($task, 'github_deployment_observed', [
            'plan_artifact_id' => $pr->id,
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'deployment_id' => 991,
            'deployment_sha' => str_repeat('a', 40),
            'environment' => 'preview',
            'deployment_status' => 'success',
            'production_environment' => false,
        ], now()->subMinute());

        $this->evidence($otherTask, 'pull_request_observed', [
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'pull_request_number' => 999,
            'pull_request_url' => 'https://github.com/1kz-ma1/Canovia-web/pull/999',
            'state' => 'open',
            'head_ref' => 'feature/other-task',
        ], now());

        $action = new ActionProposal(
            kind: 'development_fix_ci',
            title: '失敗しているCIを直す',
            intent: 'CI failureを解消する。',
            confidence: Confidence::deterministic(),
            successSignals: ['CI state = success'],
            metadata: [
                'target_task_id' => $task->id,
                'route_kind' => 'github_workflow',
            ],
        );

        $context = app(DevelopmentExecutionContextService::class)
            ->build($plan, $task->id, $action);

        $this->assertNotNull($context);
        $this->assertSame($task->id, $context['task']->id);
        $this->assertSame('1kz-ma1/Canovia-web', $context['repository']);
        $this->assertSame(
            'feature/v57-4-developer-execution-context',
            data_get($context, 'branch.name'),
        );
        $this->assertSame(262, data_get($context, 'pull_request.number'));
        $this->assertSame('open', data_get($context, 'pull_request.state'));
        $this->assertSame('failure', data_get($context, 'ci.state'));
        $this->assertSame(
            'CHANGES_REQUESTED',
            data_get($context, 'review.state'),
        );
        $this->assertSame(
            'reviewer-a',
            data_get($context, 'review.reviewer'),
        );
        $this->assertSame(
            'preview',
            data_get($context, 'deployment.environment'),
        );
        $this->assertSame(
            ['CI state = success'],
            data_get($context, 'handoff.done_when'),
        );
        $this->assertStringContainsString(
            'CI success Evidence',
            (string) data_get($context, 'handoff.evidence_hint'),
        );

        $serialized = json_encode($context, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('/pull/999', $serialized);
        $this->assertStringNotContainsString('feature/other-task', $serialized);
    }

    public function test_developer_home_renders_action_context_without_calling_github(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $pr = $this->artifact(
            $plan,
            $task,
            'PR #262',
            'https://github.com/1kz-ma1/Canovia-web/pull/262',
        );

        $sha = str_repeat('b', 40);

        $this->evidence($task, 'github_commit_observed', [
            'plan_artifact_id' => $pr->id,
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'commit_sha' => $sha,
            'branch' => 'feature/v57-4-developer-execution-context',
            'verified' => true,
        ], now()->subMinutes(3));

        $this->evidence($task, 'pull_request_observed', [
            'plan_artifact_id' => $pr->id,
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'pull_request_number' => 262,
            'pull_request_url' => $pr->url,
            'state' => 'open',
            'draft' => false,
            'merged' => false,
            'head_sha' => $sha,
            'head_ref' => 'feature/v57-4-developer-execution-context',
            'base_ref' => 'main',
        ], now()->subMinutes(2));

        $this->evidence($task, 'pull_request_ci_observed', [
            'plan_artifact_id' => $pr->id,
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'pull_request_number' => 262,
            'pull_request_url' => $pr->url,
            'head_sha' => $sha,
            'ci_state' => 'failure',
        ], now()->subMinute());

        Http::fake();

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('data-development-execution-context', false)
            ->assertSee('ACTION CONTEXT')
            ->assertSee('1kz-ma1/Canovia-web')
            ->assertSee('feature/v57-4-developer-execution-context')
            ->assertSee('#262')
            ->assertSee('CI 失敗')
            ->assertSee('DONE WHEN')
            ->assertSee('CI state = success')
            ->assertSee('同じPR / head SHAのCI success Evidence');

        Http::assertNothingSent();

        $this->assertSame(35, $task->fresh()->progress_percent);
        $this->assertSame('doing', $task->fresh()->status);
        $this->assertSame(80, $task->fresh()->remaining_minutes);
    }

    public function test_context_can_fall_back_to_linked_artifact_before_evidence_exists(): void
    {
        [, $plan, $task] = $this->scenario();

        $this->artifact(
            $plan,
            $task,
            'Issue #30',
            'https://github.com/1kz-ma1/Canovia-web/issues/30',
        );

        $action = new ActionProposal(
            kind: 'development_connect_evidence',
            title: 'GitHub項目をTaskへ結ぶ',
            intent: 'Development Evidenceを観測できるようにする。',
            confidence: Confidence::deterministic(),
            successSignals: ['Taskに紐づくDevelopment Evidenceが1件以上ある'],
            metadata: [
                'target_task_id' => $task->id,
                'route_kind' => 'github_workflow',
            ],
        );

        $context = app(DevelopmentExecutionContextService::class)
            ->build($plan, $task->id, $action);

        $this->assertNotNull($context);
        $this->assertFalse($context['has_github_evidence']);
        $this->assertSame('1kz-ma1/Canovia-web', $context['repository']);
        $this->assertCount(1, $context['linked_artifacts']);
        $this->assertSame([], $context['recent_evidence']);
        $this->assertStringContainsString(
            '対象TaskとGitHub',
            (string) data_get($context, 'handoff.evidence_hint'),
        );
    }

    private function scenario(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Canovia Development',
            'description' => 'Developer Execution Context',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = $this->task(
            $plan,
            'V57.4 Developer Execution Context',
            'doing',
            1,
        );
        $otherTask = $this->task(
            $plan,
            '別の開発Task',
            'todo',
            2,
        );

        return [$user, $plan, $task, $otherTask];
    }

    private function task(
        Plan $plan,
        string $title,
        string $status,
        int $sortOrder,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => null,
            'estimated_minutes' => 120,
            'remaining_minutes' => $status === 'doing' ? 80 : 120,
            'progress_percent' => $status === 'doing' ? 35 : 0,
            'status' => $status,
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => $sortOrder,
        ]);
    }

    private function artifact(
        Plan $plan,
        Task $task,
        string $title,
        string $url,
    ): PlanArtifact {
        $artifact = PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $plan->user_id,
            'provider' => 'github',
            'artifact_type' => 'link',
            'title' => $title,
            'url' => $url,
        ]);

        $artifact->tasks()->sync([$task->id]);

        return $artifact;
    }

    private function evidence(
        Task $task,
        string $type,
        array $metadata,
        mixed $occurredAt,
    ): TaskEvidence {
        return TaskEvidence::query()->create([
            'plan_id' => $task->plan_id,
            'task_id' => $task->id,
            'user_id' => $task->plan?->user_id,
            'source' => EvidenceSource::GitHub->value,
            'type' => $type,
            'external_key' => $type.':'.Str::uuid(),
            'confidence' => 1,
            'occurred_at' => $occurredAt,
            'metadata' => $metadata,
        ]);
    }
}
