<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\ReleaseLevel;
use App\Models\User;
use App\Services\FeatureAccessService;
use App\Services\ReleaseGateService;
use App\Services\ReleaseLevelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseGateInventoryV5821Test extends TestCase
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
            'release_levels.public_level' => ReleaseLevel::InternalPreview->value,
        ]);
    }

    public function test_every_feature_has_one_release_level_and_entitlement_definition(): void
    {
        $featureKeys = collect(FeatureKey::cases())
            ->map(fn (FeatureKey $feature) => $feature->value)
            ->sort()
            ->values()
            ->all();

        $releaseKeys = collect(
            array_keys((array) config('release_levels.feature_minimum', [])),
        )->sort()->values()->all();

        $entitlementKeys = collect(
            array_keys((array) config('entitlements.features', [])),
        )->sort()->values()->all();

        $this->assertSame($featureKeys, $releaseKeys);
        $this->assertSame($featureKeys, $entitlementKeys);
    }

    public function test_initial_feature_maturity_contract_keeps_l2_presentation_only(): void
    {
        $levels = app(ReleaseLevelService::class);

        foreach ([
            FeatureKey::AiPractice,
            FeatureKey::AdvancedAnalytics,
            FeatureKey::QuestionPack,
            FeatureKey::ProjectArtifact,
            FeatureKey::ConversationalOnboarding,
            FeatureKey::StudyScopeCapture,
        ] as $feature) {
            $this->assertSame(
                ReleaseLevel::EarlyAccessCore,
                $levels->minimumForFeature($feature),
            );
        }

        foreach ([
            FeatureKey::AutomaticAiExecution,
            FeatureKey::CanoviaCompanion,
            FeatureKey::StudyLongTermWeaknessProfile,
            FeatureKey::CareerNativeCaptureAnalysis,
            FeatureKey::DeveloperGithubEvidence,
        ] as $feature) {
            $this->assertSame(
                ReleaseLevel::BetaExpansion,
                $levels->minimumForFeature($feature),
            );
        }

        $this->assertSame(
            ReleaseLevel::InternalPreview,
            $levels->minimumForFeature(FeatureKey::DeveloperGithubWrite),
        );

        $this->assertEmpty(
            collect(FeatureKey::cases())
                ->filter(
                    fn (FeatureKey $feature) =>
                        $levels->minimumForFeature($feature)
                            === ReleaseLevel::ProductPreview,
                )
                ->all(),
            'Level 2 must remain presentation-only during Early Access.',
        );
    }

    public function test_level_one_is_structurally_ready_for_manual_release_review(): void
    {
        $assessment = app(ReleaseGateService::class)
            ->assess(ReleaseLevel::EarlyAccessCore);

        $this->assertTrue($assessment['automatic_ready']);
        $this->assertSame('manual_review', $assessment['status']);
        $this->assertSame(0, $assessment['failed_check_count']);
        $this->assertNotEmpty($assessment['manual_checks']);
    }

    public function test_level_two_contract_requires_canonical_product_preview_surface(): void
    {
        $requiredRoutes = (array) config(
            'release_levels.gate.levels.'.
            ReleaseLevel::ProductPreview->value.
            '.required_routes',
            [],
        );

        $this->assertContains('product.preview.index', $requiredRoutes);
        $this->assertTrue(route('product.preview.index') !== '');
    }

    public function test_release_maturity_never_grants_entitlement(): void
    {
        config([
            'release_levels.public_level' => ReleaseLevel::BetaExpansion->value,
        ]);

        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $release = app(ReleaseLevelService::class);
        $access = app(FeatureAccessService::class);

        $this->assertTrue(
            $release->allowsFeature(
                FeatureKey::AutomaticAiExecution,
                $user,
            ),
        );
        $this->assertFalse(
            $access->canUse(
                $user,
                FeatureKey::AutomaticAiExecution,
            ),
        );

        $this->assertFalse(
            $release->allowsFeature(
                FeatureKey::DeveloperGithubWrite,
                $user,
            ),
        );
    }

    public function test_admin_release_gate_shows_l1_candidate_and_feature_inventory(): void
    {
        $admin = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        config(['canovia.super_admin_user_id' => $admin->id]);

        $this->actingAs($admin)
            ->get(route('admin.release_gate.index'))
            ->assertOk()
            ->assertSee('Release Gate')
            ->assertSee('L1 Early Access Core')
            ->assertSee('Feature maturity map')
            ->assertSee(FeatureKey::DeveloperGithubWrite->value);
    }

    public function test_release_gate_is_read_only_and_does_not_change_public_level(): void
    {
        config([
            'release_levels.public_level' => ReleaseLevel::EarlyAccessCore->value,
        ]);

        $admin = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        config(['canovia.super_admin_user_id' => $admin->id]);

        $this->actingAs($admin)
            ->get(route('admin.release_gate.index'))
            ->assertOk();

        $this->assertSame(
            ReleaseLevel::EarlyAccessCore,
            app(ReleaseLevelService::class)->publicLevel(),
        );
    }
}
