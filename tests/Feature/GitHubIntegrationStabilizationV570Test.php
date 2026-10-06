<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\GitHubWebhookDelivery;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\AdminAccessService;
use App\Services\AdminPreviewContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class GitHubIntegrationStabilizationV570Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'session.driver' => 'array',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'services.github.api_url' => 'https://api.github.com',
            'services.github.app_id' => null,
            'services.github.app_private_key' => null,
            'services.github.app_private_key_base64' => null,
            'services.github.app_install_url' => null,
            'services.github.app_webhook_secret' => null,
            'queue.default' => 'database',
        ]);
    }

    public function test_webhook_delivery_model_uses_canonical_table_name(): void
    {
        $this->assertSame(
            'github_webhook_deliveries',
            (new GitHubWebhookDelivery())->getTable(),
        );
    }

    public function test_admin_diagnostics_stays_available_when_webhook_table_is_missing(): void
    {
        $admin = $this->user();

        Schema::dropIfExists('github_webhook_deliveries');

        $this->actingAs($admin)
            ->withSession([
                AdminAccessService::SESSION_KEY => true,
            ])
            ->get(route('admin.github.index'))
            ->assertOk()
            ->assertSee('GitHub連携の本番状態を確認')
            ->assertSee('DB SCHEMA')
            ->assertSee('要確認')
            ->assertSee('github_webhook_deliveries')
            ->assertSee('CANOVIA / DB');
    }

    public function test_admin_diagnostics_separates_app_credentials_from_install_url(): void
    {
        $admin = $this->user();

        config([
            'services.github.app_id' => '12345',
            'services.github.app_private_key' => $this->privateKey(),
            'services.github.app_install_url' => null,
        ]);

        $this->actingAs($admin)
            ->withSession([
                AdminAccessService::SESSION_KEY => true,
            ])
            ->get(route('admin.github.index'))
            ->assertOk()
            ->assertSee('Credential設定済み')
            ->assertSee('INSTALL URL')
            ->assertSee('Repository選択へ進むGitHub App URL')
            ->assertSee('GitHub App Install URL');
    }

    public function test_super_admin_free_and_premium_preview_get_actionable_redirect_instead_of_403(): void
    {
        $admin = $this->user();
        $plan = $this->plan($admin);
        $repo = $this->repository($plan, $admin);

        config([
            'canovia.super_admin_user_id' => $admin->id,
            'services.github.app_id' => '12345',
            'services.github.app_private_key' => $this->privateKey(),
            'services.github.app_install_url' =>
                'https://github.com/apps/canovia/installations/new',
        ]);

        foreach (['free', 'premium'] as $mode) {
            $response = $this->actingAs($admin)
                ->withSession([
                    AdminPreviewContext::SESSION_KEY => $mode,
                ])
                ->post(route(
                    'github_workflow.app.connect',
                    $repo,
                ));

            $response
                ->assertRedirect(route(
                    'github_workflow.index',
                    ['plan_id' => $plan->id],
                ))
                ->assertSessionHas(
                    'status',
                    fn (string $message) =>
                        str_contains(
                            $message,
                            $mode === 'free'
                                ? 'Freeプレビュー中'
                                : 'Premiumプレビュー中',
                        )
                        && str_contains(
                            $message,
                            'Super Admin表示へ戻してください',
                        ),
                );
        }

        $this->assertNull(
            data_get(
                $repo->fresh()->metadata,
                'github_app_connection.status',
            ),
        );
    }

    public function test_normal_connection_entitlement_denial_uses_evidence_capability(): void
    {
        $user = $this->user();
        $plan = $this->plan($user);
        $repo = $this->repository($plan, $user);

        $this->actingAs($user)
            ->post(route(
                'github_workflow.app.connect',
                $repo,
            ))
            ->assertRedirect(route(
                'github_workflow.index',
                ['plan_id' => $plan->id],
            ))
            ->assertSessionHas(
                'status',
                fn (string $message) =>
                    str_contains(
                        $message,
                        '現在の利用権ではDeveloper GitHub Evidenceを利用できません',
                    ),
            );
    }

    public function test_normal_evidence_entitlement_denial_redirects_before_remote_request(): void
    {
        $user = $this->user();
        $plan = $this->plan($user);
        $repo = $this->repository($plan, $user);

        $this->actingAs($user)
            ->post(route(
                'github_workflow.repository.refresh',
                $repo,
            ))
            ->assertRedirect(route(
                'github_workflow.index',
                ['plan_id' => $plan->id],
            ))
            ->assertSessionHas(
                'status',
                fn (string $message) =>
                    str_contains(
                        $message,
                        '現在の利用権ではDeveloper GitHub Evidenceを利用できません',
                    ),
            );
    }

    public function test_workflow_renders_separate_read_write_app_and_auto_sync_readiness(): void
    {
        $user = $this->user();
        $this->grantAllAccess($user);

        $plan = $this->plan($user);
        $repo = $this->repository(
            $plan,
            $user,
            connected: true,
        );

        config([
            'services.github.app_id' => '12345',
            'services.github.app_private_key' => $this->privateKey(),
            'services.github.app_install_url' =>
                'https://github.com/apps/canovia/installations/new',
            'services.github.app_webhook_secret' =>
                'webhook-secret',
            'queue.default' => 'database',
        ]);

        $this->actingAs($user)
            ->get(route('github_workflow.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-github-integration-readiness',
                false,
            )
            ->assertSee('REPOSITORY / EVIDENCE')
            ->assertSee('REVIEW WRITE')
            ->assertSee('GITHUB APP SERVER')
            ->assertSee('AUTO RETURN SYNC')
            ->assertSee('Capabilityあり')
            ->assertSee('接続導線Ready')
            ->assertSee('Server設定済み')
            ->assertSee('GitHub接続済み')
            ->assertSee('このRepositoryはGitHub Appで同期できます');

        $this->assertSame(
            'connected',
            data_get(
                $repo->fresh()->metadata,
                'github_app_connection.status',
            ),
        );
    }

    public function test_workflow_marks_app_configuration_as_canovia_operator_action(): void
    {
        $user = $this->user();
        $this->grantAllAccess($user);

        $plan = $this->plan($user);
        $this->repository($plan, $user);

        $this->actingAs($user)
            ->get(route('github_workflow.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('App credential: 未設定')
            ->assertSee('運営設定が必要')
            ->assertSee('GitHub App credential未設定')
            ->assertSee('Canovia運営側のGitHub App設定がまだありません');
    }

    public function test_workflow_marks_missing_install_url_separately(): void
    {
        $user = $this->user();
        $this->grantAllAccess($user);

        $plan = $this->plan($user);
        $this->repository($plan, $user);

        config([
            'services.github.app_id' => '12345',
            'services.github.app_private_key' => $this->privateKey(),
            'services.github.app_install_url' => null,
        ]);

        $this->actingAs($user)
            ->get(route('github_workflow.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('App credential: ✓')
            ->assertSee('Install URL: 未設定')
            ->assertSee('NEXT · CANOVIA OPERATOR')
            ->assertSee('Install URL未設定')
            ->assertSee('GitHub Appの接続URL設定が必要です');
    }

    public function test_execution_github_handoff_entitlement_denial_redirects_instead_of_403(): void
    {
        $user = $this->user();
        $plan = $this->plan($user);
        $task = $this->task($plan);

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.execution_orchestration.github.prepare',
                [$plan, $task],
            ))
            ->assertRedirect(route(
                'plans.tasks.execution_orchestration.show',
                [$plan, $task],
            ))
            ->assertSessionHas(
                'status',
                fn (string $message) =>
                    str_contains(
                        $message,
                        'Developer GitHub Write',
                    ),
            );
    }

    public function test_plan_edit_authorization_still_returns_403_before_capability_handling(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $plan = $this->plan($owner);
        $repo = $this->repository($plan, $owner);

        $this->grantAllAccess($other);

        $this->actingAs($other)
            ->post(route(
                'github_workflow.app.connect',
                $repo,
            ))
            ->assertForbidden();
    }

    private function user(): User
    {
        return User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
    }

    private function plan(User $user): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Canovia Development',
            'description' => 'GitHub integration stabilization',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }

    private function task(Plan $plan): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'GitHub integrationを確認',
            'description' => 'GitHub App接続を安定化する',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }

    private function repository(
        Plan $plan,
        User $user,
        bool $connected = false,
    ): PlanArtifact {
        return PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'github',
            'artifact_type' => 'repository',
            'title' => 'Canovia-web',
            'url' => 'https://github.com/1kz-ma1/Canovia-web',
            'metadata' => $connected
                ? [
                    'github_app_connection' => [
                        'status' => 'connected',
                        'installation_id' => 777,
                        'account_login' => '1kz-ma1',
                        'permissions' => [
                            'contents' => 'write',
                            'pull_requests' => 'write',
                        ],
                    ],
                ]
                : null,
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
        $this->assertTrue(
            openssl_pkey_export($key, $pem),
        );
        $this->assertNotSame('', $pem);

        return $pem;
    }
}
