<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\ProductKey;
use App\Models\RoadmapFeature;
use App\Models\RoadmapVote;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\FeatureAccessService;
use App\Services\FeatureFlagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanoviaFutureV407Test extends TestCase
{
    use RefreshDatabase;

    public function test_future_page_is_the_primary_feedback_experience(): void
    {
        $this->get(route('feedback.index'))
            ->assertOk()
            ->assertSee('Canovia Future')
            ->assertSee('他の人の頑張りを見たい')
            ->assertSee('500 Support')
            ->assertSee('その他のフィードバック');
    }

    public function test_guest_actor_can_support_each_candidate_only_once(): void
    {
        $feature = RoadmapFeature::query()->where('feature_key', 'social.goal_peers')->firstOrFail();

        $this->post(route('feedback.future.support', $feature))->assertRedirect();
        $this->post(route('feedback.future.support', $feature))->assertRedirect();

        $this->assertSame(1, RoadmapVote::query()->where('roadmap_feature_id', $feature->id)->count());

        $this->delete(route('feedback.future.unsupport', $feature))->assertRedirect();
        $this->assertSame(0, RoadmapVote::query()->where('roadmap_feature_id', $feature->id)->count());
    }

    public function test_hidden_roadmap_candidate_is_not_exposed(): void
    {
        $feature = RoadmapFeature::query()->where('feature_key', 'social.cheer')->firstOrFail();
        $feature->update(['is_published' => false]);

        $this->get(route('feedback.index'))
            ->assertOk()
            ->assertDontSee($feature->title);

        $this->post(route('feedback.future.support', $feature))->assertNotFound();
    }

    public function test_public_manual_roadmap_is_visible_to_guests_without_breaking_support(): void
    {
        $this->get(route('feedback.index'))
            ->assertOk()
            ->assertSee('Canoviaの開発ロードマップ')
            ->assertSee('現在開発中')
            ->assertSee('検証中')
            ->assertSee('公開済み')
            ->assertSee('これから')
            ->assertSee('初回公開では自動連携を提供しません。')
            ->assertSee('今後ほしい機能への無料Support')
            ->assertSee('他の人の頑張りを見たい')
            ->assertSee('その他のフィードバック');
    }

    public function test_public_roadmap_is_manually_editable_without_changing_support_records(): void
    {
        $item = [
            'key' => 'manual-example',
            'title' => '限定された開発状況',
            'description' => '手動で掲載する項目です。',
            'area' => '学習',
            'status' => 'in_development',
            'updated_on' => '2026-10-15',
            'is_published' => true,
        ];
        config()->set('canovia_public_roadmap.items', [$item]);

        $this->get(route('feedback.index'))
            ->assertOk()
            ->assertSee('限定された開発状況')
            ->assertSee('開発中');

        config()->set('canovia_public_roadmap.items', [
            array_merge($item, ['status' => 'ready']),
        ]);
        $this->get(route('feedback.index'))
            ->assertOk()
            ->assertSee('限定された開発状況')
            ->assertSee('検証中');
        $this->assertSame(0, RoadmapVote::count());
    }

    public function test_released_claim_requires_explicit_owner_and_live_acceptance_data(): void
    {
        $item = [
            'key' => 'verified-release',
            'title' => '一般向けの確認済み機能',
            'description' => '実際の公開受入が必要な項目です。',
            'area' => '全般',
            'status' => 'released',
            'updated_on' => '2026-10-15',
            'is_published' => true,
        ];

        config()->set('canovia_public_roadmap.items', [$item]);
        $this->get(route('feedback.index'))->assertDontSee('一般向けの確認済み機能');

        config()->set('canovia_public_roadmap.items', [
            array_merge($item, [
                'owner_release_verified' => true,
                'release_verified_on' => '2026-10-15',
            ]),
        ]);
        $this->get(route('feedback.index'))->assertDontSee('一般向けの確認済み機能');

        config()->set('canovia_public_roadmap.items', [
            array_merge($item, [
                'owner_release_verified' => true,
                'release_verified_on' => '2026-10-15',
                'access_scope' => 'limited',
            ]),
        ]);
        $this->get(route('feedback.index'))
            ->assertSee('一般向けの確認済み機能')
            ->assertSee('対象者限定で公開済み')
            ->assertSee('公開確認日: 2026-10-15');
    }

    public function test_hidden_or_invalid_public_cards_and_private_metadata_do_not_leak(): void
    {
        $base = [
            'key' => 'hidden-example',
            'title' => 'INTERNAL_HIDDEN_EXAMPLE',
            'description' => '秘密の内容',
            'area' => '学習',
            'status' => 'in_development',
            'updated_on' => '2026-10-15',
            'is_published' => false,
        ];
        config()->set('canovia_public_roadmap.items', [
            $base,
            array_merge($base, ['key' => 'unknown', 'title' => 'INTERNAL_UNKNOWN_STATUS', 'status' => 'voting', 'is_published' => true]),
            array_merge($base, ['key' => 'invalid-date', 'title' => 'INTERNAL_INVALID_DATE', 'updated_on' => '2026-02-31', 'is_published' => true]),
            [
                'key' => 'safe-escaped',
                'title' => '<script>alert(1)</script>',
                'description' => '公開用の説明',
                'area' => '全般',
                'status' => 'planned',
                'updated_on' => '2026-10-15',
                'is_published' => true,
                'internal_note' => 'INTERNAL_SECRET_MUST_NOT_RENDER',
            ],
        ]);

        $this->get(route('feedback.index'))
            ->assertOk()
            ->assertDontSee('INTERNAL_HIDDEN_EXAMPLE')
            ->assertDontSee('INTERNAL_UNKNOWN_STATUS')
            ->assertDontSee('INTERNAL_INVALID_DATE')
            ->assertDontSee('INTERNAL_SECRET_MUST_NOT_RENDER')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_feature_flag_boundary_remains_separate_from_entitlement(): void
    {
        config()->set('features.flags.'.FeatureKey::AutomaticAiExecution->value, [
            'enabled' => false,
            'environment' => null,
            'platform' => 'all',
            'minimum_app_version' => null,
        ]);

        $user = User::factory()->create();
        UserProductGrant::create([
            'user_id' => $user->id,
            'product_key' => ProductKey::PremiumCore,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
        ]);

        $this->assertFalse(app(FeatureFlagService::class)->isEnabled(FeatureKey::AutomaticAiExecution));
        $this->assertTrue(app(FeatureAccessService::class)->canUse($user, FeatureKey::AutomaticAiExecution));
    }

    public function test_feature_flag_can_limit_platform_and_minimum_app_version_without_entitlement_logic(): void
    {
        config()->set('features.flags.'.FeatureKey::AutomaticAiExecution->value, [
            'enabled' => true,
            'environment' => 'testing',
            'platform' => 'ios',
            'minimum_app_version' => '40.7.0',
        ]);

        $flags = app(FeatureFlagService::class);

        $this->assertFalse($flags->isEnabled(FeatureKey::AutomaticAiExecution, [
            'platform' => 'web',
            'app_version' => '40.7.0',
        ]));
        $this->assertFalse($flags->isEnabled(FeatureKey::AutomaticAiExecution, [
            'platform' => 'ios',
            'app_version' => '40.6.9',
        ]));
        $this->assertTrue($flags->isEnabled(FeatureKey::AutomaticAiExecution, [
            'platform' => 'ios',
            'app_version' => '40.7.0',
        ]));
    }
}
