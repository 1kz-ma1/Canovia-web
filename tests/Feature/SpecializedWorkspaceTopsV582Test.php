<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\StudyScopeCapture;
use App\Models\StudyScopeItem;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SpecializedWorkspaceTopsV582Test extends TestCase
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

    public function test_study_top_lists_only_study_plans_with_progress_and_real_preparation_links(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $study = $this->plan(
            $user,
            'AP 2026秋',
            '資格学習',
            priority: 1,
        );
        $development = $this->plan(
            $user,
            'Canovia Development',
            '個人開発',
            priority: 1,
        );

        $this->task(
            $study,
            'ネットワーク演習',
            estimated: 100,
            remaining: 50,
            progress: 50,
        );
        $this->confirmedScope($study, 'ネットワーク', 'DNS');

        $response = $this->actingAs($user)
            ->get(route('workspace.study.top'));

        $response
            ->assertOk()
            ->assertSee('data-study-top', false)
            ->assertSee('data-specialized-top-compact-header', false)
            ->assertSee('data-specialized-top-plan-row', false)
            ->assertSee('data-specialized-top-plan-tools', false)
            ->assertSee('data-workspace-mode="study"', false)
            ->assertSee('data-workspace-mode-source="route_hint"', false)
            ->assertSee($study->title)
            ->assertDontSee($development->title)
            ->assertSee('50.0%')
            ->assertSee('Active 1')
            ->assertSee('Tasks 1')
            ->assertSee('範囲 1')
            ->assertSee(
                route('workspace.study.index', [
                    'plan_id' => $study->id,
                ]),
                false,
            )
            ->assertSee(
                route('plans.study_scope.index', $study),
                false,
            )
            ->assertSee(
                route('plans.resources.index', $study),
                false,
            )
            ->assertSee(
                route('plans.study_scores.index', $study),
                false,
            )
            ->assertSee(
                route('plans.create.manual', [
                    'workspace_mode' => 'study',
                ]),
                false,
            );

        $this->assertDatabaseCount(
            'intelligence_action_projections',
            0,
        );
        $this->assertDatabaseCount(
            'intelligence_decision_traces',
            0,
        );
    }

    public function test_development_top_lists_progress_and_persisted_github_connection_without_remote_api_read(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $this->grantAllAccess($user);

        config([
            'services.github.app_id' => '12345',
            'services.github.app_private_key' => $this->privateKey(),
            'services.github.app_private_key_base64' => null,
            'services.github.app_install_url' =>
                'https://github.com/apps/canovia-dev-integration/installations/new',
        ]);

        $development = $this->plan(
            $user,
            'Canoviaを事業として成功させる',
            '個人開発',
            priority: 1,
        );
        $study = $this->plan(
            $user,
            'AP対策',
            '資格学習',
            priority: 1,
        );

        $this->task(
            $development,
            'Developer Workspaceを改善',
            estimated: 200,
            remaining: 80,
            progress: 60,
        );

        PlanArtifact::query()->create([
            'plan_id' => $development->id,
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
                    'read_ready' => true,
                    'write_ready' => false,
                ],
            ],
        ]);

        Http::fake();

        $response = $this->actingAs($user)
            ->get(route('workspace.development.top'));

        $response
            ->assertOk()
            ->assertSee('data-development-top', false)
            ->assertSee('data-specialized-top-compact-header', false)
            ->assertSee('data-development-top-integration-compact', false)
            ->assertSee('data-specialized-top-plan-row', false)
            ->assertSee('data-development-top-repository-inline', false)
            ->assertSee(
                'data-workspace-mode="development"',
                false,
            )
            ->assertSee('data-workspace-mode-source="route_hint"', false)
            ->assertSee($development->title)
            ->assertDontSee($study->title)
            ->assertSee('60.0%')
            ->assertSee('Canovia-web')
            ->assertSee('GitHub接続済み')
            ->assertSee(
                route('github_workflow.index', [
                    'plan_id' => $development->id,
                ]),
                false,
            )
            ->assertSee(
                route('workspace.development.index', [
                    'plan_id' => $development->id,
                ]),
                false,
            )
            ->assertSee(
                route('plans.create.manual', [
                    'workspace_mode' => 'development',
                ]),
                false,
            );

        Http::assertNothingSent();
    }

    public function test_top_pages_are_valid_empty_hubs_without_creating_or_selecting_fake_plans(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('workspace.study.top'))
            ->assertOk()
            ->assertSee('data-study-top', false)
            ->assertSee('data-specialized-top-empty="study"', false)
            ->assertSee('学習Planはまだありません。');

        $this->actingAs($user)
            ->get(route('workspace.development.top'))
            ->assertOk()
            ->assertSee('data-development-top', false)
            ->assertSee(
                'data-specialized-top-empty="development"',
                false,
            )
            ->assertSee('開発Planはまだありません。');

        $this->assertDatabaseCount('plans', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_plan_workspaces_have_mode_top_escape_and_no_longer_expose_multi_plan_selector(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $studyA = $this->plan($user, 'AP対策', '資格学習', priority: 1);
        $studyB = $this->plan($user, '英語', '資格学習', priority: 2);
        $devA = $this->plan($user, 'Canovia', '個人開発', priority: 1);
        $devB = $this->plan($user, 'Game', '個人開発', priority: 2);

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $studyA->id,
            ]))
            ->assertOk()
            ->assertSee('data-study-top-link', false)
            ->assertSee(route('workspace.study.top'), false)
            ->assertSee($studyA->title)
            ->assertDontSee($studyB->title)
            ->assertDontSee('id="study-workspace-plan"', false);

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $devA->id,
                'surface' => 'work',
            ]))
            ->assertOk()
            ->assertSee('data-development-top-link', false)
            ->assertSee(route('workspace.development.top'), false)
            ->assertSee($devA->title)
            ->assertDontSee($devB->title)
            ->assertDontSee('data-development-plan-select', false)
            ->assertSee('data-development-surface-select', false)
            ->assertSee(
                '<input type="hidden" name="plan_id" value="'
                    .$devA->id.'">',
                false,
            );
    }

    private function plan(
        User $user,
        string $title,
        string $category,
        int $priority = 2,
    ): Plan {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => $category,
            'priority' => $priority,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }

    private function task(
        Plan $plan,
        string $title,
        int $estimated,
        int $remaining,
        int $progress,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => null,
            'estimated_minutes' => $estimated,
            'remaining_minutes' => $remaining,
            'progress_percent' => $progress,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);
    }

    private function confirmedScope(
        Plan $plan,
        string $subject,
        string $unit,
    ): StudyScopeItem {
        $capture = StudyScopeCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $plan->user_id,
            'status' => 'confirmed',
            'exam_title' => '試験',
            'exam_date' => $plan->deadline?->format('Y-m-d'),
            'confidence' => 1,
            'extraction_version' => 'study_scope_v1',
            'confirmed_at' => now(),
        ]);

        return StudyScopeItem::query()->create([
            'study_scope_capture_id' => $capture->id,
            'plan_id' => $plan->id,
            'subject' => $subject,
            'unit' => $unit,
            'range_text' => $unit,
            'confidence' => 1,
            'sort_order' => 0,
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
