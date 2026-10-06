<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Enums\ProductKey;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeveloperProviderTriageV577Test extends TestCase
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
            'native_ai.driver' => 'disabled',
        ]);
    }

    public function test_ci_triage_fetches_provider_failure_detail_without_persisting_provider_text(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $sha = str_repeat('a', 40);
        $this->pullEvidence($task, $sha);
        $this->ciEvidence($task, $sha, 'failure');

        $beforeEvidence = TaskEvidence::query()->count();

        Http::fake(function (HttpRequest $request) use ($sha) {
            $url = $request->url();

            if ($request->method() === 'GET'
                && $url === 'https://api.github.com/repos/1kz-ma1/Canovia-web/installation') {
                return Http::response(['id' => 777], 200);
            }

            if ($request->method() === 'POST'
                && $url === 'https://api.github.com/app/installations/777/access_tokens') {
                return Http::response([
                    'token' => 'installation-token',
                    'permissions' => [
                        'pull_requests' => 'read',
                        'actions' => 'read',
                        'checks' => 'read',
                        'statuses' => 'read',
                    ],
                ], 201);
            }

            if ($request->method() === 'GET'
                && $url === 'https://api.github.com/repos/1kz-ma1/Canovia-web/pulls/264') {
                return Http::response($this->pullPayload($sha), 200);
            }

            if ($request->method() === 'GET'
                && str_starts_with($url, 'https://api.github.com/repos/1kz-ma1/Canovia-web/actions/runs?')) {
                return Http::response([
                    'workflow_runs' => [[
                        'id' => 9001,
                        'run_attempt' => 1,
                        'name' => 'CI',
                        'status' => 'completed',
                        'conclusion' => 'failure',
                        'head_sha' => $sha,
                        'updated_at' => '2026-10-06T03:31:00Z',
                        'html_url' => 'https://github.com/1kz-ma1/Canovia-web/actions/runs/9001',
                    ]],
                ], 200);
            }

            if ($request->method() === 'GET'
                && str_starts_with($url, 'https://api.github.com/repos/1kz-ma1/Canovia-web/actions/runs/9001/jobs?')) {
                return Http::response([
                    'jobs' => [[
                        'id' => 9101,
                        'name' => 'php-tests',
                        'status' => 'completed',
                        'conclusion' => 'failure',
                        'html_url' => 'https://github.com/1kz-ma1/Canovia-web/actions/runs/9001/job/9101',
                        'steps' => [
                            [
                                'number' => 1,
                                'name' => 'Checkout',
                                'conclusion' => 'success',
                            ],
                            [
                                'number' => 2,
                                'name' => 'Run feature tests',
                                'conclusion' => 'failure',
                            ],
                        ],
                    ]],
                ], 200);
            }

            if ($request->method() === 'GET'
                && str_starts_with($url, 'https://api.github.com/repos/1kz-ma1/Canovia-web/commits/'.$sha.'/check-runs?')) {
                return Http::response([
                    'check_runs' => [[
                        'id' => 9201,
                        'name' => 'PHPUnit',
                        'status' => 'completed',
                        'conclusion' => 'failure',
                        'completed_at' => '2026-10-06T03:31:00Z',
                        'html_url' => 'https://github.com/1kz-ma1/Canovia-web/runs/9201',
                    ]],
                ], 200);
            }

            if ($request->method() === 'GET'
                && str_starts_with($url, 'https://api.github.com/repos/1kz-ma1/Canovia-web/check-runs/9201/annotations?')) {
                return Http::response([[
                    'path' => 'tests/Feature/DeveloperProviderTriageV577Test.php',
                    'start_line' => 88,
                    'end_line' => 88,
                    'annotation_level' => 'failure',
                    'title' => 'Assertion failed',
                    'message' => 'Expected CI state success but failure was observed.',
                ]], 200);
            }

            if ($request->method() === 'GET'
                && $url === 'https://api.github.com/repos/1kz-ma1/Canovia-web/commits/'.$sha.'/status') {
                return Http::response([
                    'state' => 'failure',
                    'total_count' => 1,
                    'statuses' => [[
                        'id' => 9301,
                        'state' => 'failure',
                        'context' => 'ci/php',
                        'description' => 'Feature tests failed',
                        'updated_at' => '2026-10-06T03:31:00Z',
                        'target_url' => 'https://github.com/1kz-ma1/Canovia-web/actions/runs/9001',
                    ]],
                ], 200);
            }

            return Http::response([
                'message' => 'Unexpected request '.$request->method().' '.$url,
            ], 500);
        });

        $response = $this->actingAs($user)
            ->post(route('plans.development_provider_triage.inspect', [
                $plan,
                $task,
            ]), [
                'mode' => 'ci',
            ])
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->assertSee('PROVIDER-LINKED TRIAGE')
            ->assertSee('CI DETAIL')
            ->assertSee('Run feature tests')
            ->assertSee('Assertion failed')
            ->assertSee('Expected CI state success but failure was observed.')
            ->assertSee('ci/php')
            ->assertSee('このTriageを実装ツールへ渡す');

        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );

        $this->assertSame(
            $beforeEvidence,
            TaskEvidence::query()->count(),
        );

        $persisted = json_encode(
            TaskEvidence::query()->pluck('metadata')->all(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $this->assertIsString($persisted);
        $this->assertStringNotContainsString(
            'Expected CI state success but failure was observed.',
            $persisted,
        );
    }

    public function test_review_triage_fetches_bounded_review_text_without_persisting_it(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $sha = str_repeat('b', 40);
        $this->pullEvidence($task, $sha);
        $this->reviewEvidence($task, 'CHANGES_REQUESTED');

        $longBody = str_repeat('A', 1900).'TAIL_MUST_NOT_APPEAR';

        Http::fake(function (HttpRequest $request) use ($sha, $longBody) {
            $url = $request->url();

            if ($request->method() === 'GET'
                && $url === 'https://api.github.com/repos/1kz-ma1/Canovia-web/installation') {
                return Http::response(['id' => 777], 200);
            }

            if ($request->method() === 'POST'
                && $url === 'https://api.github.com/app/installations/777/access_tokens') {
                return Http::response([
                    'token' => 'installation-token',
                    'permissions' => [
                        'pull_requests' => 'read',
                    ],
                ], 201);
            }

            if ($request->method() === 'GET'
                && $url === 'https://api.github.com/repos/1kz-ma1/Canovia-web/pulls/264') {
                return Http::response($this->pullPayload($sha), 200);
            }

            if ($request->method() === 'GET'
                && str_starts_with($url, 'https://api.github.com/repos/1kz-ma1/Canovia-web/pulls/264/reviews?')) {
                return Http::response([[
                    'id' => 9401,
                    'state' => 'CHANGES_REQUESTED',
                    'user' => ['login' => 'reviewer-a'],
                    'body' => $longBody,
                    'submitted_at' => '2026-10-06T03:32:00Z',
                    'commit_id' => $sha,
                    'html_url' => 'https://github.com/1kz-ma1/Canovia-web/pull/264#pullrequestreview-9401',
                ]], 200);
            }

            if ($request->method() === 'GET'
                && str_starts_with($url, 'https://api.github.com/repos/1kz-ma1/Canovia-web/pulls/264/comments?')) {
                return Http::response([[
                    'id' => 9501,
                    'user' => ['login' => 'reviewer-a'],
                    'body' => 'Null時のfallbackを追加してください。',
                    'path' => 'app/Services/DevelopmentProviderTriageService.php',
                    'line' => 42,
                    'side' => 'RIGHT',
                    'created_at' => '2026-10-06T03:32:00Z',
                    'updated_at' => '2026-10-06T03:32:00Z',
                    'html_url' => 'https://github.com/1kz-ma1/Canovia-web/pull/264#discussion_r9501',
                ]], 200);
            }

            return Http::response([
                'message' => 'Unexpected request '.$request->method().' '.$url,
            ], 500);
        });

        $beforeEvidence = TaskEvidence::query()->count();

        $this->actingAs($user)
            ->post(route('plans.development_provider_triage.inspect', [
                $plan,
                $task,
            ]), [
                'mode' => 'review',
            ])
            ->assertOk()
            ->assertSee('REVIEW DETAIL')
            ->assertSee('CHANGES_REQUESTED')
            ->assertSee('reviewer-a')
            ->assertSee('Null時のfallbackを追加してください。')
            ->assertSee('app/Services/DevelopmentProviderTriageService.php')
            ->assertDontSee('TAIL_MUST_NOT_APPEAR');

        $this->assertSame(
            $beforeEvidence,
            TaskEvidence::query()->count(),
        );

        $persisted = json_encode(
            TaskEvidence::query()->pluck('metadata')->all(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $this->assertIsString($persisted);
        $this->assertStringNotContainsString(
            'Null時のfallbackを追加してください。',
            $persisted,
        );
    }

    public function test_developer_home_exposes_ci_triage_entry_without_remote_fetch(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $sha = str_repeat('c', 40);
        $this->pullEvidence($task, $sha);
        $this->ciEvidence($task, $sha, 'failure');

        Http::fake();

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-development-provider-triage-entry',
                false,
            )
            ->assertSee('CI失敗の詳細を取得')
            ->assertSee(
                route('plans.development_provider_triage.inspect', [
                    $plan,
                    $task,
                ]),
                false,
            )
            ->assertSee('取得した本文やannotationはCanoviaへ保存しません。');

        Http::assertNothingSent();
    }

    public function test_provider_triage_requires_task_from_same_plan(): void
    {
        [$user, $plan] = $this->scenario();

        $otherPlan = $this->plan($user, 'Other Development');
        $otherTask = $this->task($otherPlan, 'Other Task');

        $this->actingAs($user)
            ->post(route('plans.development_provider_triage.inspect', [
                $plan,
                $otherTask,
            ]), [
                'mode' => 'ci',
            ])
            ->assertNotFound();
    }

    private function scenario(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $this->grantAllAccess($user);

        $plan = $this->plan($user, 'Canovia Development');
        $task = $this->task(
            $plan,
            'V57.7 Provider-linked Triage',
        );

        return [$user, $plan, $task];
    }

    private function plan(User $user, string $title): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => 'Provider-linked triage',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => null,
            'estimated_minutes' => 120,
            'remaining_minutes' => 80,
            'progress_percent' => 35,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);
    }

    private function pullEvidence(Task $task, string $sha): TaskEvidence
    {
        return $this->evidence($task, 'pull_request_observed', [
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'pull_request_number' => 264,
            'pull_request_url' => 'https://github.com/1kz-ma1/Canovia-web/pull/264',
            'state' => 'open',
            'draft' => false,
            'merged' => false,
            'head_sha' => $sha,
            'head_ref' => 'feature/v57-7-provider-linked-triage',
            'base_ref' => 'main',
            'updated_at' => now()->subMinute()->toIso8601String(),
        ]);
    }

    private function ciEvidence(
        Task $task,
        string $sha,
        string $state,
    ): TaskEvidence {
        return $this->evidence($task, 'pull_request_ci_observed', [
            'repo_full_name' => '1kz-ma1/Canovia-web',
            'pull_request_number' => 264,
            'pull_request_url' => 'https://github.com/1kz-ma1/Canovia-web/pull/264',
            'head_sha' => $sha,
            'ci_state' => $state,
        ]);
    }

    private function reviewEvidence(
        Task $task,
        string $state,
    ): TaskEvidence {
        return $this->evidence(
            $task,
            'pull_request_review_submitted',
            [
                'repo_full_name' => '1kz-ma1/Canovia-web',
                'pull_request_number' => 264,
                'pull_request_url' => 'https://github.com/1kz-ma1/Canovia-web/pull/264',
                'review_id' => 8001,
                'review_state' => $state,
                'reviewer' => 'reviewer-a',
            ],
        );
    }

    private function evidence(
        Task $task,
        string $type,
        array $metadata,
    ): TaskEvidence {
        return TaskEvidence::query()->create([
            'plan_id' => $task->plan_id,
            'task_id' => $task->id,
            'user_id' => $task->plan?->user_id,
            'source' => EvidenceSource::GitHub->value,
            'type' => $type,
            'external_key' => $type.':'.Str::uuid(),
            'confidence' => 1,
            'occurred_at' => now()->subMinute(),
            'metadata' => $metadata,
        ]);
    }

    private function pullPayload(string $sha): array
    {
        return [
            'number' => 264,
            'title' => 'V57.7 Provider-linked Triage',
            'state' => 'open',
            'draft' => false,
            'head' => [
                'sha' => $sha,
                'ref' => 'feature/v57-7-provider-linked-triage',
            ],
            'base' => [
                'ref' => 'main',
            ],
            'html_url' => 'https://github.com/1kz-ma1/Canovia-web/pull/264',
        ];
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
