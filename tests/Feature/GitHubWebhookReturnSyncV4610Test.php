<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Jobs\ProcessGitHubWebhookDelivery;
use App\Models\GitHubWebhookDelivery;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\FeatureAccessService;
use App\Services\GitHubReturnEvidenceService;
use App\Services\GitHubWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class GitHubWebhookReturnSyncV4610Test extends TestCase
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
            'services.github.app_install_url' => 'https://github.com/apps/canovia/installations/new',
            'services.github.app_webhook_secret' => 'webhook-test-secret',
        ]);
    }

    public function test_invalid_signature_is_rejected_before_persist_or_queue(): void
    {
        Queue::fake();

        $body = json_encode($this->pullRequestPayload(), JSON_UNESCAPED_SLASHES);

        $this->call(
            'POST',
            route('api.github.webhook'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GITHUB_EVENT' => 'pull_request',
                'HTTP_X_GITHUB_DELIVERY' => 'delivery-invalid-001',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.str_repeat('0', 64),
            ],
            $body,
        )
            ->assertUnauthorized()
            ->assertJson(['status' => 'invalid_signature']);

        $this->assertDatabaseCount('github_webhook_deliveries', 0);
        Queue::assertNothingPushed();
    }

    public function test_ping_is_verified_but_not_persisted(): void
    {
        Queue::fake();

        $this->postWebhook('ping', 'delivery-ping-001', [
            'zen' => 'Keep it logically awesome.',
        ])
            ->assertOk()
            ->assertJson(['status' => 'pong']);

        $this->assertDatabaseCount('github_webhook_deliveries', 0);
        Queue::assertNothingPushed();
    }

    public function test_supported_delivery_is_durable_idempotent_and_queued_without_raw_payload(): void
    {
        Queue::fake();

        $payload = $this->pullRequestPayload();
        $payload['pull_request']['body'] = 'DO NOT persist this untrusted webhook text';

        $this->postWebhook('pull_request', 'delivery-pr-001', $payload)
            ->assertStatus(202)
            ->assertJson([
                'status' => 'accepted',
                'delivery_id' => 'delivery-pr-001',
            ]);

        $delivery = GitHubWebhookDelivery::query()->firstOrFail();

        $this->assertSame('accepted', $delivery->status);
        $this->assertSame('pull_request', $delivery->event_name);
        $this->assertSame('closed', $delivery->action);
        $this->assertSame('1kz-ma1/HINANEX', $delivery->repo_full_name);
        $this->assertSame(777, $delivery->installation_id);
        $this->assertSame([55], $delivery->pull_request_numbers);
        $this->assertStringNotContainsString(
            'DO NOT persist',
            json_encode($delivery->getAttributes(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        Queue::assertPushed(
            ProcessGitHubWebhookDelivery::class,
            fn (ProcessGitHubWebhookDelivery $job) => $job->deliveryId === $delivery->id,
        );

        $this->postWebhook('pull_request', 'delivery-pr-001', $payload)
            ->assertOk()
            ->assertJson([
                'status' => 'duplicate',
                'delivery_id' => 'delivery-pr-001',
            ]);

        $this->assertDatabaseCount('github_webhook_deliveries', 1);
        Queue::assertPushed(ProcessGitHubWebhookDelivery::class, 1);
    }

    public function test_background_delivery_refetches_authoritative_github_state_and_records_evidence_only(): void
    {
        [$user, $plan, $task, , $pr] = $this->scenario();
        $this->fakeMergedReturn();

        $delivery = GitHubWebhookDelivery::query()->create([
            'delivery_id' => 'delivery-process-001',
            'event_name' => 'pull_request',
            'action' => 'closed',
            'repo_full_name' => '1kz-ma1/HINANEX',
            'installation_id' => 777,
            'pull_request_numbers' => [55],
            'status' => 'accepted',
            'received_at' => now(),
        ]);

        $before = $task->only(['status', 'progress_percent', 'remaining_minutes']);

        $job = new ProcessGitHubWebhookDelivery((int) $delivery->id);
        $job->handle(
            app(GitHubReturnEvidenceService::class),
            app(GitHubWorkflowService::class),
            app(FeatureAccessService::class),
        );

        $delivery->refresh();
        $task->refresh();
        $pr->refresh();

        $this->assertSame('processed', $delivery->status);
        $this->assertSame(1, $delivery->matched_artifacts);
        $this->assertSame(1, $delivery->synced_tasks);
        $this->assertSame(0, $delivery->skipped_entitlement);

        $this->assertDatabaseHas('task_evidences', [
            'task_id' => $task->id,
            'source' => 'github',
            'type' => 'pull_request_review_submitted',
        ]);
        $this->assertDatabaseHas('task_evidences', [
            'task_id' => $task->id,
            'source' => 'github',
            'type' => 'pull_request_merged',
        ]);

        $this->assertSame($before['status'], $task->status);
        $this->assertSame($before['progress_percent'], $task->progress_percent);
        $this->assertSame($before['remaining_minutes'], $task->remaining_minutes);
        $this->assertTrue((bool) data_get($pr->metadata, 'github_return_snapshot.pull_request.merged'));

        Http::assertSent(fn (HttpRequest $request) =>
            $request->method() === 'GET'
            && $request->url() === 'https://api.github.com/repos/1kz-ma1/HINANEX/pulls/55'
        );
    }

    public function test_installation_mismatch_is_ignored_without_github_api_read(): void
    {
        [, , $task, , $pr] = $this->scenario();
        Http::fake();

        $delivery = GitHubWebhookDelivery::query()->create([
            'delivery_id' => 'delivery-installation-mismatch-001',
            'event_name' => 'pull_request_review',
            'action' => 'submitted',
            'repo_full_name' => '1kz-ma1/HINANEX',
            'installation_id' => 999,
            'pull_request_numbers' => [55],
            'status' => 'accepted',
            'received_at' => now(),
        ]);

        $job = new ProcessGitHubWebhookDelivery((int) $delivery->id);
        $job->handle(
            app(GitHubReturnEvidenceService::class),
            app(GitHubWorkflowService::class),
            app(FeatureAccessService::class),
        );

        $delivery->refresh();

        $this->assertSame('ignored', $delivery->status);
        $this->assertSame(0, $delivery->matched_artifacts);
        $this->assertSame(0, $delivery->synced_tasks);
        $this->assertDatabaseMissing('task_evidences', [
            'task_id' => $task->id,
            'source' => 'github',
        ]);

        Http::assertNothingSent();
        $this->assertNotNull($pr->fresh());
    }

    public function test_background_sync_does_not_bypass_github_evidence_entitlement(): void
    {
        [, , $task] = $this->scenario(grantAccess: false);
        Http::fake();

        $delivery = GitHubWebhookDelivery::query()->create([
            'delivery_id' => 'delivery-no-entitlement-001',
            'event_name' => 'pull_request',
            'action' => 'closed',
            'repo_full_name' => '1kz-ma1/HINANEX',
            'installation_id' => 777,
            'pull_request_numbers' => [55],
            'status' => 'accepted',
            'received_at' => now(),
        ]);

        $job = new ProcessGitHubWebhookDelivery((int) $delivery->id);
        $job->handle(
            app(GitHubReturnEvidenceService::class),
            app(GitHubWorkflowService::class),
            app(FeatureAccessService::class),
        );

        $delivery->refresh();

        $this->assertSame('ignored', $delivery->status);
        $this->assertSame(1, $delivery->matched_artifacts);
        $this->assertSame(0, $delivery->synced_tasks);
        $this->assertSame(1, $delivery->skipped_entitlement);
        $this->assertDatabaseMissing('task_evidences', [
            'task_id' => $task->id,
            'source' => 'github',
        ]);

        Http::assertNothingSent();
    }

    public function test_workflow_run_without_pull_request_reference_is_acknowledged_and_ignored(): void
    {
        Queue::fake();

        $this->postWebhook('workflow_run', 'delivery-workflow-empty-001', [
            'action' => 'completed',
            'installation' => ['id' => 777],
            'repository' => ['full_name' => '1kz-ma1/HINANEX'],
            'workflow_run' => [
                'id' => 7001,
                'pull_requests' => [],
            ],
        ])
            ->assertStatus(202)
            ->assertJson(['status' => 'ignored_payload']);

        $this->assertDatabaseCount('github_webhook_deliveries', 0);
        Queue::assertNothingPushed();
    }

    private function postWebhook(
        string $event,
        string $delivery,
        array $payload,
    ) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

    private function pullRequestPayload(): array
    {
        return [
            'action' => 'closed',
            'installation' => ['id' => 777],
            'repository' => [
                'full_name' => '1kz-ma1/HINANEX',
            ],
            'number' => 55,
            'pull_request' => [
                'number' => 55,
                'merged' => true,
                'body' => 'untrusted body',
            ],
        ];
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
            'title' => 'HINANEX',
            'description' => 'Webhook return sync',
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
            'description' => 'GitHubの結果を自動同期する',
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
        $pr->tasks()->sync([$task->id]);

        return [$user, $plan, $task, $repository, $pr];
    }

    private function fakeMergedReturn(): void
    {
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'GET' && $url === 'https://api.github.com/repos/1kz-ma1/HINANEX/installation') {
                return Http::response(['id' => 777], 200);
            }

            if ($method === 'POST' && $url === 'https://api.github.com/app/installations/777/access_tokens') {
                return Http::response([
                    'token' => 'installation-token',
                    'permissions' => [
                        'metadata' => 'read',
                        'contents' => 'write',
                        'pull_requests' => 'write',
                    ],
                ], 201);
            }

            if ($method === 'GET' && $url === 'https://api.github.com/repos/1kz-ma1/HINANEX/pulls/55') {
                return Http::response([
                    'number' => 55,
                    'title' => 'Map interactionを修正',
                    'state' => 'closed',
                    'draft' => false,
                    'merged' => true,
                    'merged_at' => '2026-09-29T11:50:00Z',
                    'merged_by' => ['login' => 'maintainer-a'],
                    'merge_commit_sha' => str_repeat('m', 40),
                    'head' => [
                        'sha' => str_repeat('h', 40),
                        'ref' => 'canovia/test',
                    ],
                    'base' => ['ref' => 'main'],
                    'updated_at' => '2026-09-29T11:50:00Z',
                    'closed_at' => '2026-09-29T11:50:00Z',
                    'html_url' => 'https://github.com/1kz-ma1/HINANEX/pull/55',
                ], 200);
            }

            if ($method === 'GET' && str_starts_with($url, 'https://api.github.com/repos/1kz-ma1/HINANEX/pulls/55/reviews')) {
                return Http::response([[
                    'id' => 9001,
                    'state' => 'APPROVED',
                    'user' => ['login' => 'reviewer-a'],
                    'submitted_at' => '2026-09-29T11:45:00Z',
                    'commit_id' => str_repeat('h', 40),
                    'html_url' => 'https://github.com/1kz-ma1/HINANEX/pull/55#pullrequestreview-9001',
                ]], 200);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });
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
