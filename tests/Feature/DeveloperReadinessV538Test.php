<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Enums\ProductKey;
use App\Intelligence\Development\DevelopmentAdaptiveActionService;
use App\Intelligence\Development\DevelopmentPlanIntelligenceService;
use App\Models\IntelligenceActionProjection;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\DevelopmentQualityGateService;
use App\Services\TaskEvidenceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeveloperReadinessV538Test extends TestCase
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

    public function test_no_development_evidence_keeps_readiness_unknown_and_recommends_connection(): void
    {
        [$user, $plan] = $this->scenario();

        $result = app(DevelopmentAdaptiveActionService::class)
            ->evaluate($plan, new CarbonImmutable('2026-10-04T12:00:00+09:00'));

        $this->assertNull($result->intelligence->readiness->score);
        $this->assertSame(
            'unknown',
            $result->intelligence->readiness->level->value,
        );
        $this->assertSame(
            'connect_development_evidence',
            $result->decision->type,
        );
        $this->assertSame(
            'development_connect_evidence',
            $result->primaryAction()?->kind,
        );
    }

    public function test_complete_same_task_release_chain_becomes_ready_without_touching_task_progress(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $before = $task->only([
            'status',
            'progress_percent',
            'remaining_minutes',
        ]);

        $this->readyChain($user, $task);

        $result = app(DevelopmentAdaptiveActionService::class)
            ->evaluate($plan, new CarbonImmutable('2026-10-04T12:30:00+09:00'));

        $readiness = $result->intelligence->readiness;

        $this->assertSame(100, $readiness->score);
        $this->assertSame('ready', $readiness->level->value);
        $this->assertSame(7, data_get($readiness->components, 'passed_gate_count'));
        $this->assertSame('release_ready', $result->decision->type);
        $this->assertSame(
            'development_release_ready',
            $result->primaryAction()?->kind,
        );

        $task->refresh();
        $this->assertSame($before['status'], $task->status);
        $this->assertSame($before['progress_percent'], $task->progress_percent);
        $this->assertSame($before['remaining_minutes'], $task->remaining_minutes);
    }

    public function test_release_gates_from_different_tasks_are_never_combined_into_ready(): void
    {
        [$user, $plan, $taskA] = $this->scenario();
        $taskB = $this->task($plan, 'Production確認');

        $this->evidence($user, $taskA, 'github_commit_observed', [
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'commit_sha' => str_repeat('a', 40),
            'branch' => 'feature/a',
        ], '2026-10-04T01:00:00Z');
        $this->evidence($user, $taskA, 'pull_request_observed', [
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'pull_request_number' => 10,
            'state' => 'open',
            'draft' => false,
            'merged' => false,
            'head_sha' => str_repeat('a', 40),
            'head_ref' => 'feature/a',
            'base_ref' => 'main',
        ], '2026-10-04T01:05:00Z');
        $this->evidence($user, $taskA, 'pull_request_ci_observed', [
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'pull_request_number' => 10,
            'head_sha' => str_repeat('a', 40),
            'ci_state' => 'success',
        ], '2026-10-04T01:10:00Z');
        $this->evidence($user, $taskA, 'pull_request_review_submitted', [
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'pull_request_number' => 10,
            'review_id' => 100,
            'review_state' => 'APPROVED',
            'reviewer' => 'reviewer-a',
        ], '2026-10-04T01:15:00Z');
        $this->evidence($user, $taskA, 'pull_request_merged', [
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'pull_request_number' => 10,
            'merge_commit_sha' => str_repeat('b', 40),
            'head_sha' => str_repeat('a', 40),
        ], '2026-10-04T01:20:00Z');

        $this->evidence($user, $taskB, 'github_deployment_observed', [
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'deployment_id' => 200,
            'deployment_sha' => str_repeat('c', 40),
            'ref' => 'main',
            'environment' => 'production',
            'deployment_status' => 'success',
            'production_environment' => true,
            'transient_environment' => false,
        ], '2026-10-04T01:30:00Z');
        $this->confirmGate($user, $taskB, 'verification', 'passed');
        $this->confirmGate($user, $taskB, 'spec_sync', 'passed');

        $result = app(DevelopmentPlanIntelligenceService::class)
            ->evaluate($plan, new CarbonImmutable('2026-10-04T12:00:00+09:00'));

        $this->assertSame($taskB->id, data_get(
            $result->state->facts,
            'focus_task_id',
        ));
        $this->assertLessThan(100, $result->readiness->score);
        $this->assertNotSame('ready', $result->readiness->level->value);
        $this->assertSame(
            'unknown',
            data_get(
                $result->readiness->components,
                'gates.implementation.status',
            ),
        );

        $states = collect(data_get($result->state->facts, 'task_states', []))
            ->keyBy('task_id');

        $this->assertSame(
            'passed',
            data_get($states->get($taskA->id), 'gates.ci.status'),
        );
        $this->assertSame(
            'unknown',
            data_get($states->get($taskB->id), 'gates.ci.status'),
        );
    }

    public function test_ci_failure_blocks_release_and_becomes_current_action(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->evidence($user, $task, 'github_commit_observed', [
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'commit_sha' => str_repeat('d', 40),
            'branch' => 'feature/ci',
        ], '2026-10-04T02:00:00Z');
        $this->evidence($user, $task, 'pull_request_ci_observed', [
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'pull_request_number' => 20,
            'head_sha' => str_repeat('d', 40),
            'ci_state' => 'failure',
        ], '2026-10-04T02:05:00Z');

        $result = app(DevelopmentAdaptiveActionService::class)
            ->evaluate($plan);

        $this->assertSame('blocked', $result->intelligence->readiness->level->value);
        $this->assertSame('fix_ci', $result->decision->type);
        $this->assertSame(
            'development_fix_ci',
            $result->primaryAction()?->kind,
        );
        $this->assertSame(
            'ci',
            data_get($result->decision->metadata, 'target_gate'),
        );
    }

    public function test_review_gate_preserves_unresolved_changes_requested_from_another_reviewer(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->evidence($user, $task, 'github_commit_observed', [
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'commit_sha' => str_repeat('e', 40),
        ], '2026-10-04T03:00:00Z');

        $this->evidence($user, $task, 'pull_request_review_submitted', [
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'pull_request_number' => 30,
            'review_id' => 301,
            'review_state' => 'CHANGES_REQUESTED',
            'reviewer' => 'reviewer-a',
        ], '2026-10-04T03:05:00Z');

        $this->evidence($user, $task, 'pull_request_review_submitted', [
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'pull_request_number' => 30,
            'review_id' => 302,
            'review_state' => 'APPROVED',
            'reviewer' => 'reviewer-b',
        ], '2026-10-04T03:10:00Z');

        $first = app(DevelopmentPlanIntelligenceService::class)->evaluate($plan);

        $this->assertSame(
            'failed',
            data_get($first->readiness->components, 'gates.review.status'),
        );

        $this->evidence($user, $task, 'pull_request_review_submitted', [
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'pull_request_number' => 30,
            'review_id' => 303,
            'review_state' => 'APPROVED',
            'reviewer' => 'reviewer-a',
        ], '2026-10-04T03:15:00Z');

        $second = app(DevelopmentPlanIntelligenceService::class)->evaluate($plan);

        $this->assertSame(
            'passed',
            data_get($second->readiness->components, 'gates.review.status'),
        );
    }

    public function test_non_production_deployment_success_is_not_release_deploy_pass(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->evidence($user, $task, 'github_deployment_observed', [
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'deployment_id' => 400,
            'deployment_sha' => str_repeat('f', 40),
            'ref' => 'main',
            'environment' => 'staging',
            'deployment_status' => 'success',
            'production_environment' => false,
            'transient_environment' => false,
        ], '2026-10-04T04:00:00Z');

        $result = app(DevelopmentPlanIntelligenceService::class)->evaluate($plan);

        $this->assertSame(
            'pending',
            data_get($result->readiness->components, 'gates.deploy.status'),
        );
        $this->assertNotSame('ready', $result->readiness->level->value);
    }

    public function test_explicit_quality_gate_confirmation_is_idempotent_and_refreshes_action_history(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->releaseThroughDeploy($user, $task);

        $actions = app(DevelopmentAdaptiveActionService::class);
        $before = $actions->refresh(
            $plan,
            new CarbonImmutable('2026-10-04T05:00:00Z'),
        );

        $this->assertSame('verify_release', $before->decision->type);
        $this->assertDatabaseCount('intelligence_action_projections', 1);

        $requestId = (string) Str::uuid();
        $service = app(DevelopmentQualityGateService::class);
        $service->confirm(
            $task,
            'verification',
            'passed',
            $requestId,
            userId: $user->id,
        );
        $service->confirm(
            $task,
            'verification',
            'passed',
            $requestId,
            userId: $user->id,
        );

        $this->assertSame(
            1,
            TaskEvidence::query()
                ->where('type', 'development_quality_gate_confirmed')
                ->where('task_id', $task->id)
                ->count(),
        );

        $after = $actions->refresh(
            $plan,
            new CarbonImmutable('2026-10-04T05:05:00Z'),
        );

        $this->assertSame('sync_spec', $after->decision->type);
        $this->assertDatabaseCount('intelligence_action_projections', 2);
        $this->assertSame(
            1,
            IntelligenceActionProjection::query()
                ->where('status', 'superseded')
                ->count(),
        );

        $service->confirm(
            $task,
            'spec_sync',
            'not_required',
            (string) Str::uuid(),
            userId: $user->id,
        );

        $ready = $actions->refresh(
            $plan,
            new CarbonImmutable('2026-10-04T05:10:00Z'),
        );

        $this->assertSame('ready', $ready->intelligence->readiness->level->value);
        $this->assertSame('release_ready', $ready->decision->type);
    }

    public function test_development_readiness_surface_allows_explicit_gate_confirmation_only_for_owned_task(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->releaseThroughDeploy($user, $task);

        $this->actingAs($user)
            ->get(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('DEVELOPMENT INTELLIGENCE / V53.8')
            ->assertSee('Release Readiness')
            ->assertSee('実機・本番確認')
            ->assertSee('仕様同期');

        $requestId = (string) Str::uuid();

        $this->actingAs($user)
            ->post(
                route(
                    'plans.development_readiness.quality_gate.confirm',
                    [$plan, $task],
                ),
                [
                    'quality_gate' => 'verification',
                    'gate_status' => 'passed',
                    'confirmation_request_id' => $requestId,
                ],
            )
            ->assertRedirect(route('github_workflow.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('task_evidences', [
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native->value,
            'type' => 'development_quality_gate_confirmed',
        ]);

        $other = User::factory()->create(['first_run_completed_at' => now()]);
        $this->grantAllAccess($other);

        $this->actingAs($other)
            ->post(
                route(
                    'plans.development_readiness.quality_gate.confirm',
                    [$plan, $task],
                ),
                [
                    'quality_gate' => 'spec_sync',
                    'gate_status' => 'passed',
                    'confirmation_request_id' => (string) Str::uuid(),
                ],
            )
            ->assertForbidden();
    }

    public function test_same_semantic_state_does_not_duplicate_current_action_on_reopen(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->releaseThroughDeploy($user, $task);

        $actions = app(DevelopmentAdaptiveActionService::class);

        $first = $actions->refresh(
            $plan,
            new CarbonImmutable('2026-10-04T06:00:00Z'),
        );
        $second = $actions->refresh(
            $plan,
            new CarbonImmutable('2026-10-04T06:30:00Z'),
        );

        $this->assertSame(
            $first->projection?->id,
            $second->projection?->id,
        );
        $this->assertDatabaseCount('intelligence_action_projections', 1);
        $this->assertDatabaseCount('intelligence_decision_traces', 2);
    }

    private function readyChain(User $user, Task $task): void
    {
        $this->releaseThroughDeploy($user, $task);
        $this->confirmGate($user, $task, 'verification', 'passed');
        $this->confirmGate($user, $task, 'spec_sync', 'passed');
    }

    private function releaseThroughDeploy(User $user, Task $task): void
    {
        $repo = '1kz-ma1/Canovia-web';
        $head = str_repeat('1', 40);
        $merge = str_repeat('2', 40);

        $this->evidence($user, $task, 'github_commit_observed', [
            'repo_full_name' => $repo,
            'commit_sha' => $head,
            'branch' => 'feature/v53-8',
        ], '2026-10-04T00:00:00Z');

        $this->evidence($user, $task, 'pull_request_observed', [
            'repo_full_name' => $repo,
            'pull_request_number' => 88,
            'state' => 'open',
            'draft' => false,
            'merged' => false,
            'head_sha' => $head,
            'head_ref' => 'feature/v53-8',
            'base_ref' => 'main',
        ], '2026-10-04T00:02:00Z');

        $this->evidence($user, $task, 'pull_request_ci_observed', [
            'repo_full_name' => $repo,
            'pull_request_number' => 88,
            'head_sha' => $head,
            'ci_state' => 'success',
        ], '2026-10-04T00:04:00Z');

        $this->evidence($user, $task, 'pull_request_review_submitted', [
            'repo_full_name' => $repo,
            'pull_request_number' => 88,
            'review_id' => 8801,
            'review_state' => 'APPROVED',
            'reviewer' => 'reviewer-v538',
        ], '2026-10-04T00:06:00Z');

        $this->evidence($user, $task, 'pull_request_merged', [
            'repo_full_name' => $repo,
            'pull_request_number' => 88,
            'merge_commit_sha' => $merge,
            'head_sha' => $head,
            'head_ref' => 'feature/v53-8',
            'base_ref' => 'main',
        ], '2026-10-04T00:08:00Z');

        $this->evidence($user, $task, 'github_deployment_observed', [
            'repo_full_name' => $repo,
            'deployment_id' => 8802,
            'deployment_sha' => $merge,
            'ref' => 'main',
            'environment' => 'production',
            'deployment_status' => 'success',
            'production_environment' => true,
            'transient_environment' => false,
        ], '2026-10-04T00:10:00Z');
    }

    private function confirmGate(
        User $user,
        Task $task,
        string $gate,
        string $status,
    ): void {
        app(DevelopmentQualityGateService::class)->confirm(
            $task,
            $gate,
            $status,
            (string) Str::uuid(),
            userId: $user->id,
        );
    }

    private function evidence(
        User $user,
        Task $task,
        string $type,
        array $metadata,
        string $occurredAt,
    ): TaskEvidence {
        return app(TaskEvidenceService::class)->record(
            $task,
            EvidenceSource::GitHub,
            $type,
            $metadata,
            confidence: 1.0,
            externalKey: 'v538:'.hash(
                'sha256',
                $task->id.'|'.$type.'|'.$occurredAt.'|'.json_encode($metadata),
            ),
            userId: $user->id,
            occurredAt: CarbonImmutable::parse($occurredAt),
        );
    }

    private function scenario(): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $this->grantAllAccess($user);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Canovia Development',
            'description' => 'Release Readinessを検証する',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = $this->task($plan, 'V53.8 Developer Readiness');

        return [$user, $plan, $task];
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 120,
            'remaining_minutes' => 60,
            'progress_percent' => 50,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => $plan->tasks()->count() + 1,
        ]);
    }

    private function grantAllAccess(User $user): void
    {
        UserProductGrant::query()->create([
            'user_id' => $user->id,
            'product_key' => ProductKey::AllAccess,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
            'metadata' => ['test' => true],
        ]);
    }
}
