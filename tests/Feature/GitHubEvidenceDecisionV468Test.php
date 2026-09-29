<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\GitHubEvidenceDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GitHubEvidenceDecisionV468Test extends TestCase
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

    public function test_merged_pr_becomes_completion_candidate_and_only_human_apply_completes_task(): void
    {
        [$user, $plan, $task, $pr] = $this->scenario(
            snapshot: $this->snapshot(
                merged: true,
                reviewState: 'APPROVED',
                ciState: 'unknown',
            ),
        );

        $before = $task->only(['status', 'progress_percent', 'remaining_minutes']);

        $response = $this->actingAs($user)
            ->get(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('EVIDENCE DECISION')
            ->assertSee('Task完了の候補があります')
            ->assertSee('このTaskを完了として反映');

        $task->refresh();
        $this->assertSame($before['status'], $task->status);
        $this->assertSame($before['progress_percent'], $task->progress_percent);
        $this->assertSame($before['remaining_minutes'], $task->remaining_minutes);

        $candidate = app(GitHubEvidenceDecisionService::class)->candidate(
            $task,
            $pr,
            (array) data_get($pr->metadata, 'github_return_snapshot'),
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
            ->post(route('plans.tasks.execution_orchestration.github.decision.apply', [$plan, $task, $pr]), [
                'action' => 'complete',
                'expected_snapshot_fingerprint' => $candidate['snapshot_fingerprint'],
                'expected_task_fingerprint' => $candidate['task_fingerprint'],
            ])
            ->assertRedirect(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'GitHub Evidenceを確認し、このTaskを完了として反映しました。');

        $task->refresh();
        $this->assertSame('done', $task->status);
        $this->assertSame(100, $task->progress_percent);
        $this->assertSame(0, $task->remaining_minutes);
        $this->assertStringContainsString('PR #55', (string) $task->progress_reason);
        $this->assertStringContainsString('ユーザー確認', (string) $task->progress_reason);

        $this->assertDatabaseHas('task_evidences', [
            'task_id' => $task->id,
            'source' => 'github',
            'type' => 'pull_request_merged',
        ]);
    }

    public function test_changes_requested_and_failed_ci_can_continue_without_lowering_or_inventing_progress(): void
    {
        [$user, $plan, $task, $pr] = $this->scenario(
            taskStatus: 'todo',
            taskProgress: 50,
            snapshot: $this->snapshot(
                merged: false,
                reviewState: 'CHANGES_REQUESTED',
                ciState: 'failure',
            ),
        );

        $candidate = app(GitHubEvidenceDecisionService::class)->candidate(
            $task,
            $pr,
            (array) data_get($pr->metadata, 'github_return_snapshot'),
        );

        $this->assertSame('continue', $candidate['recommendation']);
        $this->assertContains('continue', $candidate['allowed_actions']);
        $this->assertNotContains('complete', $candidate['allowed_actions']);

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
            ->post(route('plans.tasks.execution_orchestration.github.decision.apply', [$plan, $task, $pr]), [
                'action' => 'continue',
                'expected_snapshot_fingerprint' => $candidate['snapshot_fingerprint'],
                'expected_task_fingerprint' => $candidate['task_fingerprint'],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'success',
                'GitHub Evidenceを確認し、修正対応を続ける状態へ反映しました。進捗率は変更していません。',
            );

        $task->refresh();
        $this->assertSame('doing', $task->status);
        $this->assertSame(50, $task->progress_percent);
        $this->assertSame(45, $task->remaining_minutes);
        $this->assertStringContainsString('修正依頼', (string) $task->next_action_note);
        $this->assertStringContainsString('CI失敗', (string) $task->next_action_note);

        $this->assertDatabaseHas('task_evidences', [
            'task_id' => $task->id,
            'source' => 'github',
            'type' => 'pull_request_review_submitted',
        ]);
        $this->assertDatabaseHas('task_evidences', [
            'task_id' => $task->id,
            'source' => 'github',
            'type' => 'pull_request_ci_observed',
        ]);
    }

    public function test_remote_change_during_confirmation_refreshes_evidence_and_blocks_old_decision(): void
    {
        [$user, $plan, $task, $pr] = $this->scenario(
            snapshot: $this->snapshot(
                merged: false,
                reviewState: 'APPROVED',
                ciState: 'unknown',
            ),
        );

        $candidate = app(GitHubEvidenceDecisionService::class)->candidate(
            $task,
            $pr,
            (array) data_get($pr->metadata, 'github_return_snapshot'),
        );

        $this->assertSame('wait', $candidate['recommendation']);

        $this->fakeReturn(
            merged: true,
            reviewState: 'APPROVED',
            permissions: [
                'metadata' => 'read',
                'contents' => 'write',
                'pull_requests' => 'write',
            ],
        );

        $before = $task->only(['status', 'progress_percent', 'remaining_minutes', 'next_action_note']);

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.github.decision.apply', [$plan, $task, $pr]), [
                'action' => 'wait',
                'expected_snapshot_fingerprint' => $candidate['snapshot_fingerprint'],
                'expected_task_fingerprint' => $candidate['task_fingerprint'],
            ])
            ->assertRedirect(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertSessionHas(
                'status',
                '確認中にGitHubまたはTaskの状態が変わりました。最新結果を表示したので、内容を確認してからもう一度反映してください。',
            );

        $task->refresh();
        $this->assertSame($before['status'], $task->status);
        $this->assertSame($before['progress_percent'], $task->progress_percent);
        $this->assertSame($before['remaining_minutes'], $task->remaining_minutes);
        $this->assertSame($before['next_action_note'], $task->next_action_note);

        $pr->refresh();
        $this->assertTrue((bool) data_get($pr->metadata, 'github_return_snapshot.pull_request.merged'));
        $this->assertDatabaseHas('task_evidences', [
            'task_id' => $task->id,
            'source' => 'github',
            'type' => 'pull_request_merged',
        ]);
    }

    public function test_merged_pr_with_negative_signal_does_not_choose_for_user(): void
    {
        [, , $task, $pr] = $this->scenario(
            snapshot: $this->snapshot(
                merged: true,
                reviewState: 'CHANGES_REQUESTED',
                ciState: 'failure',
            ),
        );

        $candidate = app(GitHubEvidenceDecisionService::class)->candidate(
            $task,
            $pr,
            (array) data_get($pr->metadata, 'github_return_snapshot'),
        );

        $this->assertSame('manual_review', $candidate['recommendation']);
        $this->assertContains('complete', $candidate['allowed_actions']);
        $this->assertContains('continue', $candidate['allowed_actions']);
        $this->assertContains('wait', $candidate['allowed_actions']);
        $this->assertSame(50, $task->fresh()->progress_percent);
        $this->assertSame('doing', $task->fresh()->status);
    }

    public function test_complete_action_is_rejected_when_merge_is_not_confirmed(): void
    {
        [$user, $plan, $task, $pr] = $this->scenario(
            snapshot: $this->snapshot(
                merged: false,
                reviewState: 'APPROVED',
                ciState: 'success',
            ),
        );

        $candidate = app(GitHubEvidenceDecisionService::class)->candidate(
            $task,
            $pr,
            (array) data_get($pr->metadata, 'github_return_snapshot'),
        );

        $this->fakeReturn(
            merged: false,
            reviewState: 'APPROVED',
            permissions: [
                'metadata' => 'read',
                'contents' => 'write',
                'pull_requests' => 'write',
                'actions' => 'read',
            ],
            actionsConclusion: 'success',
        );

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.github.decision.apply', [$plan, $task, $pr]), [
                'action' => 'complete',
                'expected_snapshot_fingerprint' => $candidate['snapshot_fingerprint'],
                'expected_task_fingerprint' => $candidate['task_fingerprint'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('github_decision');

        $task->refresh();
        $this->assertSame('doing', $task->status);
        $this->assertSame(50, $task->progress_percent);
    }

    private function scenario(
        string $taskStatus = 'doing',
        int $taskProgress = 50,
        ?array $snapshot = null,
    ): array {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $this->grantAllAccess($user);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'HINANEX',
            'description' => 'GitHub Evidenceから次状態を判断する',
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
            'description' => 'GitHub結果を確認して次状態を決める',
            'estimated_minutes' => 90,
            'remaining_minutes' => 45,
            'progress_percent' => $taskProgress,
            'status' => $taskStatus,
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

        $prMetadata = [
            'github_workflow_state' => 'review',
            'github_write_origin' => [
                'source' => 'execution_github_handoff',
                'repository_artifact_id' => $repository->id,
                'target_task_id' => $task->id,
                'executed_by' => 'github_app',
            ],
        ];

        if ($snapshot !== null) {
            $prMetadata['github_return_snapshot'] = $snapshot;
        }

        $pr = PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'github',
            'artifact_type' => 'link',
            'title' => 'PR #55 · Map interactionを修正',
            'url' => 'https://github.com/1kz-ma1/HINANEX/pull/55',
            'external_id' => '55',
            'metadata' => $prMetadata,
        ]);
        $pr->tasks()->sync([$task->id]);

        return [$user, $plan, $task, $pr];
    }

    private function snapshot(
        bool $merged,
        string $reviewState,
        string $ciState,
    ): array {
        $approved = $reviewState === 'APPROVED' ? 1 : 0;
        $changes = $reviewState === 'CHANGES_REQUESTED' ? 1 : 0;

        return [
            'version' => 1,
            'source' => 'github_app_rest',
            'repo_full_name' => '1kz-ma1/HINANEX',
            'fetched_at' => '2026-09-29T05:00:00+00:00',
            'pull_request' => [
                'number' => 55,
                'title' => 'Map interactionを修正',
                'state' => $merged ? 'closed' : 'open',
                'draft' => false,
                'merged' => $merged,
                'merged_at' => $merged ? '2026-09-29T05:00:00Z' : null,
                'merged_by' => $merged ? 'maintainer-a' : '',
                'merge_commit_sha' => $merged ? str_repeat('m', 40) : '',
                'head_sha' => str_repeat('h', 40),
                'head_ref' => 'canovia/test',
                'base_ref' => 'main',
                'updated_at' => '2026-09-29T05:00:00Z',
                'closed_at' => $merged ? '2026-09-29T05:00:00Z' : null,
                'url' => 'https://github.com/1kz-ma1/HINANEX/pull/55',
            ],
            'review_summary' => [
                'approved_reviewers' => $approved,
                'changes_requested_reviewers' => $changes,
                'latest_decisions' => [[
                    'id' => 9001,
                    'state' => $reviewState,
                    'reviewer' => 'reviewer-a',
                    'submitted_at' => '2026-09-29T04:50:00Z',
                    'commit_id' => str_repeat('h', 40),
                ]],
            ],
            'ci' => [
                'state' => $ciState,
                'actions_runs' => $ciState === 'unknown' ? [] : [[
                    'id' => 7001,
                    'run_attempt' => 1,
                    'status' => $ciState === 'pending' ? 'in_progress' : 'completed',
                    'conclusion' => $ciState === 'pending' ? null : $ciState,
                    'head_sha' => str_repeat('h', 40),
                    'updated_at' => '2026-09-29T04:55:00Z',
                ]],
                'check_runs' => [],
                'combined_status' => null,
            ],
            'warnings' => [],
        ];
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
                        'status' => $actionsConclusion === null ? 'completed' : 'completed',
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
