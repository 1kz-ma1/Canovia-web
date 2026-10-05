<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\ExecutionGitHubHandoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExecutionGitHubHandoffV466Test extends TestCase
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

    public function test_execution_result_becomes_preview_candidate_without_remote_write(): void
    {
        [$user, $plan, $task, $repository] = $this->scenario();
        $this->importExecutionPacket($user, $plan, $task);
        $this->fakePreview('old content');

        $beforeArtifacts = PlanArtifact::query()->count();
        $beforeEvidence = TaskEvidence::query()->count();

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.github.prepare', [$plan, $task]), [
                'repository_artifact_id' => $repository->id,
                'file_path' => 'app/Services/MapService.php',
                'file_content' => "new content\n",
                'commit_message' => 'fix map interaction',
                'pull_request_title' => 'Map interactionを修正',
                'pull_request_body' => 'Execution resultをレビューへ渡します。',
            ])
            ->assertRedirect(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'GitHubへ送る変更候補を準備しました。内容を確認してからレビューに出してください。');

        $this->assertSame($beforeArtifacts, PlanArtifact::query()->count());
        $this->assertSame($beforeEvidence, TaskEvidence::query()->count());

        $storedCandidate = session(ExecutionGitHubHandoffService::sessionKey($plan, $task));
        $this->assertIsArray($storedCandidate);
        $this->assertNull(data_get($storedCandidate, 'change.content'));
        $this->assertNotEmpty(data_get($storedCandidate, 'change.content_encrypted'));
        $this->assertStringNotContainsString(
            'new content',
            json_encode($storedCandidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        Http::assertNotSent(fn (HttpRequest $request) =>
            ($request->method() === 'POST' && str_contains($request->url(), '/git/refs'))
            || ($request->method() === 'PUT' && str_contains($request->url(), '/contents/'))
            || ($request->method() === 'POST' && str_ends_with($request->url(), '/pulls'))
        );

        $this->actingAs($user)
            ->get(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('GITHUB HANDOFF')
            ->assertSee('HUMAN CONFIRMATION')
            ->assertSee('この内容をGitHubへ送りますか？')
            ->assertSee('app/Services/MapService.php')
            ->assertSee('new content');
    }

    public function test_human_confirmation_creates_review_pr_linked_to_task_without_progress_or_evidence(): void
    {
        [$user, $plan, $task, $repository] = $this->scenario();
        $this->importExecutionPacket($user, $plan, $task);
        $this->fakePreview('old content');

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.github.prepare', [$plan, $task]), [
                'repository_artifact_id' => $repository->id,
                'file_path' => 'app/Services/MapService.php',
                'file_content' => "new content\n",
                'commit_message' => 'fix map interaction',
                'pull_request_title' => 'Map interactionを修正',
            ])
            ->assertSessionHasNoErrors();

        $beforeEvidence = TaskEvidence::query()->count();
        $beforeProgress = $task->fresh()->progress_percent;

        $this->fakeConfirm('old content', str_repeat('b', 40));

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.github.confirm', [$plan, $task]))
            ->assertRedirect(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '確認した変更をGitHubのレビュー用Pull Requestへ反映しました。Task進捗はまだ変更していません。');

        $pr = PlanArtifact::query()
            ->where('provider', 'github')
            ->where('artifact_type', 'link')
            ->where('external_id', '55')
            ->firstOrFail();

        $this->assertSame('review', $pr->githubWorkflowState());
        $this->assertSame('execution_github_handoff', data_get($pr->metadata, 'github_write_origin.source'));
        $this->assertSame($task->id, data_get($pr->metadata, 'github_write_origin.target_task_id'));
        $this->assertTrue($pr->tasks()->whereKey($task->id)->exists());

        $this->assertSame($beforeProgress, $task->fresh()->progress_percent);
        $this->assertSame($beforeEvidence, TaskEvidence::query()->count());

        Http::assertSent(fn (HttpRequest $request) =>
            $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/repos/1kz-ma1/HINANEX/git/refs'
            && str_starts_with((string) data_get($request->data(), 'ref'), 'refs/heads/canovia/')
        );
        Http::assertSent(fn (HttpRequest $request) =>
            $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/repos/1kz-ma1/HINANEX/pulls'
        );
        Http::assertNotSent(fn (HttpRequest $request) =>
            str_contains($request->url(), '/merge')
            || $request->method() === 'DELETE'
        );

        $this->actingAs($user)
            ->get(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('レビュー用Pull Requestへ反映済み')
            ->assertSee('PR #55 をGitHubで確認');
    }

    public function test_context_change_after_preview_blocks_github_write(): void
    {
        [$user, $plan, $task, $repository] = $this->scenario();
        $this->importExecutionPacket($user, $plan, $task);
        $this->fakePreview('old content');

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.github.prepare', [$plan, $task]), [
                'repository_artifact_id' => $repository->id,
                'file_path' => 'app/Services/MapService.php',
                'file_content' => "new content\n",
                'commit_message' => 'fix map interaction',
                'pull_request_title' => 'Map interactionを修正',
            ])
            ->assertSessionHasNoErrors();

        $task->update([
            'description' => 'preview後に変更されたscope',
        ]);

        Http::fake();

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.github.confirm', [$plan, $task]))
            ->assertRedirect(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertSessionHas(
                'status',
                '変更候補を確認した後にPlan / TaskのContextが変わっています。現在Contextから候補を作り直してください。',
            );

        Http::assertNotSent(fn (HttpRequest $request) =>
            ($request->method() === 'POST' && str_contains($request->url(), '/git/refs'))
            || ($request->method() === 'PUT' && str_contains($request->url(), '/contents/'))
            || ($request->method() === 'POST' && str_ends_with($request->url(), '/pulls'))
        );
        $this->assertDatabaseMissing('plan_artifacts', ['external_id' => '55']);
    }

    public function test_file_change_after_preview_is_rejected_before_branch_creation(): void
    {
        [$user, $plan, $task, $repository] = $this->scenario();
        $this->importExecutionPacket($user, $plan, $task);
        $this->fakePreview('old content', str_repeat('b', 40));

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.github.prepare', [$plan, $task]), [
                'repository_artifact_id' => $repository->id,
                'file_path' => 'app/Services/MapService.php',
                'file_content' => "new content\n",
                'commit_message' => 'fix map interaction',
                'pull_request_title' => 'Map interactionを修正',
            ])
            ->assertSessionHasNoErrors();

        $this->fakeConfirm('someone else changed this', str_repeat('c', 40), failBeforeWrite: true);

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.github.confirm', [$plan, $task]))
            ->assertRedirect(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertSessionHas(
                'status',
                '確認後に対象ファイルが更新されています。上書きを避けるため変更候補を作り直してください。',
            );

        Http::assertNotSent(fn (HttpRequest $request) =>
            $request->method() === 'POST'
            && str_contains($request->url(), '/git/refs')
        );
        $this->assertDatabaseMissing('plan_artifacts', ['external_id' => '55']);
    }

    public function test_change_candidate_requires_current_execution_packet(): void
    {
        [$user, $plan, $task, $repository] = $this->scenario();
        Http::fake();

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.github.prepare', [$plan, $task]), [
                'repository_artifact_id' => $repository->id,
                'file_path' => 'README.md',
                'file_content' => 'new',
                'commit_message' => 'update readme',
                'pull_request_title' => 'README更新',
            ])
            ->assertRedirect(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertSessionHasErrors('github_change');

        Http::assertNothingSent();
    }

    private function scenario(): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $this->grantAllAccess($user);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'HINANEX',
            'description' => 'Execution resultをGitHubレビューへ渡す',
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
            'description' => 'ズーム操作の挙動を修正する',
            'estimated_minutes' => 90,
            'remaining_minutes' => 60,
            'progress_percent' => 20,
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

        return [$user, $plan, $task, $repository];
    }

    private function importExecutionPacket(User $user, Plan $plan, Task $task): void
    {
        $packet = [
            'schema_version' => '1.0',
            'flow' => 'execution_packet',
            'summary' => 'Mapの修正を実行',
            'execution_mode' => 'execute',
            'current_situation' => '修正対象は確認済み',
            'role' => 'Implementation',
            'objective' => 'Map interactionを修正する',
            'reason' => '選択Taskのscope内',
            'actions' => [[
                'title' => 'MapServiceを修正',
                'details' => '既存scopeを越えない',
                'estimated_minutes' => 30,
            ]],
            'inputs' => [],
            'outputs' => ['MapService.php'],
            'dependencies' => [],
            'assumptions' => [],
            'do_not_touch' => ['他Task'],
            'completion_criteria' => ['変更内容をレビューへ渡せる'],
            'confirmation_required' => [],
            'next_phase' => 'Pull Request review',
        ];

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.import', [$plan, $task]), [
                'packet_json' => json_encode($packet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ])
            ->assertSessionHasNoErrors();
    }

    private function fakePreview(string $currentContent, ?string $fileSha = null): void
    {
        $fileSha ??= str_repeat('b', 40);

        Http::fake(function (HttpRequest $request) use ($currentContent, $fileSha) {
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

            if ($method === 'GET' && $url === 'https://api.github.com/repos/1kz-ma1/HINANEX') {
                return Http::response([
                    'full_name' => '1kz-ma1/HINANEX',
                    'archived' => false,
                    'default_branch' => 'main',
                ], 200);
            }

            if ($method === 'GET' && str_starts_with($url, 'https://api.github.com/repos/1kz-ma1/HINANEX/contents/app/Services/MapService.php')) {
                return Http::response([
                    'type' => 'file',
                    'sha' => $fileSha,
                    'size' => strlen($currentContent),
                    'encoding' => 'base64',
                    'content' => base64_encode($currentContent),
                ], 200);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });
    }

    private function fakeConfirm(
        string $currentContent,
        string $fileSha,
        bool $failBeforeWrite = false,
    ): void {
        Http::fake(function (HttpRequest $request) use ($currentContent, $fileSha, $failBeforeWrite) {
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

            if ($method === 'GET' && $url === 'https://api.github.com/repos/1kz-ma1/HINANEX') {
                return Http::response([
                    'full_name' => '1kz-ma1/HINANEX',
                    'archived' => false,
                    'default_branch' => 'main',
                ], 200);
            }

            if ($method === 'GET' && str_contains($url, '/repos/1kz-ma1/HINANEX/git/ref/heads/main')) {
                return Http::response([
                    'object' => ['sha' => str_repeat('a', 40)],
                ], 200);
            }

            if ($method === 'GET' && str_starts_with($url, 'https://api.github.com/repos/1kz-ma1/HINANEX/contents/app/Services/MapService.php')) {
                return Http::response([
                    'type' => 'file',
                    'sha' => $fileSha,
                    'size' => strlen($currentContent),
                    'encoding' => 'base64',
                    'content' => base64_encode($currentContent),
                ], 200);
            }

            if ($failBeforeWrite) {
                return Http::response(['message' => 'Unexpected write'], 500);
            }

            if ($method === 'POST' && str_contains($url, '/repos/1kz-ma1/HINANEX/git/refs')) {
                return Http::response([
                    'ref' => data_get($request->data(), 'ref'),
                    'object' => ['sha' => str_repeat('a', 40)],
                ], 201);
            }

            if ($method === 'PUT' && str_contains($url, '/repos/1kz-ma1/HINANEX/contents/app/Services/MapService.php')) {
                return Http::response([
                    'commit' => ['sha' => str_repeat('d', 40)],
                ], 200);
            }

            if ($method === 'POST' && str_contains($url, '/repos/1kz-ma1/HINANEX/pulls')) {
                return Http::response([
                    'number' => 55,
                    'title' => 'Map interactionを修正',
                    'state' => 'open',
                    'html_url' => 'https://github.com/1kz-ma1/HINANEX/pull/55',
                ], 201);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
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
