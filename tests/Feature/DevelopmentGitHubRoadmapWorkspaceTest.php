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
            ->assertSee('READ ONLY');

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
        $markdown = "## Workstreams\n| Priority | Workstream | Existing evidence | Remaining acceptance |\n| --- | --- | --- | --- |\n| P0 | Adaptive Learning | PR merged | iPhone E2E pending |\n";
        Http::fake(function (HttpRequest $request) use ($sha, $markdown) {
            $url = $request->url();
            if ($url === 'https://api.github.com/repos/example/repo/installation') {
                return Http::response(['id' => 777], 200);
            }
            if ($url === 'https://api.github.com/app/installations/777/access_tokens') {
                return Http::response([
                    'token' => 'installation-token',
                    'permissions' => ['contents' => 'read'],
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
            if (str_starts_with($url, 'https://api.github.com/repos/example/repo/contents/docs/development/ROADMAP.md?')) {
                return Http::response([
                    'type' => 'file',
                    'encoding' => 'base64',
                    'content' => base64_encode($markdown),
                    'size' => strlen($markdown),
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
            ->assertSee('GitHub記述 · 検証前');

        Http::assertSent(fn (HttpRequest $request) =>
            str_contains($request->url(), '/contents/docs/development/ROADMAP.md?ref='.$sha)
            && $request->hasHeader('Authorization', 'Bearer installation-token')
        );
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
