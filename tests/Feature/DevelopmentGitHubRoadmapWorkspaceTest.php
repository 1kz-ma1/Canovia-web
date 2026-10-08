<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\UserProductGrant;
use Illuminate\Http\Client\Request as HttpRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DevelopmentGitHubRoadmapWorkspaceTest extends TestCase
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

    public function test_roadmap_surface_shows_connection_guidance_without_fetching_an_unlinked_repo(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        Http::fake();

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'roadmap',
            ]))
            ->assertOk()
            ->assertSee('data-development-roadmap', false)
            ->assertSee('GitHub接続を確認する')
            ->assertSee('READ ONLY')
            ->assertDontSee('data-roadmap-context-copy', false);

        Http::assertNothingSent();
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_unrelated_user_cannot_select_someone_elses_roadmap_plan(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $plan = $this->plan($owner);
        Http::fake();

        $this->actingAs($outsider)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'roadmap',
            ]))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_context_requires_auth_and_plan_ownership_before_github_fetch(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $plan = $this->plan($owner);
        Http::fake();

        $this->get(route('workspace.development.context', ['plan' => $plan->id]))
            ->assertRedirect();
        $this->actingAs($outsider)
            ->get(route('workspace.development.context', ['plan' => $plan->id]))
            ->assertForbidden();
        $this->actingAs($outsider)
            ->get(route('workspace.development.context', ['plan' => $plan->id, 'compare' => '1']))
            ->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_context_requires_linked_repository_and_valid_scope(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        Http::fake();
        $this->actingAs($owner)
            ->get(route('workspace.development.context', ['plan' => $plan->id]))
            ->assertStatus(409)
            ->assertJsonPath('error', 'repository_not_connected');
        $this->actingAs($owner)
            ->get(route('workspace.development.context', ['plan' => $plan->id, 'scope' => 'priority']))
            ->assertSessionHasErrors('priority');
        Http::assertNothingSent();
    }

    public function test_connected_public_roadmap_is_visible_without_writing_tasks(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);
        UserProductGrant::query()->create([
            'user_id' => $user->id,
            'product_key' => ProductKey::AllAccess,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
            'metadata' => ['test' => true],
        ]);
        PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'github',
            'artifact_type' => 'repository',
            'title' => 'Example',
            'url' => 'https://github.com/example/repo',
            'metadata' => [
                'github_app_connection' => [
                    'status' => 'connected',
                    'installation_id' => 777,
                    'read_ready' => true,
                    'write_ready' => false,
                    'permissions' => ['contents' => 'read', 'pull_requests' => 'read'],
                ],
            ],
        ]);
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);
        $pem = '';
        $this->assertTrue(openssl_pkey_export($key, $pem));
        config([
            'services.github.api_url' => 'https://api.github.com',
            'services.github.app_id' => '12345',
            'services.github.app_private_key' => $pem,
            'services.github.app_private_key_base64' => null,
            'services.github.app_install_url' => 'https://github.com/apps/canovia/installations/new',
        ]);

        $sha = str_repeat('f', 40);
        $previousSha = str_repeat('e', 40);
        $markdown = "## Workstreams\n| Priority | Workstream | Existing evidence | Remaining acceptance |\n| --- | --- | --- | --- |\n| P0 | Adaptive Learning | PR #12 merged | iPhone E2E pending |\n";
        $previousMarkdown = "## Workstreams\n| Priority | Workstream | Existing evidence | Remaining acceptance |\n| --- | --- | --- | --- |\n| P0 | Adaptive Learning | PR #12 merged | Browser E2E pending |\n";
        Http::fake(function (HttpRequest $request) use ($sha, $previousSha, $markdown, $previousMarkdown) {
            $url = $request->url();
            if ($url === 'https://api.github.com/repos/example/repo/installation') {
                return Http::response(['id' => 777], 200);
            }
            if ($url === 'https://api.github.com/app/installations/777/access_tokens') {
                return Http::response([
                    'token' => 'installation-token',
                    'permissions' => ['contents' => 'read', 'pull_requests' => 'read', 'checks' => 'read'],
                ], 201);
            }
            if ($url === 'https://api.github.com/repos/example/repo') {
                return Http::response([
                    'default_branch' => 'main',
                    'private' => false,
                    'visibility' => 'public',
                ], 200);
            }
            if ($url === 'https://api.github.com/repos/example/repo/commits/main') {
                return Http::response(['sha' => $sha], 200);
            }
            if (str_starts_with($url, 'https://api.github.com/repos/example/repo/commits?')) {
                return Http::response([['sha' => $sha], ['sha' => $previousSha]], 200);
            }
            if ($url === 'https://api.github.com/repos/example/repo/pulls/12') {
                return Http::response([
                    'number' => 12, 'state' => 'closed', 'merged' => true,
                    'base' => ['ref' => 'main'],
                    'head' => ['sha' => str_repeat('b', 40)],
                    'merge_commit_sha' => str_repeat('c', 40),
                ], 200);
            }
            if (str_contains($url, '/check-runs?')) {
                return Http::response(['total_count' => 1, 'check_runs' => [
                    ['status' => 'completed', 'conclusion' => 'success'],
                ]], 200);
            }
            if (str_starts_with($url, 'https://api.github.com/repos/example/repo/contents/docs/development/ROADMAP.md?')) {
                $body = str_contains($url, 'ref='.$previousSha) ? $previousMarkdown : $markdown;
                return Http::response([
                    'type' => 'file',
                    'encoding' => 'base64',
                    'content' => base64_encode($body),
                    'size' => strlen($body),
                ], 200);
            }
            return Http::response(['error' => 'unexpected'], 500);
        });

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'roadmap',
            ]))
            ->assertOk()
            ->assertSee('Adaptive Learning')
            ->assertSee('iPhone E2E pending')
            ->assertSee('GitHub記述 · 検証前')
            ->assertSee('data-roadmap-context-copy', false)
            ->assertSee('data-roadmap-context-scope="workstream"', false)
            ->assertSee('data-roadmap-context-title="Adaptive Learning"', false)
            ->assertSee('この項目をAIへ共有')
            ->assertSee('AI用Contextをコピー')
            ->assertSee('data-roadmap-compare', false)
            ->assertSee('仕様書の変更を確認')
            ->assertSee(route('workspace.development.context', ['plan' => $plan->id]));

        Http::assertSent(fn (HttpRequest $request) =>
            str_contains($request->url(), '/contents/docs/development/ROADMAP.md?ref='.$sha)
            && $request->hasHeader('Authorization', 'Bearer installation-token')
        );
        Http::assertNotSent(fn (HttpRequest $request) =>
            str_contains($request->url(), '/pulls/12')
        );

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'roadmap',
                'verify' => '1',
            ]))
            ->assertOk()
            ->assertSee('data-roadmap-pr-observation', false)
            ->assertSee('default branchへマージ確認')
            ->assertSee('PR headの取得済みChecks成功');

        $this->actingAs($user)
            ->get(route('workspace.development.context', ['plan' => $plan->id]))
            ->assertOk()
            ->assertJsonPath('schema', 'canovia.development_context.v1')
            ->assertJsonPath('scope', 'overview')
            ->assertJsonPath('matched', 1)
            ->assertJsonPath('items.0.title', 'Adaptive Learning')
            ->assertJsonPath('items.0.completion', 'unverified')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->actingAs($user)
            ->get(route('workspace.development.context', ['plan' => $plan->id, 'scope' => 'priority', 'priority' => 'P2']))
            ->assertOk()
            ->assertJsonPath('matched', 0)
            ->assertJsonCount(0, 'items');

        $this->actingAs($user)
            ->get(route('workspace.development.context', ['plan' => $plan->id, 'scope' => 'workstream', 'title' => 'Adaptive Learning', 'verify' => '1']))
            ->assertOk()
            ->assertJsonPath('items.0.pr_evidence.0.merge', 'merged_default')
            ->assertJsonPath('items.0.pr_evidence.0.ci', 'observed_pass');

        // A selected workstream is fetched only through an explicit, scoped GET.
        // Copying does not depend on AI credentials or create Task records.
        $this->actingAs($user)
            ->get(route('workspace.development.context', [
                'plan' => $plan->id,
                'scope' => 'workstream',
                'title' => 'Adaptive Learning',
                'limit' => 1,
            ]))
            ->assertOk()
            ->assertJsonPath('matched', 1)
            ->assertJsonPath('items.0.title', 'Adaptive Learning')
            ->assertJsonPath('items.0.completion', 'unverified')
            ->assertJsonPath('verification_requested', false)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->actingAs($user)
            ->get(route('workspace.development.context', [
                'plan' => $plan->id,
                'scope' => 'workstream',
                'title' => 'Not in this roadmap',
                'limit' => 1,
            ]))
            ->assertOk()
            ->assertJsonPath('matched', 0)
            ->assertJsonCount(0, 'items');

        $this->actingAs($user)
            ->get(route('workspace.development.context', ['plan' => $plan->id, 'compare' => '1']))
            ->assertOk()
            ->assertJsonPath('schema', 'canovia.development_roadmap_diff.v1')
            ->assertJsonPath('status', 'compared')
            ->assertJsonPath('before_sha', $previousSha)
            ->assertJsonPath('after_sha', $sha)
            ->assertJsonPath('changes.0.kind', 'changed')
            ->assertJsonPath('changes.0.title', 'Adaptive Learning')
            ->assertJsonPath('changes.0.before.next', 'Browser E2E pending')
            ->assertJsonPath('changes.0.after.next', 'iPhone E2E pending')
            ->assertJsonPath('completion', 'unverified')
            ->assertHeader('Cache-Control', 'no-store, private');

        Http::assertSent(fn (HttpRequest $request) =>
            str_contains($request->url(), '/commits?')
            && str_contains($request->url(), 'path=')
        );
        Http::assertSent(fn (HttpRequest $request) =>
            str_contains($request->url(), '/contents/docs/development/ROADMAP.md?ref='.$previousSha)
            && $request->hasHeader('Authorization', 'Bearer installation-token')
        );

        $this->actingAs($user)
            ->get(route('workspace.development.context', [
                'plan' => $plan->id, 'compare' => '1', 'verify' => '1',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'incompatible_comparison_options');

        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('task_evidences', 0);
    }

    private function plan(User $user): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Development',
            'description' => 'Development testing',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addWeeks(3),
            'is_public' => false,
        ]);
    }
}
