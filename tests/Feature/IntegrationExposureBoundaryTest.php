<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\ReleaseLevel;
use App\Models\Plan;
use App\Models\User;
use App\Services\FeatureAccessService;
use App\Services\ReleaseLevelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class IntegrationExposureBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['release_levels.public_level' => 1, 'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null]);
    }

    public function test_absent_invalid_or_hosted_internal_public_configuration_is_safe(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $levels = app(ReleaseLevelService::class);
        foreach ([null, '', 'invalid', -1, 99, 4] as $value) {
            config(['release_levels.public_level' => $value]);
            $this->assertSame(ReleaseLevel::CoreStable, $levels->publicLevel());
        }
        config()->offsetUnset('release_levels.public_level');
        $this->assertSame(ReleaseLevel::CoreStable, $levels->publicLevel());
    }

    public function test_source_configuration_does_not_default_to_internal_preview(): void
    {
        // The absent-environment case is validated in an independent process:
        // PHPUnit explicitly selects L4 for the existing development fixtures.
        $process = new Process([
            PHP_BINARY, '-r',
            'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; '
            .'$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
            .'echo json_encode(config("release_levels.public_level"));',
        ], base_path(), ['CANOVIA_PUBLIC_RELEASE_LEVEL' => false, 'APP_ENV' => 'production']);
        // Use a separate dotenv name so the local development .env is not read.
        $process->setEnv(['CANOVIA_PUBLIC_RELEASE_LEVEL' => false, 'APP_ENV' => 'production',
            'APP_CONFIG_CACHE' => storage_path('framework/testing/no-integration-config-cache.php')]);
        $process->mustRun();
        $this->assertSame('0', trim($process->getOutput()));
    }

    public function test_entitlement_does_not_publish_internal_writes_for_guest_normal_or_beta(): void
    {
        config(['entitlements.features.developer_github_write.free' => true]);
        $normal = User::factory()->create();
        $beta = User::factory()->create(['release_level_override' => 3]);
        $access = app(FeatureAccessService::class);
        foreach ([null, $normal, $beta] as $actor) {
            $decision = $access->resolveAccess($actor, FeatureKey::DeveloperGithubWrite);
            $this->assertFalse($decision->allowed);
            $this->assertSame('feature_not_published', $decision->reason);
        }
    }

    public function test_stored_internal_override_is_retained_but_cannot_elevate_regular_user(): void
    {
        $user = User::factory()->create(['release_level_override' => 4]);
        $this->assertSame(ReleaseLevel::EarlyAccessCore, app(ReleaseLevelService::class)->levelFor($user));
        $this->assertSame(4, $user->fresh()->release_level_override);
        $user->fill(['release_level_override' => 3]);
        $this->assertSame(4, $user->release_level_override);
    }

    public function test_configured_maximum_cannot_allow_internal_user_override(): void
    {
        config(['release_levels.maximum_user_override' => 4]);
        $user = User::factory()->create();
        try {
            app(ReleaseLevelService::class)->setUserOverride($user, ReleaseLevel::InternalPreview);
            $this->fail('Internal preview must remain administrator-session-only');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $this->assertNull($user->fresh()->release_level_override);
    }

    public function test_beta_grant_revoke_and_direct_mutation_preserve_existing_plan(): void
    {
        $user = User::factory()->create(['release_level_override' => 3, 'workspace_mode_preference' => 'career']);
        $plan = $this->plan($user);
        $this->actingAs($user)->get(route('workspace.career.index'))->assertOk();
        app(ReleaseLevelService::class)->setUserOverride($user, null);
        $this->actingAs($user->fresh())->post(route('plans.career.applications.store', $plan), [])->assertNotFound();
        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'user_id' => $user->id]);
        $this->assertSame('career', $user->fresh()->workspace_mode_preference);
        app(ReleaseLevelService::class)->setUserOverride($user, ReleaseLevel::BetaExpansion);
        $this->actingAs($user->fresh())->get(route('workspace.career.index'))->assertOk();
    }

    public function test_admin_preview_does_not_escape_to_other_actor_or_bypass_plan_ownership(): void
    {
        $admin = User::factory()->create();
        $owner = User::factory()->create();
        config(['canovia.super_admin_user_id' => $admin->id]);
        $plan = $this->plan($owner);
        $this->actingAs($admin)->post(route('admin.release_level.preview.update'), ['level' => '4'])->assertRedirect();
        $this->assertTrue(app(FeatureAccessService::class)->canUse($admin, FeatureKey::DeveloperGithubWrite));
        $this->get(route('plans.career.index', $plan))->assertForbidden();
        $this->actingAs($owner)->get(route('workspace.career.index'))->assertRedirect(route('workspace.overview.index'));
        $this->assertFalse(app(FeatureAccessService::class)->canUse($owner, FeatureKey::DeveloperGithubWrite));
        $this->post(route('admin.release_level.preview.update'), ['level' => '4'])->assertForbidden();
    }

    public function test_l0_route_publication_denies_even_entitled_feature(): void
    {
        config(['release_levels.public_level' => 0, 'entitlements.features.project_artifact.free' => true]);
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('plans.artifacts.store', $this->plan($user)), [])->assertForbidden();
        $this->assertDatabaseCount('plan_artifacts', 0);
    }

    private function plan(User $user): Plan
    {
        return Plan::query()->create(['user_id' => $user->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'Integration fixture', 'description' => '',
            'category' => 'その他', 'priority' => 1, 'start_date' => today(), 'deadline' => today()->addMonth(),
            'is_public' => false, 'is_collaborative' => false]);
    }
}
