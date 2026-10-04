<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Intelligence\Development\DevelopmentEvidenceCollector;
use App\Jobs\ProcessGitHubWebhookDelivery;
use App\Models\GitHubWebhookDelivery;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\FeatureAccessService;
use App\Services\GitHubDevelopmentEvidenceService;
use App\Services\GitHubReturnEvidenceService;
use App\Services\GitHubWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeveloperEvidenceSyncV537Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'services.github.api_url' => 'https://api.github.com',
            'services.github.app_id' => '12345',
            'services.github.app_private_key' => $this->privateKey(),
            'services.github.app_private_key_base64' => null,
            'services.github.app_webhook_secret' => 'v53-7-webhook-secret',
        ]);
    }

    public function test_push_webhook_persists_only_minimal_routing_facts(): void
    {
        Queue::fake();

        $sha = str_repeat('a', 40);
        $payload = [
            'installation' => ['id' => 777],
            'repository' => ['full_name' => '1kz-ma1/HINANEX'],
            'ref' => 'refs/heads/feature/adaptive-map',
            'after' => $sha,
            'head_commit' => [
                'id' => $sha,
                'message' => 'DO NOT persist this private commit message',
            ],
        ];

        $this->postWebhook(
            'push',
            'delivery-v537-push-routing-001',
            $payload,
        )
            ->assertStatus(202)
            ->assertJson(['status' => 'accepted']);

        $delivery = GitHubWebhookDelivery::firstOrFail();

        $this->assertSame([], $delivery->pull_request_numbers);
        $this->assertSame([[
            'kind' => 'push',
            'branch' => 'feature/adaptive-map',
            'commit_sha' => $sha,
        ]], $delivery->routing_targets);

        $serialized = json_encode(
            $delivery->getAttributes(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $this->assertStringNotContainsString('DO NOT persist', $serialized);

        Queue::assertPushed(ProcessGitHubWebhookDelivery::class, 1);
    }

    public function test_issue_event_refetches_authoritative_state_and_records_evidence_only(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $issue = $this->artifact(
            $plan,
            $user,
            'https://github.com/1kz-ma1/HINANEX/issues/12',
            '12',
        );
        $issue->tasks()->sync([$task->id]);

        $this->fakeIssue(12, 'closed');

        $delivery = $this->delivery(
            'issues',
            [['kind' => 'issue', 'number' => 12]],
        );

        $before = $task->only([
            'status',
            'progress_percent',
            'remaining_minutes',
        ]);

        $this->process($delivery);

        $delivery->refresh();
        $task->refresh();

        $this->assertSame('processed', $delivery->status);
        $this->assertSame(1, $delivery->matched_artifacts);
        $this->assertSame(1, $delivery->synced_tasks);
        $this->assertSame(0, $delivery->skipped_entitlement);

        $evidence = TaskEvidence::query()
            ->where('type', 'github_issue_observed')
            ->firstOrFail();

        $this->assertSame(12, data_get($evidence->metadata, 'issue_number'));
        $this->assertSame('closed', data_get($evidence->metadata, 'issue_state'));
        $this->assertArrayNotHasKey('body', $evidence->metadata ?? []);
        $this->assertArrayNotHasKey('title', $evidence->metadata ?? []);

        $this->assertSame($before['status'], $task->status);
        $this->assertSame($before['progress_percent'], $task->progress_percent);
        $this->assertSame($before['remaining_minutes'], $task->remaining_minutes);

        $observations = app(DevelopmentEvidenceCollector::class)->collect($plan);
        $this->assertCount(1, $observations);
        $this->assertSame('github_issue_observed', $observations[0]->type);
        $this->assertSame('closed', $observations[0]->facts['issue_state']);
        $this->assertArrayNotHasKey('body', $observations[0]->facts);
    }

    public function test_push_records_branch_and_commit_without_advancing_task_progress(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $branch = $this->artifact(
            $plan,
            $user,
            'https://github.com/1kz-ma1/HINANEX/tree/feature/adaptive-map',
        );
        $branch->tasks()->sync([$task->id]);

        $sha = str_repeat('b', 40);
        $this->fakePush('feature/adaptive-map', $sha);

        $delivery = $this->delivery(
            'push',
            [[
                'kind' => 'push',
                'branch' => 'feature/adaptive-map',
                'commit_sha' => $sha,
            ]],
        );

        $before = $task->only([
            'status',
            'progress_percent',
            'remaining_minutes',
        ]);

        $this->process($delivery);

        $this->assertDatabaseHas('task_evidences', [
            'task_id' => $task->id,
            'type' => 'github_branch_observed',
            'source' => 'github',
        ]);
        $this->assertDatabaseHas('task_evidences', [
            'task_id' => $task->id,
            'type' => 'github_commit_observed',
            'source' => 'github',
        ]);

        $commit = TaskEvidence::query()
            ->where('type', 'github_commit_observed')
            ->firstOrFail();

        $this->assertSame($sha, data_get($commit->metadata, 'commit_sha'));
        $this->assertSame(
            'feature/adaptive-map',
            data_get($commit->metadata, 'branch'),
        );
        $this->assertArrayNotHasKey('message', $commit->metadata ?? []);
        $this->assertArrayNotHasKey('files', $commit->metadata ?? []);

        $task->refresh();
        $this->assertSame($before['status'], $task->status);
        $this->assertSame($before['progress_percent'], $task->progress_percent);
        $this->assertSame($before['remaining_minutes'], $task->remaining_minutes);

        $observations = app(DevelopmentEvidenceCollector::class)->collect($plan);
        $this->assertSame(
            ['github_branch_observed', 'github_commit_observed'],
            collect($observations)->pluck('type')->sort()->values()->all(),
        );
    }

    public function test_deployment_matches_linked_pr_by_authoritative_sha(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $sha = str_repeat('c', 40);

        $pr = $this->artifact(
            $plan,
            $user,
            'https://github.com/1kz-ma1/HINANEX/pull/55',
            '55',
            [
                'github_return_snapshot' => [
                    'pull_request' => [
                        'head_sha' => str_repeat('d', 40),
                        'merge_commit_sha' => $sha,
                    ],
                ],
            ],
        );
        $pr->tasks()->sync([$task->id]);

        $this->fakeDeployment(9001, $sha, 'main', 'production', 'success');

        $delivery = $this->delivery(
            'deployment_status',
            [[
                'kind' => 'deployment',
                'deployment_id' => 9001,
            ]],
        );

        $this->process($delivery);

        $evidence = TaskEvidence::query()
            ->where('type', 'github_deployment_observed')
            ->firstOrFail();

        $this->assertSame(9001, data_get($evidence->metadata, 'deployment_id'));
        $this->assertSame($sha, data_get($evidence->metadata, 'deployment_sha'));
        $this->assertSame('production', data_get($evidence->metadata, 'environment'));
        $this->assertSame('success', data_get($evidence->metadata, 'deployment_status'));
        $this->assertTrue((bool) data_get(
            $evidence->metadata,
            'production_environment',
        ));

        $task->refresh();
        $this->assertSame(50, (int) $task->progress_percent);
        $this->assertSame('doing', $task->status);
    }

    public function test_unlinked_issue_does_not_trigger_github_api_read(): void
    {
        [$user, $plan] = $this->scenario();
        $this->artifact(
            $plan,
            $user,
            'https://github.com/1kz-ma1/HINANEX/issues/99',
            '99',
        );

        Http::fake();

        $delivery = $this->delivery(
            'issues',
            [['kind' => 'issue', 'number' => 12]],
        );

        $this->process($delivery);

        $delivery->refresh();
        $this->assertSame('ignored', $delivery->status);
        $this->assertSame(0, $delivery->matched_artifacts);
        $this->assertSame(0, $delivery->synced_tasks);
        $this->assertDatabaseCount('task_evidences', 0);
        Http::assertNothingSent();
    }

    public function test_entitlement_blocks_new_development_sync_before_github_read(): void
    {
        [$user, $plan, $task] = $this->scenario(grantAccess: false);
        $issue = $this->artifact(
            $plan,
            $user,
            'https://github.com/1kz-ma1/HINANEX/issues/12',
            '12',
        );
        $issue->tasks()->sync([$task->id]);

        Http::fake();

        $delivery = $this->delivery(
            'issues',
            [['kind' => 'issue', 'number' => 12]],
        );

        $this->process($delivery);

        $delivery->refresh();
        $this->assertSame('ignored', $delivery->status);
        $this->assertSame(0, $delivery->synced_tasks);
        $this->assertSame(1, $delivery->skipped_entitlement);
        $this->assertDatabaseCount('task_evidences', 0);
        Http::assertNothingSent();
    }

    private function process(GitHubWebhookDelivery $delivery): void
    {
        $job = new ProcessGitHubWebhookDelivery((int) $delivery->id);
        $job->handle(
            app(GitHubReturnEvidenceService::class),
            app(GitHubWorkflowService::class),
            app(FeatureAccessService::class),
            app(GitHubDevelopmentEvidenceService::class),
        );
    }

    private function delivery(
        string $event,
        array $targets,
    ): GitHubWebhookDelivery {
        return GitHubWebhookDelivery::query()->create([
            'delivery_id' => 'delivery-v537-'.Str::lower(Str::random(18)),
            'event_name' => $event,
            'action' => 'observed',
            'repo_full_name' => '1kz-ma1/HINANEX',
            'installation_id' => 777,
            'pull_request_numbers' => [],
            'routing_targets' => $targets,
            'status' => 'accepted',
            'received_at' => now(),
        ]);
    }

    private function scenario(bool $grantAccess = true): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        if ($grantAccess) {
            UserProductGrant::query()->create([
                'user_id' => $user->id,
                'product_key' => ProductKey::AllAccess,
                'source' => 'manual',
                'starts_at' => now()->subMinute(),
                'metadata' => ['test' => true],
            ]);
        }

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Canovia Development',
            'description' => 'Development Evidence Sync',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Release readinessを改善',
            'description' => 'GitHubの事実から状態を判断する',
            'estimated_minutes' => 120,
            'remaining_minutes' => 60,
            'progress_percent' => 50,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        PlanArtifact::query()->create([
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

        return [$user, $plan, $task];
    }

    private function artifact(
        Plan $plan,
        User $user,
        string $url,
        ?string $externalId = null,
        array $metadata = [],
    ): PlanArtifact {
        return PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'github',
            'artifact_type' => 'link',
            'title' => 'GitHub work item',
            'url' => $url,
            'external_id' => $externalId,
            'metadata' => $metadata,
        ]);
    }

    private function fakeIssue(int $number, string $state): void
    {
        Http::fake(function (HttpRequest $request) use ($number, $state) {
            $common = $this->commonGitHubAuth($request, ['issues' => 'read']);
            if ($common !== null) {
                return $common;
            }

            if (
                $request->method() === 'GET'
                && $request->url() === 'https://api.github.com/repos/1kz-ma1/HINANEX/issues/'.$number
            ) {
                return Http::response([
                    'number' => $number,
                    'title' => 'DO NOT import Issue title',
                    'body' => 'DO NOT import Issue body',
                    'state' => $state,
                    'state_reason' => $state === 'closed' ? 'completed' : null,
                    'locked' => false,
                    'assignees' => [['login' => 'developer-a']],
                    'updated_at' => '2026-10-04T03:00:00Z',
                    'closed_at' => $state === 'closed'
                        ? '2026-10-04T03:00:00Z'
                        : null,
                    'html_url' => 'https://github.com/1kz-ma1/HINANEX/issues/'.$number,
                ], 200);
            }

            return Http::response([
                'message' => 'Unexpected request '.$request->method().' '.$request->url(),
            ], 500);
        });
    }

    private function fakePush(string $branch, string $sha): void
    {
        Http::fake(function (HttpRequest $request) use ($branch, $sha) {
            $common = $this->commonGitHubAuth($request, ['contents' => 'read']);
            if ($common !== null) {
                return $common;
            }

            if (
                $request->method() === 'GET'
                && str_contains($request->url(), '/branches/')
            ) {
                return Http::response([
                    'name' => $branch,
                    'protected' => false,
                    'commit' => ['sha' => $sha],
                ], 200);
            }

            if (
                $request->method() === 'GET'
                && $request->url() === 'https://api.github.com/repos/1kz-ma1/HINANEX/commits/'.$sha
            ) {
                return Http::response([
                    'sha' => $sha,
                    'html_url' => 'https://github.com/1kz-ma1/HINANEX/commit/'.$sha,
                    'commit' => [
                        'message' => 'DO NOT import commit message',
                        'author' => ['date' => '2026-10-04T03:10:00Z'],
                        'committer' => ['date' => '2026-10-04T03:11:00Z'],
                        'verification' => [
                            'verified' => true,
                            'reason' => 'valid',
                        ],
                    ],
                    'parents' => [['sha' => str_repeat('a', 40)]],
                    'files' => [[
                        'filename' => 'private/source.php',
                        'patch' => 'DO NOT import diff',
                    ]],
                ], 200);
            }

            return Http::response([
                'message' => 'Unexpected request '.$request->method().' '.$request->url(),
            ], 500);
        });
    }

    private function fakeDeployment(
        int $id,
        string $sha,
        string $ref,
        string $environment,
        string $status,
    ): void {
        Http::fake(function (HttpRequest $request) use (
            $id,
            $sha,
            $ref,
            $environment,
            $status,
        ) {
            $common = $this->commonGitHubAuth(
                $request,
                ['deployments' => 'read'],
            );
            if ($common !== null) {
                return $common;
            }

            if (
                $request->method() === 'GET'
                && $request->url() === 'https://api.github.com/repos/1kz-ma1/HINANEX/deployments/'.$id
            ) {
                return Http::response([
                    'id' => $id,
                    'sha' => $sha,
                    'ref' => $ref,
                    'environment' => $environment,
                    'production_environment' => true,
                    'transient_environment' => false,
                    'created_at' => '2026-10-04T03:20:00Z',
                    'updated_at' => '2026-10-04T03:22:00Z',
                    'payload' => ['DO NOT' => 'persist'],
                    'description' => 'DO NOT persist deployment description',
                ], 200);
            }

            if (
                $request->method() === 'GET'
                && str_starts_with(
                    $request->url(),
                    'https://api.github.com/repos/1kz-ma1/HINANEX/deployments/'.$id.'/statuses'
                )
            ) {
                return Http::response([[
                    'id' => 9100,
                    'state' => $status,
                    'created_at' => '2026-10-04T03:22:00Z',
                    'updated_at' => '2026-10-04T03:22:00Z',
                    'description' => 'DO NOT persist status description',
                ]], 200);
            }

            return Http::response([
                'message' => 'Unexpected request '.$request->method().' '.$request->url(),
            ], 500);
        });
    }

    private function commonGitHubAuth(
        HttpRequest $request,
        array $permissions,
    ) {
        if (
            $request->method() === 'GET'
            && $request->url() === 'https://api.github.com/repos/1kz-ma1/HINANEX/installation'
        ) {
            return Http::response(['id' => 777], 200);
        }

        if (
            $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/app/installations/777/access_tokens'
        ) {
            return Http::response([
                'token' => 'installation-token',
                'permissions' => [
                    'metadata' => 'read',
                    ...$permissions,
                ],
            ], 201);
        }

        return null;
    }

    private function postWebhook(
        string $event,
        string $delivery,
        array $payload,
    ) {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $signature = 'sha256='.hash_hmac(
            'sha256',
            $body,
            (string) config('services.github.app_webhook_secret'),
        );

        return $this->call(
            'POST',
            route('api.github.webhook'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GITHUB_EVENT' => $event,
                'HTTP_X_GITHUB_DELIVERY' => $delivery,
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $body,
        );
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
