<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\Plan;
use App\Models\PlanActivityLog;
use App\Models\PlanArtifact;
use App\Models\PlanMember;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeveloperWorkspaceSurfacesV580Test extends TestCase
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

    public function test_default_surface_shows_current_work_and_dedicated_surface_tabs_only(): void
    {
        [$user, $plan] = $this->scenario();

        $this->task($plan, 'Current implementation');

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('data-development-surface="work"', false)
            ->assertSee('data-development-surface-panel="work"', false)
            ->assertSee('data-development-surface-tab="work"', false)
            ->assertSee('data-development-surface-tab="repository"', false)
            ->assertSee('data-development-surface-tab="team"', false)
            ->assertSee('data-development-surface-tab="improvements"', false)
            ->assertSee('data-development-surface-tab="preview"', false)
            ->assertSee('今やること')
            ->assertSee('リポジトリ')
            ->assertSee('チーム')
            ->assertSee('改善')
            ->assertSee('プレビュー')
            ->assertSee('data-development-context-details', false)
            ->assertDontSee('data-development-surface-panel="repository"', false)
            ->assertDontSee('data-development-surface-panel="team"', false)
            ->assertDontSee('data-development-surface-panel="improvements"', false)
            ->assertDontSee('data-development-surface-panel="preview"', false)
            ->assertDontSee('DEVELOPER HOME');
    }

    public function test_team_surface_uses_real_collaboration_and_assignment_state_without_inventing_task_owner(): void
    {
        [$owner, $plan] = $this->scenario([
            'is_collaborative' => true,
        ]);
        $memberUser = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        PlanMember::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $memberUser->id,
            'role' => PlanMember::ROLE_EDITOR,
            'invited_by_user_id' => $owner->id,
            'joined_at' => now()->subDay(),
        ]);

        $task = $this->task($plan, 'Repository browser');

        $artifact = PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $owner->id,
            'assigned_user_id' => $memberUser->id,
            'provider' => 'github',
            'artifact_type' => 'link',
            'title' => 'Repository surface PR',
            'url' => 'https://github.com/1kz-ma1/Canovia-web/pull/300',
            'metadata' => [
                'collaboration_state' => 'active',
            ],
        ]);
        $artifact->tasks()->sync([$task->id]);

        PlanActivityLog::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $memberUser->id,
            'action' => 'task_updated',
            'target_type' => 'task',
            'target_id' => $task->id,
            'metadata' => [
                'task_title' => $task->title,
            ],
            'created_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($owner)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'team',
            ]))
            ->assertOk()
            ->assertSee('data-development-surface="team"', false)
            ->assertSee('data-development-surface-panel="team"', false)
            ->assertSee($owner->name)
            ->assertSee($memberUser->name)
            ->assertSee('編集者')
            ->assertSee($task->title)
            ->assertSee('Repository surface PR')
            ->assertSee('Task担当者を推測して埋めることはしません。')
            ->assertSee('メンバー管理')
            ->assertDontSee('data-development-home-next-action', false);
    }

    public function test_preview_surface_requires_explicit_load_and_stores_only_safe_http_url(): void
    {
        [$user, $plan] = $this->scenario();
        $url = 'https://preview.example.com/app';

        $this->actingAs($user)
            ->post(route('plans.development_preview.store', $plan), [
                'url' => $url,
            ])
            ->assertRedirect(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'preview',
            ]));

        $this->assertDatabaseHas('plan_artifacts', [
            'plan_id' => $plan->id,
            'external_id' => 'canovia:development-preview',
            'url' => $url,
        ]);

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'preview',
            ]))
            ->assertOk()
            ->assertSee('data-development-surface-panel="preview"', false)
            ->assertSee('data-development-preview-load', false)
            ->assertSee('data-preview-url="'.$url.'"', false)
            ->assertSee('sandbox="allow-scripts allow-forms allow-popups allow-modals"', false)
            ->assertDontSee('src="'.$url.'"', false)
            ->assertSee('外部で開く');

        $this->actingAs($user)
            ->from(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'preview',
            ]))
            ->post(route('plans.development_preview.store', $plan), [
                'url' => 'http://localhost:3000',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('url');

        $this->assertDatabaseHas('plan_artifacts', [
            'plan_id' => $plan->id,
            'url' => $url,
        ]);

        $this->actingAs($user)
            ->delete(route('plans.development_preview.destroy', $plan))
            ->assertRedirect(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'preview',
            ]));

        $this->assertDatabaseMissing('plan_artifacts', [
            'plan_id' => $plan->id,
            'external_id' => 'canovia:development-preview',
        ]);
    }

    public function test_repository_tree_is_loaded_only_when_repository_surface_is_explicitly_opened(): void
    {
        [$user, $plan] = $this->scenario();
        $this->grantAllAccess($user);

        PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'github',
            'artifact_type' => 'repository',
            'title' => 'Canovia-web',
            'url' => 'https://github.com/1kz-ma1/Canovia-web',
            'metadata' => [
                'github_app_connection' => [
                    'status' => 'connected',
                    'installation_id' => 777,
                    'permissions' => [
                        'contents' => 'read',
                        'pull_requests' => 'read',
                    ],
                ],
            ],
        ]);

        config([
            'services.github.api_url' => 'https://api.github.com',
            'services.github.app_id' => '12345',
            'services.github.app_private_key' => $this->privateKey(),
            'services.github.app_private_key_base64' => null,
            'services.github.app_install_url' =>
                'https://github.com/apps/canovia-dev-integration/installations/new',
        ]);

        Http::fake(function (HttpRequest $request) {
            $url = $request->url();

            if (
                $request->method() === 'GET'
                && $url === 'https://api.github.com/repos/1kz-ma1/Canovia-web/installation'
            ) {
                return Http::response(['id' => 777], 200);
            }

            if (
                $request->method() === 'POST'
                && $url === 'https://api.github.com/app/installations/777/access_tokens'
            ) {
                return Http::response([
                    'token' => 'installation-token',
                    'permissions' => [
                        'contents' => 'read',
                        'pull_requests' => 'read',
                    ],
                ], 201);
            }

            if (
                $request->method() === 'GET'
                && $url === 'https://api.github.com/repos/1kz-ma1/Canovia-web'
            ) {
                return Http::response([
                    'full_name' => '1kz-ma1/Canovia-web',
                    'default_branch' => 'main',
                    'private' => true,
                    'visibility' => 'private',
                    'html_url' => 'https://github.com/1kz-ma1/Canovia-web',
                ], 200);
            }

            if (
                $request->method() === 'GET'
                && $url === 'https://api.github.com/repos/1kz-ma1/Canovia-web/commits/main'
            ) {
                return Http::response([
                    'sha' => str_repeat('a', 40),
                    'commit' => [
                        'tree' => [
                            'sha' => str_repeat('b', 40),
                        ],
                    ],
                ], 200);
            }

            if (
                $request->method() === 'GET'
                && str_starts_with(
                    $url,
                    'https://api.github.com/repos/1kz-ma1/Canovia-web/git/trees/'
                        .str_repeat('b', 40),
                )
            ) {
                return Http::response([
                    'sha' => str_repeat('b', 40),
                    'truncated' => false,
                    'tree' => [
                        [
                            'path' => 'app',
                            'type' => 'tree',
                            'sha' => str_repeat('c', 40),
                        ],
                        [
                            'path' => 'app/Services',
                            'type' => 'tree',
                            'sha' => str_repeat('d', 40),
                        ],
                        [
                            'path' => 'app/Services/DevelopmentWorkspaceSurfaceService.php',
                            'type' => 'blob',
                            'sha' => str_repeat('e', 40),
                            'size' => 4200,
                        ],
                        [
                            'path' => 'resources/views/workspace/development',
                            'type' => 'tree',
                            'sha' => str_repeat('f', 40),
                        ],
                    ],
                ], 200);
            }

            return Http::response([
                'message' => 'Unexpected request '.$request->method().' '.$url,
            ], 500);
        });

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('data-development-surface-panel="work"', false);

        Http::assertNothingSent();

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'repository',
            ]))
            ->assertOk()
            ->assertSee('data-development-surface-panel="repository"', false)
            ->assertSee('data-development-repository-tree', false)
            ->assertSee('DevelopmentWorkspaceSurfaceService.php')
            ->assertSee('resources/views/workspace/development')
            ->assertSee('private');

        Http::assertSent(fn (HttpRequest $request) =>
            $request->method() === 'GET'
            && str_contains($request->url(), '/git/trees/')
        );
    }

    public function test_unknown_surface_falls_back_to_current_work(): void
    {
        [$user, $plan] = $this->scenario();

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'everything-at-once',
            ]))
            ->assertOk()
            ->assertSee('data-development-surface="work"', false)
            ->assertSee('data-development-surface-panel="work"', false);
    }

    private function scenario(array $overrides = []): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create(array_merge([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Canovia Development',
            'description' => 'Focused Developer Workspace',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ], $overrides));

        return [$user, $plan];
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
