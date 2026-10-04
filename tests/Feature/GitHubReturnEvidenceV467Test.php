<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Enums\ProductKey;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GitHubReturnEvidenceV467Test extends TestCase
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
            'services.github.api_url' => 'https://api.github.com',
            'services.github.app_id' => '12345',
            'services.github.app_private_key' => $this->privateKey(),
            'services.github.app_private_key_base64' => null,
            'services.github.app_install_url' => 'https://github.com/apps/canovia/installations/new',
        ]);
    }

    public function test_merged_pr_and_review_are_recorded_idempotently_without_completing_task(): void
    {
        [$user, $plan, $task, $repository, $pr] = $this->scenario();
        $this->importPacket($user, $plan, $task);
        $this->fakeReturn(
            merged: true,
            reviewState: 'APPROVED',
            permissions: [
                'metadata' => 'read',
                'contents' => 'write',
                'pull_requests' => 'write',
            ],
        );

        $beforeProgress = $task->progress_percent;
        $beforeStatus = $task->status;

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.github.return_sync', [$plan, $task, $pr]))
            ->assertRedirect(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'success',
                'GitHubのReview / Merge / CI結果を確認し、Task Evidenceへ反映しました。Task進捗・完了状態は自動変更していません。',
            );

        $this->assertDatabaseCount('task_evidences', 3);

        $reviewEvidence = TaskEvidence::query()
            ->where('type', 'pull_request_review_submitted')
            ->firstOrFail();
        $mergeEvidence = TaskEvidence::query()
            ->where('type', 'pull_request_merged')
            ->firstOrFail();

        $this->assertSame(EvidenceSource::GitHub, $reviewEvidence->source);
        $this->assertSame('APPROVED', data_get($reviewEvidence->metadata, 'review_state'));
        $this->assertSame('reviewer-a', data_get($reviewEvidence->metadata, 'reviewer'));
        $this->assertSame(EvidenceSource::GitHub, $mergeEvidence->source);
        $this->assertSame(str_repeat('m', 40), data_get($mergeEvidence->metadata, 'merge_commit_sha'));

        $task->refresh();
        $this->assertSame($beforeProgress, $task->progress_percent);
        $this->assertSame($beforeStatus, $task->status);

        $pr->refresh();
        $this->assertSame('review', $pr->githubWorkflowState());
        $this->assertTrue((bool) data_get($pr->metadata, 'github_return_snapshot.pull_request.merged'));
        $this->assertSame('unknown', data_get($pr->metadata, 'github_return_snapshot.ci.state'));
        $this->assertStringContainsString(
            'Actions permission',
            implode(' ', (array) data_get($pr->metadata, 'github_return_snapshot.warnings', [])),
        );

        $this->fakeReturn(
            merged: true,
            reviewState: 'APPROVED',
            permissions: [
                'metadata' => 'read',
                'contents' => 'write',
                'pull_requests' => 'write',
            ],
        );

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.github.return_sync', [$plan, $task, $pr]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('task_evidences', 3);

        $this->actingAs($user)
            ->get(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('RETURN LAYER')
            ->assertSee('Merged')
            ->assertSee('承認確認 1人')
            ->assertSee('このPacket生成後にTask / Dependency / Evidenceなどの状態が変わっています');
    }

    public function test_changes_requested_and_failed_actions_are_returned_as_evidence(): void
    {
        [$user, $plan, $task, , $pr] = $this->scenario();

        $this->fakeReturn(
            merged: false,
            reviewState: 'CHANGES_REQUESTED',
            permissions: [
                'metadata' => 'read',
                'contents' => 'write',
                'pull_requests' => 'write',
                'actions' => 'read',
            ],
            actionsConclusion: 'failure',
        );

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.github.return_sync', [$plan, $task, $pr]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('task_evidences', 3);

        $reviewEvidence = TaskEvidence::query()
            ->where('type', 'pull_request_review_submitted')
            ->firstOrFail();
        $ciEvidence = TaskEvidence::query()
            ->where('type', 'pull_request_ci_observed')
            ->firstOrFail();

        $this->assertSame('CHANGES_REQUESTED', data_get($reviewEvidence->metadata, 'review_state'));
        $this->assertSame('failure', data_get($ciEvidence->metadata, 'ci_state'));
        $this->assertSame('CI', data_get($ciEvidence->metadata, 'actions_runs.0.name'));

        $pr->refresh();
        $this->assertFalse((bool) data_get($pr->metadata, 'github_return_snapshot.pull_request.merged'));
        $this->assertSame(1, data_get($pr->metadata, 'github_return_snapshot.review_summary.changes_requested_reviewers'));
        $this->assertSame('failure', data_get($pr->metadata, 'github_return_snapshot.ci.state'));

        $this->actingAs($user)
            ->get(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('修正依頼 1人')
            ->assertSee('失敗');
    }

    public function test_unlinked_pull_request_cannot_create_task_evidence(): void
    {
        [$user, $plan, $task, , $pr] = $this->scenario(linkPullRequest: false);
        Http::fake();

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.github.return_sync', [$plan, $task, $pr]))
            ->assertRedirect()
            ->assertSessionHasErrors('github_return');

        Http::assertNothingSent();
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_user_without_github_evidence_capability_cannot_fetch_return_state(): void
    {
        [$user, $plan, $task, , $pr] = $this->scenario(grantAccess: false);
        Http::fake();

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.github.return_sync', [$plan, $task, $pr]))
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('task_evidences', 0);
    }

    private function scenario(
        bool $linkPullRequest = true,
        bool $grantAccess = true,
    ): array {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        if ($grantAccess) {
            $this->grantAllAccess($user);
        }

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'HINANEX',
            'description' => 'GitHub return loop',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Map interactionを修正',
            'description' => 'PR review結果をCanoviaへ戻す',
            'estimated_minutes' => 90,
            'remaining_minutes' => 45,
            'progress_percent' => 50,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        $repository = PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'github',
            'artifact_type' => 'repository',
            'title' => 'HINANEX',
            'url' => 'https://github.com/1kz-ma1/HINANEX',
            'metadata' => [
                'github_app_connection' => [
                    'status' => 'connected',
                    'installation_id' => 777,
                ],
            ],
        ]);

        $pr = PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'github',
            'artifact_type' => 'link',
            'title' => 'PR #55 · Map interactionを修正',
            'url' => 'https://github.com/1kz-ma1/HINANEX/pull/55',
            'external_id' => '55',
            'metadata' => [
                'github_workflow_state' => 'review',
                'github_write_origin' => [
                    'source' => 'execution_github_handoff',
                    'repository_artifact_id' => $repository->id,
                    'target_task_id' => $task->id,
                    'executed_by' => 'github_app',
                ],
            ],
        ]);

        if ($linkPullRequest) {
            $pr->tasks()->sync([$task->id]);
        }

        return [$user, $plan, $task, $repository, $pr];
    }

    private function importPacket(User $user, Plan $plan, Task $task): void
    {
        $packet = [
            'schema_version' => '1.0',
            'flow' => 'execution_packet',
            'summary' => 'Map修正',
            'execution_mode' => 'execute',
            'current_situation' => '実装済み',
            'role' => 'Implementation',
            'objective' => 'PRをレビューへ渡す',
            'reason' => 'Task scope内',
            'actions' => [[
                'title' => 'レビューを受ける',
                'details' => 'GitHub結果を待つ',
                'estimated_minutes' => 5,
            ]],
            'inputs' => [],
            'outputs' => ['Pull Request'],
            'dependencies' => [],
            'assumptions' => [],
            'do_not_touch' => [],
            'completion_criteria' => ['Review結果を確認'],
            'confirmation_required' => [],
            'next_phase' => 'Return Layer',
        ];

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.import', [$plan, $task]), [
                'packet_json' => json_encode($packet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ])
            ->assertSessionHasNoErrors();
    }

    /**
     * @param array<string,string> $permissions
     */
    private function fakeReturn(
        bool $merged,
        string $reviewState,
        array $permissions,
        ?string $actionsConclusion = null,
    ): void {
        Http::fake(function (HttpRequest $request) use ($merged, $reviewState, $permissions, $actionsConclusion) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'GET' && $url === 'https://api.github.com/repos/1kz-ma1/HINANEX/installation') {
                return Http::response(['id' => 777], 200);
            }

            if ($method === 'POST' && $url === 'https://api.github.com/app/installations/777/access_tokens') {
                return Http::response([
                    'token' => 'installation-token',
                    'permissions' => $permissions,
                ], 201);
            }

            if ($method === 'GET' && $url === 'https://api.github.com/repos/1kz-ma1/HINANEX/pulls/55') {
                return Http::response([
                    'number' => 55,
                    'title' => 'Map interactionを修正',
                    'state' => $merged ? 'closed' : 'open',
                    'draft' => false,
                    'merged' => $merged,
                    'merged_at' => $merged ? '2026-09-29T05:00:00Z' : null,
                    'merged_by' => $merged ? ['login' => 'maintainer-a'] : null,
                    'merge_commit_sha' => $merged ? str_repeat('m', 40) : null,
                    'head' => [
                        'sha' => str_repeat('h', 40),
                        'ref' => 'canovia/test',
                    ],
                    'base' => ['ref' => 'main'],
                    'updated_at' => '2026-09-29T05:00:00Z',
                    'closed_at' => $merged ? '2026-09-29T05:00:00Z' : null,
                    'html_url' => 'https://github.com/1kz-ma1/HINANEX/pull/55',
                ], 200);
            }

            if ($method === 'GET' && str_starts_with($url, 'https://api.github.com/repos/1kz-ma1/HINANEX/pulls/55/reviews')) {
                return Http::response([[
                    'id' => 9001,
                    'state' => $reviewState,
                    'user' => ['login' => 'reviewer-a'],
                    'submitted_at' => '2026-09-29T04:50:00Z',
                    'commit_id' => str_repeat('h', 40),
                    'html_url' => 'https://github.com/1kz-ma1/HINANEX/pull/55#pullrequestreview-9001',
                ]], 200);
            }

            if (
                $method === 'GET'
                && str_starts_with($url, 'https://api.github.com/repos/1kz-ma1/HINANEX/actions/runs')
                && in_array(($permissions['actions'] ?? null), ['read', 'write'], true)
            ) {
                return Http::response([
                    'workflow_runs' => [[
                        'id' => 7001,
                        'run_attempt' => 1,
                        'name' => 'CI',
                        'event' => 'pull_request',
                        'status' => 'completed',
                        'conclusion' => $actionsConclusion ?? 'success',
                        'head_sha' => str_repeat('h', 40),
                        'updated_at' => '2026-09-29T04:55:00Z',
                        'html_url' => 'https://github.com/1kz-ma1/HINANEX/actions/runs/7001',
                    ]],
                ], 200);
            }

            return Http::response(['message' => 'Unexpected request: '.$method.' '.$url], 500);
        });
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

    private function privateKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($key);

        $pem = '';
        $this->assertTrue(openssl_pkey_export($key, $pem));
        $this->assertNotSame('', $pem);

        return $pem;
    }
}
