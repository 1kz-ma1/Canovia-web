<?php

namespace Tests\Feature;

use App\Enums\EntitlementSource;
use App\Enums\FeatureKey;
use App\Enums\ProductKey;
use App\Models\CareerCapture;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\StudyPracticeAttempt;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\AdminAccessService;
use App\Services\AiCapacityService;
use App\Services\EconomyRecommendationService;
use App\Services\FeatureAccessService;
use App\Services\ProductGrantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EconomyFoundationV415Test extends TestCase
{
    use RefreshDatabase;

    public function test_existing_free_features_remain_free_and_new_pack_capabilities_default_to_denied(): void
    {
        $user = User::factory()->create();
        $access = app(FeatureAccessService::class);

        foreach ([
            FeatureKey::AiPractice,
            FeatureKey::AdvancedAnalytics,
            FeatureKey::QuestionPack,
            FeatureKey::ProjectArtifact,
            FeatureKey::ConversationalOnboarding,
        ] as $feature) {
            $decision = $access->resolveAccess($user, $feature);
            $this->assertTrue($decision->allowed, $feature->value);
            $this->assertSame(EntitlementSource::Free, $decision->source);
        }

        $this->assertFalse($access->canUse($user, FeatureKey::AutomaticAiExecution));

        foreach ([
            FeatureKey::StudyLongTermWeaknessProfile,
            FeatureKey::CareerNativeCaptureAnalysis,
            FeatureKey::DeveloperGithubEvidence,
            FeatureKey::DeveloperGithubWrite,
        ] as $feature) {
            $this->assertFalse($access->canUse($user, $feature), $feature->value);
        }
    }

    public function test_feature_catalog_and_entitlement_configuration_stay_in_sync(): void
    {
        $enumKeys = collect(FeatureKey::cases())->map(fn (FeatureKey $key) => $key->value)->sort()->values()->all();
        $configuredKeys = collect(array_keys(config('entitlements.features', [])))->sort()->values()->all();

        $this->assertSame($enumKeys, $configuredKeys);
    }

    public function test_product_catalog_matches_product_enum_and_coin_is_not_a_direct_entitlement_source(): void
    {
        $enumKeys = collect(ProductKey::cases())->map(fn (ProductKey $key) => $key->value)->sort()->values()->all();
        $configuredKeys = collect(array_keys(config('economy.products', [])))->sort()->values()->all();

        $this->assertSame($enumKeys, $configuredKeys);
        $this->assertNotContains('coin', collect(EntitlementSource::cases())->map->value->all());

        foreach (config('economy.products', []) as $product) {
            foreach ((array) ($product['feature_keys'] ?? []) as $featureKey) {
                $this->assertNotNull(FeatureKey::tryFrom((string) $featureKey));
            }
        }
    }

    public function test_pack_requires_premium_core_and_grants_only_its_mapped_capability(): void
    {
        $user = User::factory()->create();

        $studyGrant = $this->grant($user, ProductKey::StudyPack);
        $this->assertFalse(
            app(FeatureAccessService::class)->canUse($user, FeatureKey::StudyLongTermWeaknessProfile)
        );

        $premiumGrant = $this->grant($user, ProductKey::PremiumCore);

        $decision = app(FeatureAccessService::class)
            ->resolveAccess($user, FeatureKey::StudyLongTermWeaknessProfile);

        $this->assertTrue($decision->allowed);
        $this->assertSame(EntitlementSource::Premium, $decision->source);
        $this->assertSame('product_grant', $decision->reason);
        $this->assertSame($studyGrant->id, $decision->metadata['product_grant_id']);
        $this->assertSame(ProductKey::StudyPack->value, $decision->metadata['product_key']);
        $this->assertSame(ProductKey::StudyPack->value, $decision->metadata['effective_product_key']);
        $this->assertSame('manual', $decision->metadata['grant_source']);

        $this->assertFalse(
            app(FeatureAccessService::class)->canUse($user, FeatureKey::CareerNativeCaptureAnalysis)
        );
        $this->assertFalse(
            app(FeatureAccessService::class)->canUse($user, FeatureKey::DeveloperGithubEvidence)
        );

        $this->assertNotNull($premiumGrant);
    }

    public function test_all_access_expands_to_purpose_packs_without_boosting_ai_capacity(): void
    {
        $user = User::factory()->create();
        $this->grant($user, ProductKey::AllAccess);

        $access = app(FeatureAccessService::class);
        $this->assertTrue($access->canUse($user, FeatureKey::StudyLongTermWeaknessProfile));
        $this->assertTrue($access->canUse($user, FeatureKey::CareerNativeCaptureAnalysis));
        $this->assertTrue($access->canUse($user, FeatureKey::DeveloperGithubEvidence));
        $this->assertTrue($access->canUse($user, FeatureKey::DeveloperGithubWrite));

        $products = app(ProductGrantService::class)->effectiveProducts($user)->map->value->all();
        $this->assertContains(ProductKey::PremiumCore->value, $products);
        $this->assertContains(ProductKey::StudyPack->value, $products);
        $this->assertContains(ProductKey::CareerPack->value, $products);
        $this->assertContains(ProductKey::DeveloperPack->value, $products);

        $this->assertSame('standard', app(AiCapacityService::class)->tierFor($user));
    }

    public function test_ai_capacity_boost_is_separate_from_pack_entitlements(): void
    {
        $user = User::factory()->create();
        $this->grant($user, ProductKey::PremiumCore);
        $this->grant($user, ProductKey::AiCapacityBoost);

        $this->assertSame('boosted', app(AiCapacityService::class)->tierFor($user));
        $this->assertFalse(
            app(FeatureAccessService::class)->canUse($user, FeatureKey::StudyLongTermWeaknessProfile)
        );
        $this->assertFalse(
            app(FeatureAccessService::class)->canUse($user, FeatureKey::CareerNativeCaptureAnalysis)
        );
    }

    public function test_only_current_product_grants_are_effective_and_deleting_a_grant_revokes_access(): void
    {
        $user = User::factory()->create();
        $this->grant($user, ProductKey::PremiumCore);

        $future = $this->grant($user, ProductKey::StudyPack, now()->addHour(), null);
        $this->assertFalse(
            app(FeatureAccessService::class)->canUse($user, FeatureKey::StudyLongTermWeaknessProfile)
        );

        $future->delete();
        $expired = $this->grant($user, ProductKey::StudyPack, now()->subDays(2), now()->subDay());
        $this->assertFalse(
            app(FeatureAccessService::class)->canUse($user, FeatureKey::StudyLongTermWeaknessProfile)
        );

        $expired->delete();
        $active = $this->grant($user, ProductKey::StudyPack, now()->subHour(), now()->addHour());
        $this->assertTrue(
            app(FeatureAccessService::class)->canUse($user, FeatureKey::StudyLongTermWeaknessProfile)
        );

        $active->delete();
        $this->assertFalse(
            app(FeatureAccessService::class)->canUse($user, FeatureKey::StudyLongTermWeaknessProfile)
        );
    }

    public function test_gift_and_sponsor_grants_use_safe_runtime_sources_without_billing_payloads(): void
    {
        $giftUser = User::factory()->create();
        $this->grant($giftUser, ProductKey::PremiumCore, null, null, 'gift');
        $this->grant($giftUser, ProductKey::StudyPack, null, null, 'gift');

        $giftDecision = app(FeatureAccessService::class)
            ->resolveAccess($giftUser, FeatureKey::StudyLongTermWeaknessProfile);

        $this->assertSame(EntitlementSource::Gift, $giftDecision->source);
        $this->assertArrayNotHasKey('receipt', $giftDecision->auditMetadata());
        $this->assertArrayNotHasKey('price', $giftDecision->metadata);

        $sponsorUser = User::factory()->create();
        $this->grant($sponsorUser, ProductKey::PremiumCore, null, null, 'sponsor');
        $this->grant($sponsorUser, ProductKey::CareerPack, null, null, 'sponsor');

        $sponsorDecision = app(FeatureAccessService::class)
            ->resolveAccess($sponsorUser, FeatureKey::CareerNativeCaptureAnalysis);

        $this->assertSame(EntitlementSource::Sponsor, $sponsorDecision->source);
    }

    public function test_recommendation_can_keep_user_free_and_does_not_overrecommend_unused_domains(): void
    {
        $freeUser = User::factory()->create();
        $this->plan($freeUser, '資格学習', '軽い資格確認');

        $free = app(EconomyRecommendationService::class)->recommend($freeUser);
        $this->assertTrue($free['free_is_sufficient']);
        $this->assertSame([], $free['recommended_products']);

        $studyUser = User::factory()->create();
        $studyPlan = $this->plan($studyUser, '資格学習', 'AP対策');
        for ($i = 0; $i < 3; $i++) {
            $this->attempt($studyUser, $studyPlan, 'study-'.$i);
        }

        $study = app(EconomyRecommendationService::class)->recommend($studyUser);

        $this->assertFalse($study['free_is_sufficient']);
        $this->assertContains(ProductKey::PremiumCore->value, $study['recommended_products']);
        $this->assertContains(ProductKey::StudyPack->value, $study['recommended_products']);
        $this->assertNotContains(ProductKey::CareerPack->value, $study['recommended_products']);
        $this->assertNotContains(ProductKey::DeveloperPack->value, $study['recommended_products']);
        $this->assertNotContains(ProductKey::AllAccess->value, $study['recommended_products']);
    }

    public function test_three_meaningful_domains_can_recommend_all_access_without_price_claims(): void
    {
        $user = User::factory()->create();

        $studyPlan = $this->plan($user, '資格学習', 'AP対策');
        for ($i = 0; $i < 3; $i++) {
            $this->attempt($user, $studyPlan, 'all-study-'.$i);
        }

        $careerPlan = $this->plan($user, '就活・キャリア', '就活');
        CareerCapture::create([
            'plan_id' => $careerPlan->id,
            'user_id' => $user->id,
            'source_type' => 'url',
            'status' => 'pending',
            'source_url' => 'https://example.com/a',
            'captured_at' => now(),
        ]);
        CareerCapture::create([
            'plan_id' => $careerPlan->id,
            'user_id' => $user->id,
            'source_type' => 'url',
            'status' => 'pending',
            'source_url' => 'https://example.com/b',
            'captured_at' => now(),
        ]);

        $developerPlan = $this->plan($user, '個人開発', 'Canovia開発');
        PlanArtifact::create([
            'plan_id' => $developerPlan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'github',
            'artifact_type' => 'repository',
            'title' => 'Repository',
            'url' => 'https://github.com/example/project',
        ]);

        $result = app(EconomyRecommendationService::class)->recommend($user);

        $this->assertSame([ProductKey::AllAccess->value], $result['recommended_products']);
        $this->assertStringNotContainsString('安', implode(' ', $result['why']));
        $this->assertStringNotContainsString('月額', implode(' ', $result['why']));
    }

    public function test_admin_economy_inspector_can_add_and_revoke_manual_grants(): void
    {
        $user = User::factory()->create();
        $studyPlan = $this->plan($user, '資格学習', 'AP対策');
        for ($i = 0; $i < 3; $i++) {
            $this->attempt($user, $studyPlan, 'admin-economy-'.$i);
        }

        $this->withSession([AdminAccessService::SESSION_KEY => true])
            ->get(route('admin.economy.index', ['user_id' => $user->id]))
            ->assertOk()
            ->assertSee('Economy Inspector')
            ->assertSee('Premium Core')
            ->assertSee('Study Pack');

        $this->withSession([AdminAccessService::SESSION_KEY => true])
            ->post(route('admin.economy.grants.store'), [
                'user_id' => $user->id,
                'product_key' => ProductKey::PremiumCore->value,
                'source' => 'manual',
            ])
            ->assertRedirect(route('admin.economy.index', ['user_id' => $user->id]));

        $grant = UserProductGrant::query()->firstOrFail();
        $this->assertSame(ProductKey::PremiumCore, $grant->product_key);

        $this->withSession([AdminAccessService::SESSION_KEY => true])
            ->delete(route('admin.economy.grants.destroy', $grant))
            ->assertRedirect(route('admin.economy.index', ['user_id' => $user->id]));

        $this->assertDatabaseMissing('user_product_grants', ['id' => $grant->id]);
    }

    private function grant(
        User $user,
        ProductKey $product,
        $startsAt = null,
        $expiresAt = null,
        string $source = 'manual',
    ): UserProductGrant {
        return UserProductGrant::create([
            'user_id' => $user->id,
            'product_key' => $product,
            'source' => $source,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'metadata' => ['test' => true],
        ]);
    }

    private function plan(User $user, string $category, string $title): Plan
    {
        return Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'category' => $category,
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }

    private function attempt(User $user, Plan $plan, string $key): StudyPracticeAttempt
    {
        $task = $plan->tasks()->create([
            'title' => '演習 '.$key,
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return StudyPracticeAttempt::create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'request_hash' => hash('sha256', $key),
            'exercise_title' => $key,
            'questions' => [['id' => 'q1']],
            'answers' => [['question_id' => 'q1', 'fields' => []]],
            'assessment' => ['question_feedback' => []],
            'score_percent' => 80,
            'strengths' => [],
            'weaknesses' => [],
            'recommended_task_progress_percent' => 50,
            'evidence_summary' => 'test',
            'next_action' => '次へ',
        ]);
    }
}
