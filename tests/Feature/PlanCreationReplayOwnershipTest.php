<?php

namespace Tests\Feature;

use App\Models\GoalContext;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlanCreationReplayOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['native_ai.driver' => 'disabled']);
    }

    private function existing(?User $owner = null): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner?->id,
            'creation_request_id' => (string) Str::uuid(),
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => '保存済みの準備Plan',
            'category' => 'その他',
            'start_date' => today(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }

    private function payload(Plan $plan): array
    {
        return ['title' => '再送で変更しない', 'create_request_id' => $plan->creation_request_id];
    }

    public function test_guest_request_id_alone_cannot_recover_owner_cookie_or_attach_goal(): void
    {
        $plan = $this->existing();
        $before = $plan->fresh()->getRawOriginal();
        $response = $this->post(route('plans.store'), $this->payload($plan));
        $response->assertSessionHasNoErrors()->assertForbidden()->assertCookieMissing('pace_keeper_owner_token_'.$plan->id);
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
        $this->assertDatabaseCount('goal_contexts', 0);
        $this->assertDatabaseCount('plans', 1);
    }

    public function test_wrong_guest_cookie_cannot_recover_plan(): void
    {
        $plan = $this->existing();
        $this->withCookie('pace_keeper_owner_token_'.$plan->id, Str::random(64))
            ->post(route('plans.store'), $this->payload($plan))
            ->assertForbidden()->assertCookieMissing('pace_keeper_owner_token_'.$plan->id);
    }

    public function test_logged_in_nonowner_cannot_attach_their_goal_to_guest_plan(): void
    {
        $plan = $this->existing();
        $user = User::factory()->create();
        $goal = GoalContext::query()->create([
            'user_id' => $user->id, 'desired_state' => '本人の目標', 'status' => 'discovery',
        ]);
        $this->actingAs($user)->post(route('plans.store'), [
            ...$this->payload($plan), 'goal_context_id' => $goal->id,
        ])->assertForbidden();
        $this->assertNull($goal->fresh()->plan_id);
        $this->assertNull($plan->fresh()->user_id);
    }

    public function test_guest_owner_cookie_keeps_idempotent_retry_and_saved_content(): void
    {
        $plan = $this->existing();
        $this->withCookie('pace_keeper_owner_token_'.$plan->id, $plan->owner_token)
            ->post(route('plans.store'), $this->payload($plan))
            ->assertRedirect(route('plans.show', $plan));
        $this->assertSame('保存済みの準備Plan', $plan->fresh()->title);
        $this->assertDatabaseCount('plans', 1);
    }

    public function test_account_owner_retry_works_but_other_account_and_old_guest_cookie_do_not(): void
    {
        $owner = User::factory()->create();
        $plan = $this->existing($owner);
        $this->actingAs($owner)->post(route('plans.store'), $this->payload($plan))
            ->assertRedirect(route('plans.show', $plan));
        $other = User::factory()->create();
        $this->actingAs($other)->withCookie('pace_keeper_owner_token_'.$plan->id, $plan->owner_token)
            ->post(route('plans.store'), $this->payload($plan))->assertStatus(409);
        $this->assertSame($owner->id, $plan->fresh()->user_id);
        $this->assertDatabaseCount('plans', 1);
    }
}
