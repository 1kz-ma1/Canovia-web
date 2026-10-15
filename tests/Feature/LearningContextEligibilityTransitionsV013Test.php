<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\GuestPlanClaimService;
use App\Services\LearningContextSourceCaptureService;
use App\Services\LearningContextSourceEpochService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class LearningContextEligibilityTransitionsV013Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'intelligence.learning_context_watch.epoch_shadow_enabled' => true,
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    private function fixture(?User $owner = null): array
    {
        $owner ??= User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'PRIVATE_STUDY_ELIGIBILITY',
            'category' => '資格学習', 'priority' => 1,
            'priority_mode' => 'manual', 'start_date' => today(),
            'deadline' => today()->addDays(15),
            'is_public' => false, 'is_collaborative' => false,
        ]);
        Task::query()->create([
            'plan_id' => $plan->id, 'title' => 'Study synthetic DNS',
            'status' => 'doing', 'progress_percent' => 20,
            'priority' => 1, 'sort_order' => 1,
        ]);
        return [$owner, $plan];
    }

    public function test_enable_and_disable_collaboration_record_exit_and_reentry_without_reusing_old_context(): void
    {
        [$owner, $plan] = $this->fixture();
        $capture = app(LearningContextSourceCaptureService::class);
        $epoch = app(LearningContextSourceEpochService::class);
        $original = $capture->capture($owner, $plan->id);
        $this->assertNotNull($original);
        $this->actingAs($owner);

        $this->post(route('plans.collaboration.enable', $plan))
            ->assertRedirect(route('plans.collaboration.settings', $plan));
        $this->assertTrue($plan->fresh()->is_collaborative);
        $this->assertSame(1, $epoch->inspect($plan->id)['epoch']);
        $this->assertNull($capture->capture($owner, $plan->id));
        $this->assertFalse($capture->isCurrent($owner, $plan->id,
            $original['source_revision']));

        // Repeated enable is a no-op even though an invitation may be
        // regenerated or an existing code reused by the collaboration API.
        $this->post(route('plans.collaboration.enable', $plan))
            ->assertRedirect(route('plans.collaboration.settings', $plan));
        $this->assertSame(1, $epoch->inspect($plan->id)['epoch']);

        $this->delete(route('plans.collaboration.disable', $plan))
            ->assertRedirect(route('plans.collaboration.settings', $plan));
        $this->assertFalse($plan->fresh()->is_collaborative);
        $this->assertSame(2, $epoch->inspect($plan->id)['epoch']);
        $this->assertNotNull($capture->capture($owner, $plan->id));
        $this->assertFalse($epoch->inspect($plan->id)['authoritative']);

        $this->delete(route('plans.collaboration.disable', $plan))
            ->assertRedirect(route('plans.collaboration.settings', $plan));
        $this->assertSame(2, $epoch->inspect($plan->id)['epoch']);
    }

    public function test_guest_plan_claim_initializes_owner_study_epoch_once_and_denies_other_users(): void
    {
        [$ignored, $plan] = $this->fixture();
        $plan->update(['user_id' => null]);
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $capture = app(LearningContextSourceCaptureService::class);
        $this->assertNull($capture->capture($owner, $plan->id));
        $token = $plan->owner_token;
        $request = Request::create('/', 'GET', [], [
            'pace_keeper_owner_token_'.$plan->id => $token,
        ]);
        $claim = app(GuestPlanClaimService::class);

        $this->assertSame(1, $claim->claim($request, $owner));
        $this->assertSame($owner->id, $plan->fresh()->user_id);
        $this->assertSame(1,
            app(LearningContextSourceEpochService::class)->inspect($plan->id)['epoch']);
        $this->assertNotNull($capture->capture($owner, $plan->id));
        $this->assertNull($capture->capture($attacker, $plan->id));
        $this->assertSame(0, $claim->claim($request, $attacker));
        $this->assertSame(0, $claim->claim($request, $owner));
        $this->assertSame(1,
            app(LearningContextSourceEpochService::class)->inspect($plan->id)['epoch']);
    }

    public function test_feature_off_never_creates_shadow_row_on_collaboration_or_guest_claim(): void
    {
        config(['intelligence.learning_context_watch.epoch_shadow_enabled' => false]);
        [$owner, $plan] = $this->fixture();
        $this->actingAs($owner)
            ->post(route('plans.collaboration.enable', $plan))->assertRedirect();
        $this->delete(route('plans.collaboration.disable', $plan))
            ->assertRedirect();
        $this->assertDatabaseCount('learning_context_source_epochs', 0);

        $plan->update(['user_id' => null]);
        $newOwner = User::factory()->create();
        $request = Request::create('/', 'GET', [], [
            'pace_keeper_owner_token_'.$plan->id => $plan->owner_token,
        ]);
        $this->assertSame(1,
            app(GuestPlanClaimService::class)->claim($request, $newOwner));
        $this->assertDatabaseCount('learning_context_source_epochs', 0);
    }

    public function test_collaboration_exit_epoch_and_status_roll_back_as_one_mutation(): void
    {
        [$owner, $plan] = $this->fixture();
        try {
            DB::transaction(function () use ($plan): void {
                $locked = Plan::query()->whereKey($plan->id)
                    ->lockForUpdate()->firstOrFail();
                app(LearningContextSourceEpochService::class)
                    ->record($locked, 'plan_updated');
                $locked->update(['is_collaborative' => true]);
                throw new RuntimeException('intentional failure after epoch');
            });
            $this->fail('Expected test rollback.');
        } catch (RuntimeException $e) {
            $this->assertSame('intentional failure after epoch', $e->getMessage());
        }
        $this->assertFalse($plan->fresh()->is_collaborative);
        $this->assertNull(
            app(LearningContextSourceEpochService::class)->inspect($plan->id));
        $this->assertNotNull(app(LearningContextSourceCaptureService::class)
            ->capture($owner, $plan->id));
    }
}
