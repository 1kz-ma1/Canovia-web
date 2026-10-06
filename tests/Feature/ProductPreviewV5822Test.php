<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Enums\ReleaseLevel;
use App\Models\BehaviorEvent;
use App\Models\User;
use App\Services\ReleaseGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPreviewV5822Test extends TestCase
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

    public function test_level_one_hides_account_entry_and_blocks_direct_product_preview(): void
    {
        config([
            'release_levels.public_level' => ReleaseLevel::EarlyAccessCore->value,
        ]);

        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('auth.account'))
            ->assertOk()
            ->assertDontSee('data-product-preview-entry', false);

        $this->actingAs($user)
            ->get(route('product.preview.index'))
            ->assertRedirect(route('workspace.overview.index'))
            ->assertSessionHas(
                'status',
                'この機能は現在の公開レベルでは利用できません。Beta公開後に利用できます。',
            );
    }

    public function test_level_two_exposes_product_preview_without_checkout(): void
    {
        config([
            'release_levels.public_level' => ReleaseLevel::ProductPreview->value,
        ]);

        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('auth.account'))
            ->assertOk()
            ->assertSee('data-product-preview-entry', false)
            ->assertSee(route('product.preview.index'), false);

        $this->actingAs($user)
            ->get(route('product.preview.index'))
            ->assertOk()
            ->assertSee('data-product-preview', false)
            ->assertSee('Free')
            ->assertSee('Premium')
            ->assertSee('Pro')
            ->assertSee('Dev Pro')
            ->assertSee('利用可能')
            ->assertSee('Coming Soon')
            ->assertSee('価格・正式提供時期は未定')
            ->assertSee('Study')
            ->assertSee('Development')
            ->assertDontSee('購入する')
            ->assertDontSee('今すぐ購入')
            ->assertDontSee('checkout', false);
    }

    public function test_product_preview_makes_level_two_structurally_ready_for_manual_review(): void
    {
        $assessment = app(ReleaseGateService::class)
            ->assess(ReleaseLevel::ProductPreview);

        $this->assertTrue($assessment['automatic_ready']);
        $this->assertSame('manual_review', $assessment['status']);
        $this->assertSame(0, $assessment['failed_check_count']);
        $this->assertNotEmpty($assessment['manual_checks']);
    }

    public function test_product_preview_view_is_recorded_once_per_session_window(): void
    {
        config([
            'release_levels.public_level' => ReleaseLevel::ProductPreview->value,
        ]);

        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('product.preview.index'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('product.preview.index'))
            ->assertOk();

        $events = BehaviorEvent::query()
            ->where(
                'event_type',
                BehaviorEventType::ProductPreviewViewed->value,
            )
            ->get();

        $this->assertCount(1, $events);
        $this->assertSame(
            true,
            (bool) data_get($events->first()?->metadata, 'authenticated'),
        );
        $this->assertSame(
            ReleaseLevel::ProductPreview->value,
            (int) data_get($events->first()?->metadata, 'release_level'),
        );
    }


    public function test_early_access_planning_target_advances_to_level_two(): void
    {
        $this->assertSame(
            ReleaseLevel::ProductPreview,
            app(ReleaseGateService::class)->recommendedTarget(),
        );
    }

    public function test_product_preview_contract_keeps_only_free_available(): void
    {
        $tiers = (array) config('product_preview.tiers', []);

        $this->assertSame('available', data_get($tiers, 'free.status'));
        $this->assertSame('coming_soon', data_get($tiers, 'premium.status'));
        $this->assertSame('coming_soon', data_get($tiers, 'pro.status'));
        $this->assertSame('coming_soon', data_get($tiers, 'dev_pro.status'));
    }
}
