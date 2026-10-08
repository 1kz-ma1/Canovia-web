<?php

namespace Tests\Feature;

use App\Enums\ReleaseLevel;
use App\Models\Plan;
use App\Models\ReleaseReviewCheck;
use App\Models\Task;
use App\Models\User;
use App\Services\ReleaseLevelService;
use App\Services\ReleaseReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReleaseReviewV5824Test extends TestCase
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

    public function test_admin_can_persist_and_reset_manual_release_review(): void
    {
        $admin = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        config(['canovia.super_admin_user_id' => $admin->id]);

        $this->actingAs($admin)
            ->from(route('admin.release_gate.index'))
            ->post(route('admin.release_gate.review.update'), [
                'release_level' => ReleaseLevel::ProductPreview->value,
                'check_key' => 'product_preview_copy',
                'status' => 'passed',
                'note' => 'Desktop / mobile copy reviewed.',
            ])
            ->assertRedirect(route('admin.release_gate.index'));

        $this->assertDatabaseHas('release_review_checks', [
            'release_level' => ReleaseLevel::ProductPreview->value,
            'check_key' => 'product_preview_copy',
            'status' => ReleaseReviewCheck::STATUS_PASSED,
            'reviewed_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.release_gate.index'))
            ->delete(route('admin.release_gate.review.reset'), [
                'release_level' => ReleaseLevel::ProductPreview->value,
                'check_key' => 'product_preview_copy',
            ])
            ->assertRedirect(route('admin.release_gate.index'));

        $this->assertDatabaseMissing('release_review_checks', [
            'release_level' => ReleaseLevel::ProductPreview->value,
            'check_key' => 'product_preview_copy',
        ]);
    }

    public function test_new_personalization_checks_remain_pending_until_manual_review(): void
    {
        $items = app(ReleaseReviewService::class)->items(ReleaseLevel::EarlyAccessCore);
        $keys = $items->pluck('check_key')->all();

        foreach ([
            'personalization_confidence_calibration',
            'sustained_development_behavior_signal',
            'repository_structure_breadth_signal',
            'guidance_level_confirmation',
            'plan_direction_review_confirmation',
            'feature_recommendation_priority',
            'growth_experience_separation',
        ] as $key) {
            $this->assertContains($key, $keys);
            $this->assertSame('pending', $items->firstWhere('check_key', $key)['status']);
        }
    }

    public function test_invalid_manual_check_key_cannot_be_saved(): void
    {
        $admin = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        config(['canovia.super_admin_user_id' => $admin->id]);

        $this->actingAs($admin)
            ->from(route('admin.release_gate.index'))
            ->post(route('admin.release_gate.review.update'), [
                'release_level' => ReleaseLevel::ProductPreview->value,
                'check_key' => 'not_a_real_check',
                'status' => 'passed',
            ])
            ->assertRedirect(route('admin.release_gate.index'))
            ->assertSessionHasErrors('check_key');

        $this->assertDatabaseCount('release_review_checks', 0);
    }

    public function test_target_becomes_ready_only_after_every_cumulative_manual_check_passes(): void
    {
        $admin = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        config(['canovia.super_admin_user_id' => $admin->id]);

        $reviews = app(ReleaseReviewService::class);
        $items = $reviews->items(ReleaseLevel::ProductPreview);

        $this->assertGreaterThanOrEqual(10, $items->count());

        foreach ($items as $item) {
            $reviews->save(
                $item['source_level'],
                $item['check_key'],
                ReleaseReviewCheck::STATUS_PASSED,
                'Verified for V58.24 release candidate.',
                $admin,
            );
        }

        $summary = $reviews->summary(ReleaseLevel::ProductPreview);
        $this->assertTrue($summary['complete']);
        $this->assertSame($items->count(), $summary['passed']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(0, $summary['pending']);

        $this->actingAs($admin)
            ->get(route('admin.release_gate.index'))
            ->assertOk()
            ->assertSee('data-release-review-decision="ready"', false)
            ->assertSee('READY FOR RELEASE')
            ->assertSee(
                $items->count().'/'.$items->count(),
            );
    }

    public function test_one_failed_review_prevents_ready_decision(): void
    {
        $admin = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        config(['canovia.super_admin_user_id' => $admin->id]);

        $reviews = app(ReleaseReviewService::class);
        $items = $reviews->items(ReleaseLevel::ProductPreview);

        foreach ($items as $index => $item) {
            $reviews->save(
                $item['source_level'],
                $item['check_key'],
                $index === 0
                    ? ReleaseReviewCheck::STATUS_FAILED
                    : ReleaseReviewCheck::STATUS_PASSED,
                $index === 0 ? 'Needs another pass.' : null,
                $admin,
            );
        }

        $this->actingAs($admin)
            ->get(route('admin.release_gate.index'))
            ->assertOk()
            ->assertSee('data-release-review-decision="failed"', false)
            ->assertSee('REVIEW FAILED');
    }

    public function test_release_review_never_changes_public_level(): void
    {
        config([
            'release_levels.public_level' =>
                ReleaseLevel::EarlyAccessCore->value,
        ]);

        $admin = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        config(['canovia.super_admin_user_id' => $admin->id]);

        $reviews = app(ReleaseReviewService::class);

        foreach ($reviews->items(ReleaseLevel::ProductPreview) as $item) {
            $reviews->save(
                $item['source_level'],
                $item['check_key'],
                ReleaseReviewCheck::STATUS_PASSED,
                null,
                $admin,
            );
        }

        $this->actingAs($admin)
            ->get(route('admin.release_gate.index'))
            ->assertOk()
            ->assertSee('READY FOR RELEASE');

        $this->assertSame(
            ReleaseLevel::EarlyAccessCore,
            app(ReleaseLevelService::class)->publicLevel(),
        );
    }

    public function test_l2_to_l0_downgrade_preserves_plan_task_and_core_access(): void
    {
        config([
            'release_levels.public_level' =>
                ReleaseLevel::ProductPreview->value,
        ]);

        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'creation_request_id' => (string) Str::uuid(),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Downgrade smoke',
            'description' => 'L2からL0へ落としても保持する',
            'category' => '資格学習',
            'priority' => 2,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '保持されるTask',
            'description' => 'Core data',
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-release-level="2"', false)
            ->assertSee('data-canovia-nav-key="mobile-workspace"', false);

        $this->actingAs($user)->get(route('workspace.study.top'))->assertOk()
            ->assertSee('data-canovia-nav-key="mobile-workspace-study"', false);

        config([
            'release_levels.public_level' => ReleaseLevel::CoreStable->value,
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-release-level="0"', false)
            ->assertDontSee('data-canovia-nav-key="mobile-workspace-study"', false)
            ->assertDontSee('data-canovia-nav-key="mobile-workspace-development"', false);

        $this->actingAs($user)
            ->get(route('plans.show', $plan))
            ->assertOk();

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'plan_id' => $plan->id,
        ]);
    }
}
