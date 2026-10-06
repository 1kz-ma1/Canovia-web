<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\User;
use App\Models\UserProductGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SpecializedModeTopDensityV585Test extends TestCase
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

    public function test_study_top_renders_compact_plan_row_with_disclosed_secondary_tools(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $plan = $this->plan($user, 'AP対策', '資格学習');

        $this->actingAs($user)
            ->get(route('workspace.study.top'))
            ->assertOk()
            ->assertSee('data-specialized-top-compact-header', false)
            ->assertSee('data-specialized-top-plan-row', false)
            ->assertSee('data-specialized-top-plan-tools', false)
            ->assertSee('<details', false)
            ->assertSee('準備・詳細')
            ->assertSee(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]), false)
            ->assertSee(route('plans.study_scope.index', $plan), false)
            ->assertSee(route('plans.resources.index', $plan), false)
            ->assertSee(route('plans.study_scores.index', $plan), false)
            ->assertSee(route('plans.show', $plan), false);
    }

    public function test_development_top_keeps_repository_state_inline_without_remote_provider_read(): void
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

        $plan = $this->plan($user, 'Canovia', '個人開発');

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
                    'read_ready' => true,
                    'write_ready' => false,
                ],
            ],
        ]);

        Http::fake();

        $this->actingAs($user)
            ->get(route('workspace.development.top'))
            ->assertOk()
            ->assertSee('data-specialized-top-compact-header', false)
            ->assertSee('data-development-top-integration-compact', false)
            ->assertSee('data-specialized-top-plan-row', false)
            ->assertSee('data-development-top-repository-inline', false)
            ->assertSee('Canovia-web')
            ->assertSee('GitHub接続済み')
            ->assertSee(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]), false)
            ->assertSee(route('github_workflow.index', [
                'plan_id' => $plan->id,
            ]), false)
            ->assertSee(route('plans.show', $plan), false);

        Http::assertNothingSent();
    }

    public function test_shared_plan_partial_does_not_nest_page_card_per_plan(): void
    {
        $partial = file_get_contents(
            resource_path(
                'views/workspace/partials/specialized-top-plan-list.blade.php',
            ),
        );

        $this->assertStringContainsString(
            'data-specialized-top-plan-row',
            $partial,
        );
        $this->assertStringContainsString(
            'data-specialized-top-plan-tools',
            $partial,
        );
        $this->assertStringNotContainsString(
            'class="page-card',
            $partial,
        );
        $this->assertStringNotContainsString(
            'overflow-hidden rounded-2xl',
            $partial,
        );
    }

    private function plan(
        User $user,
        string $title,
        string $category,
    ): Plan {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
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
