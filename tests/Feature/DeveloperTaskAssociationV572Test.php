<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\DevelopmentActivityObservation;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\DevelopmentTaskAssociationService;
use App\Services\DevelopmentTaskMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeveloperTaskAssociationV572Test extends TestCase
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

    public function test_explicit_task_id_creates_suggestion_but_not_relation(): void
    {
        [$user, $plan, $task, $root] = $this->scenario();

        $observation = $this->observation($plan, $root, [
            'kind' => 'branch',
            'ref' => 'feature/task-'.$task->id.'-github-webhook',
            'sha' => str_repeat('a', 40),
        ]);

        app(DevelopmentTaskMatchService::class)->refreshPlan($plan);

        $observation->refresh();

        $this->assertSame('suggested', $observation->resolution_status);
        $this->assertSame((int) $task->id, (int) $observation->suggested_task_id);
        $this->assertSame(1.0, (float) $observation->suggestion_confidence);
        $this->assertSame(
            'explicit_task_id',
            data_get($observation->suggestion_basis, 'method'),
        );
        $this->assertDatabaseCount('plan_artifact_task', 0);
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_ambiguous_match_stays_unlinked(): void
    {
        [, $plan, , $root] = $this->scenario(
            taskTitle: 'GitHub webhook routing fix',
        );

        Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'GitHub webhook routing repair',
            'description' => null,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 2,
            'activation_cost' => 2,
            'sort_order' => 2,
        ]);

        $observation = $this->observation($plan, $root, [
            'kind' => 'issue',
            'provider_number' => 19,
            'title' => 'GitHub webhook routing',
            'url' => 'https://github.com/1kz-ma1/HINANEX/issues/19',
        ]);

        app(DevelopmentTaskMatchService::class)->refreshPlan($plan);

        $observation->refresh();
        $this->assertSame('unlinked', $observation->resolution_status);
        $this->assertNull($observation->suggested_task_id);
        $this->assertNull($observation->suggestion_confidence);
        $this->assertDatabaseCount('plan_artifact_task', 0);
    }

    public function test_human_link_creates_artifact_relation_and_evidence_without_progress_change(): void
    {
        [, $plan, $task, $root] = $this->scenario(
            taskTitle: 'Fix GitHub webhook routing',
        );

        $observation = $this->observation($plan, $root, [
            'kind' => 'issue',
            'provider_number' => 12,
            'title' => 'Fix GitHub webhook routing',
            'state' => 'closed',
            'url' => 'https://github.com/1kz-ma1/HINANEX/issues/12',
        ]);

        $this->fakeIssue(12, 'closed');

        $before = $task->only([
            'status',
            'progress_percent',
            'remaining_minutes',
        ]);

        $result = app(DevelopmentTaskAssociationService::class)
            ->link($plan, $task, $observation);

        $this->assertNull($result['warning']);
        $this->assertTrue($result['evidence_synced']);

        $observation->refresh();
        $task->refresh();

        $this->assertSame('linked', $observation->resolution_status);
        $this->assertNotNull($observation->resolved_artifact_id);

        $artifact = PlanArtifact::query()
            ->findOrFail($observation->resolved_artifact_id);

        $this->assertSame(
            'https://github.com/1kz-ma1/HINANEX/issues/12',
            $artifact->url,
        );
        $this->assertTrue(
            $artifact->tasks()->whereKey($task->id)->exists(),
        );

        $evidence = TaskEvidence::query()
            ->where('task_id', $task->id)
            ->where('type', 'github_issue_observed')
            ->firstOrFail();

        $this->assertSame(12, data_get($evidence->metadata, 'issue_number'));
        $this->assertSame('closed', data_get($evidence->metadata, 'issue_state'));

        $this->assertSame($before['status'], $task->status);
        $this->assertSame($before['progress_percent'], $task->progress_percent);
        $this->assertSame($before['remaining_minutes'], $task->remaining_minutes);
    }

    public function test_ignore_removes_observation_from_future_suggestions(): void
    {
        [, $plan, , $root] = $this->scenario();

        $observation = $this->observation($plan, $root, [
            'kind' => 'issue',
            'provider_number' => 20,
            'title' => 'Unrelated GitHub issue',
            'url' => 'https://github.com/1kz-ma1/HINANEX/issues/20',
        ]);

        app(DevelopmentTaskAssociationService::class)
            ->ignore($plan, $observation);

        $observation->refresh();
        $this->assertSame('ignored', $observation->resolution_status);
        $this->assertNull($observation->suggested_task_id);

        $refreshed = app(DevelopmentTaskMatchService::class)
            ->refreshPlan($plan);

        $this->assertFalse($refreshed->contains('id', $observation->id));
    }

    public function test_development_workspace_renders_suggested_association_without_auto_link(): void
    {
        [$user, $plan, $task, $root] = $this->scenario(
            taskTitle: 'Fix GitHub webhook routing',
        );

        $observation = $this->observation($plan, $root, [
            'kind' => 'pull_request',
            'provider_number' => 259,
            'title' => 'Fix GitHub webhook routing',
            'state' => 'open',
            'ref' => 'feature/webhook-routing',
            'sha' => str_repeat('b', 40),
            'url' => 'https://github.com/1kz-ma1/HINANEX/pull/259',
        ]);

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('最近GitHubで何が起きたか')
            ->assertSee('Fix GitHub webhook routing')
            ->assertSee('関連付ける')
            ->assertSee('今回は無視');

        $observation->refresh();
        $this->assertSame((int) $task->id, (int) $observation->suggested_task_id);
        $this->assertDatabaseCount('plan_artifact_task', 0);
    }

    private function scenario(
        string $taskTitle = 'Developer V1を実装',
    ): array {
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
            'description' => 'Developer Task Association',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $taskTitle,
            'description' => null,
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

        return [$user, $plan, $task, $root];
    }

    private function observation(
        Plan $plan,
        PlanArtifact $root,
        array $overrides,
    ): DevelopmentActivityObservation {
        return DevelopmentActivityObservation::query()->create([
            'plan_id' => $plan->id,
            'repository_artifact_id' => $root->id,
            'provider' => 'github',
            'kind' => $overrides['kind'] ?? 'issue',
            'external_key' => 'github:1kz-ma1/hinanex:'
                .($overrides['kind'] ?? 'issue').':'
                .Str::lower(Str::random(12)),
            'provider_number' => $overrides['provider_number'] ?? null,
            'url' => $overrides['url'] ?? null,
            'title' => $overrides['title'] ?? null,
            'state' => $overrides['state'] ?? 'open',
            'ref' => $overrides['ref'] ?? null,
            'sha' => $overrides['sha'] ?? null,
            'occurred_at' => now()->subMinute(),
            'last_observed_at' => now(),
            'resolution_status' => 'unlinked',
        ]);
    }

    private function fakeIssue(int $number, string $state): void
    {
        Http::fake(function (HttpRequest $request) use ($number, $state) {
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
                        'issues' => 'read',
                    ],
                ], 201);
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
                    'updated_at' => '2026-10-06T02:30:00Z',
                    'closed_at' => $state === 'closed'
                        ? '2026-10-06T02:30:00Z'
                        : null,
                    'html_url' => 'https://github.com/1kz-ma1/HINANEX/issues/'.$number,
                ], 200);
            }

            return Http::response([
                'message' => 'Unexpected request '.$request->method().' '.$request->url(),
            ], 500);
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
