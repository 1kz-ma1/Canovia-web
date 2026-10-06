<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\DevelopmentActivityObservation;
use App\Models\GitHubWebhookDelivery;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\GitHubDevelopmentObservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeveloperActivityObservationV571Test extends TestCase
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
        ]);
    }

    public function test_unlinked_issue_becomes_observation_without_task_evidence(): void
    {
        [$plan, $task, $root] = $this->scenario();
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

        $result = app(GitHubDevelopmentObservationService::class)
            ->observeDelivery($delivery);

        $this->assertSame(1, $result['observed']);
        $this->assertSame([(int) $plan->id], $result['plan_ids']);
        $this->assertDatabaseCount('development_activity_observations', 1);
        $this->assertDatabaseCount('task_evidences', 0);

        $observation = DevelopmentActivityObservation::firstOrFail();
        $this->assertSame((int) $root->id, (int) $observation->repository_artifact_id);
        $this->assertSame('issue', $observation->kind);
        $this->assertSame(12, $observation->provider_number);
        $this->assertSame('closed', $observation->state);
        $this->assertNull($observation->title);
        $this->assertSame('unlinked', $observation->resolution_status);
        $this->assertNull($observation->suggested_task_id);

        $task->refresh();
        $this->assertSame($before['status'], $task->status);
        $this->assertSame($before['progress_percent'], $task->progress_percent);
        $this->assertSame($before['remaining_minutes'], $task->remaining_minutes);

        $serialized = json_encode(
            $observation->getAttributes(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $this->assertStringNotContainsString('PRIVATE ISSUE BODY', $serialized);
        $this->assertStringNotContainsString('PRIVATE ISSUE TITLE', $serialized);
    }

    public function test_repeated_delivery_updates_same_observation_idempotently(): void
    {
        $this->scenario();
        $this->fakeIssue(12, 'open');

        $service = app(GitHubDevelopmentObservationService::class);

        $service->observeDelivery($this->delivery(
            'issues',
            [['kind' => 'issue', 'number' => 12]],
        ));
        $first = DevelopmentActivityObservation::firstOrFail();

        $service->observeDelivery($this->delivery(
            'issues',
            [['kind' => 'issue', 'number' => 12]],
        ));

        $this->assertDatabaseCount('development_activity_observations', 1);
        $this->assertSame(
            (int) $first->id,
            (int) DevelopmentActivityObservation::firstOrFail()->id,
        );
    }

    public function test_connection_bootstrap_captures_bounded_existing_activity(): void
    {
        [, , $root] = $this->scenario();

        $prSha = str_repeat('a', 40);
        $commitSha = str_repeat('b', 40);
        $this->fakeBootstrap($prSha, $commitSha);

        $result = app(GitHubDevelopmentObservationService::class)
            ->bootstrapRepository($root);

        $this->assertSame(4, $result['observed']);
        $this->assertDatabaseCount('development_activity_observations', 4);
        $this->assertDatabaseCount('task_evidences', 0);

        $pr = DevelopmentActivityObservation::query()
            ->where('kind', 'pull_request')
            ->firstOrFail();
        $issue = DevelopmentActivityObservation::query()
            ->where('kind', 'issue')
            ->firstOrFail();
        $commit = DevelopmentActivityObservation::query()
            ->where('kind', 'commit')
            ->firstOrFail();

        $this->assertSame('Implement Developer V1', $pr->title);
        $this->assertSame('Fix webhook routing', $issue->title);
        $this->assertSame($prSha, $pr->sha);
        $this->assertSame($commitSha, $commit->sha);

        $serialized = DevelopmentActivityObservation::query()
            ->get()
            ->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString('PRIVATE PR BODY', $serialized);
        $this->assertStringNotContainsString('PRIVATE ISSUE BODY', $serialized);
        $this->assertStringNotContainsString('PRIVATE COMMIT MESSAGE', $serialized);
        $this->assertStringNotContainsString('private/source.php', $serialized);
    }

    private function scenario(): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        UserProductGrant::query()->create([
            'user_id' => $user->id,
            'product_key' => ProductKey::AllAccess,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
            'metadata' => ['test' => true],
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Canovia Development',
            'description' => 'Developer Activity Observation',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Developer V1を実装',
            'description' => 'GitHub activityを観測する',
            'estimated_minutes' => 120,
            'remaining_minutes' => 90,
            'progress_percent' => 25,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        $root = PlanArtifact::query()->create([
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

        return [$plan, $task, $root];
    }

    private function delivery(string $event, array $targets): GitHubWebhookDelivery
    {
        return GitHubWebhookDelivery::query()->create([
            'delivery_id' => 'delivery-v571-'.Str::lower(Str::random(18)),
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

    private function fakeIssue(int $number, string $state): void
    {
        Http::fake(function (HttpRequest $request) use ($number, $state) {
            $auth = $this->authResponse($request, [
                'issues' => 'read',
            ]);
            if ($auth !== null) {
                return $auth;
            }

            if (
                $request->method() === 'GET'
                && $request->url() === 'https://api.github.com/repos/1kz-ma1/HINANEX/issues/'.$number
            ) {
                return Http::response([
                    'number' => $number,
                    'title' => 'PRIVATE ISSUE TITLE',
                    'body' => 'PRIVATE ISSUE BODY',
                    'state' => $state,
                    'state_reason' => $state === 'closed' ? 'completed' : null,
                    'locked' => false,
                    'assignees' => [],
                    'updated_at' => '2026-10-06T01:00:00Z',
                    'closed_at' => $state === 'closed'
                        ? '2026-10-06T01:00:00Z'
                        : null,
                    'html_url' => 'https://github.com/1kz-ma1/HINANEX/issues/'.$number,
                ], 200);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });
    }

    private function fakeBootstrap(string $prSha, string $commitSha): void
    {
        Http::fake(function (HttpRequest $request) use ($prSha, $commitSha) {
            $auth = $this->authResponse($request, [
                'contents' => 'read',
                'pull_requests' => 'read',
                'issues' => 'read',
            ]);
            if ($auth !== null) {
                return $auth;
            }

            $url = $request->url();

            if (
                $request->method() === 'GET'
                && $url === 'https://api.github.com/repos/1kz-ma1/HINANEX'
            ) {
                return Http::response([
                    'full_name' => '1kz-ma1/HINANEX',
                    'default_branch' => 'main',
                ], 200);
            }

            if (
                $request->method() === 'GET'
                && str_starts_with(
                    $url,
                    'https://api.github.com/repos/1kz-ma1/HINANEX/pulls'
                )
            ) {
                return Http::response([[
                    'number' => 258,
                    'title' => 'Implement Developer V1',
                    'body' => 'PRIVATE PR BODY',
                    'state' => 'open',
                    'draft' => false,
                    'updated_at' => '2026-10-06T01:10:00Z',
                    'html_url' => 'https://github.com/1kz-ma1/HINANEX/pull/258',
                    'head' => [
                        'ref' => 'feature/developer-v1',
                        'sha' => $prSha,
                    ],
                ]], 200);
            }

            if (
                $request->method() === 'GET'
                && str_starts_with(
                    $url,
                    'https://api.github.com/repos/1kz-ma1/HINANEX/issues'
                )
            ) {
                return Http::response([[
                    'number' => 91,
                    'title' => 'Fix webhook routing',
                    'body' => 'PRIVATE ISSUE BODY',
                    'state' => 'open',
                    'updated_at' => '2026-10-06T01:20:00Z',
                    'html_url' => 'https://github.com/1kz-ma1/HINANEX/issues/91',
                ]], 200);
            }

            if (
                $request->method() === 'GET'
                && str_starts_with(
                    $url,
                    'https://api.github.com/repos/1kz-ma1/HINANEX/commits'
                )
            ) {
                return Http::response([[
                    'sha' => $commitSha,
                    'html_url' => 'https://github.com/1kz-ma1/HINANEX/commit/'.$commitSha,
                    'commit' => [
                        'message' => 'PRIVATE COMMIT MESSAGE',
                        'author' => ['date' => '2026-10-06T01:30:00Z'],
                        'committer' => ['date' => '2026-10-06T01:31:00Z'],
                    ],
                    'files' => [[
                        'filename' => 'private/source.php',
                    ]],
                ]], 200);
            }

            return Http::response([
                'message' => 'Unexpected request '.$request->method().' '.$url,
            ], 500);
        });
    }

    private function authResponse(
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
