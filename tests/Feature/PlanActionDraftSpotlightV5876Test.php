<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanActionDraft;
use App\Models\PlanMember;
use App\Models\User;
use App\Services\PlanActionDraftStepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlanActionDraftSpotlightV5876Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['session.driver' => 'array', 'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null, 'canovia.admin_email' => null]);
    }

    private function plan(User $owner, bool $team = false): Plan
    {
        return Plan::create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => '次の学習行動',
            'category' => '資格学習', 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(30),
            'is_public' => false, 'is_collaborative' => $team,
        ]);
    }

    private function draft(Plan $plan, string $status = 'proposed'): PlanActionDraft
    {
        return PlanActionDraft::create([
            'plan_id' => $plan->id, 'request_id' => (string) Str::uuid(),
            'source_kind' => 'self_report', 'completed_action' => '問題を解いた',
            'observed_outcome' => '借方で迷った',
            'suggested_next_action' => '借方を3問復習する',
            'status' => $status,
        ]);
    }

    private function prepare(User $owner, Plan $plan, PlanActionDraft $draft): void
    {
        $this->actingAs($owner)->post(route('plans.action_drafts.steps.prepare', [$plan, $draft]))
            ->assertRedirect();
    }

    public function test_ready_three_step_draft_is_quietly_shown_and_only_acknowledged_on_owner_action(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);
        $draft = $this->draft($plan);
        $this->prepare($owner, $plan, $draft);

        $this->actingAs($owner)->get(route('plans.show', $plan))
            ->assertOk()
            ->assertSee('data-plan-draft-ready-spotlight', false)
            ->assertSee('3段階の次の行動を、確認・修正できます')
            ->assertSee('計画案を確認する')
            ->assertSee('今は閉じる');

        $this->assertNull($plan->fresh()->action_draft_spotlight_acknowledged_at);
        $this->actingAs($owner)->get(route('plans.show', $plan))
            ->assertOk()->assertSee('data-plan-draft-ready-spotlight', false);
        $this->assertNull($plan->fresh()->action_draft_spotlight_acknowledged_at);

        $this->actingAs($owner)->post(route('plans.action_drafts.spotlight.acknowledge', $plan), [
            'action' => 'open',
        ])->assertRedirect(route('plans.action_drafts.index', $plan));
        $this->assertNotNull($plan->fresh()->action_draft_spotlight_acknowledged_at);
        $this->actingAs($owner)->get(route('plans.show', $plan))
            ->assertOk()
            ->assertDontSee('data-plan-draft-ready-spotlight', false)
            ->assertSee('data-plan-action-drafts-entry', false);

        $this->actingAs($owner)->post(route('plans.action_drafts.spotlight.acknowledge', $plan), [
            'action' => 'open',
        ])->assertRedirect(route('plans.action_drafts.index', $plan));
        $this->assertSame(0, $plan->tasks()->count());

        // A subsequent draft does not restart the per-Plan guide.
        $otherDraft = $this->draft($plan);
        $this->prepare($owner, $plan, $otherDraft);
        $this->actingAs($owner)->get(route('plans.show', $plan))
            ->assertOk()->assertDontSee('data-plan-draft-ready-spotlight', false);
    }

    public function test_dismissal_is_persistent_and_legacy_entry_is_still_accessible(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $this->prepare($owner, $plan, $this->draft($plan));
        $this->actingAs($owner)->post(route('plans.action_drafts.spotlight.acknowledge', $plan), [
            'action' => 'dismiss',
        ])->assertRedirect(route('plans.show', $plan));
        $this->assertNotNull($plan->fresh()->action_draft_spotlight_acknowledged_at);
        $this->actingAs($owner)->get(route('plans.show', $plan))
            ->assertOk()->assertDontSee('data-plan-draft-ready-spotlight', false)
            ->assertSee('行動から計画案を育てる');
        $this->actingAs($owner)->get(route('plans.action_drafts.index', $plan))
            ->assertOk()->assertSee('3段階のステップ案');
    }

    public function test_no_ready_steps_or_accepted_or_stale_drafts_never_trigger_notice(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $draft = $this->draft($plan);
        $this->actingAs($owner)->get(route('plans.show', $plan))
            ->assertOk()->assertDontSee('data-plan-draft-ready-spotlight', false);

        $this->prepare($owner, $plan, $draft);
        $old = hash('sha256', $draft->suggested_next_action);
        $this->actingAs($owner)->patch(route('plans.action_drafts.update', [$plan, $draft]), [
            'suggested_next_action' => '最新の演習を2問解く',
            'expected_candidate_fingerprint' => $old,
        ])->assertRedirect();
        $this->actingAs($owner)->get(route('plans.show', $plan))
            ->assertOk()->assertDontSee('data-plan-draft-ready-spotlight', false);
        $this->assertNull($plan->fresh()->action_draft_spotlight_acknowledged_at);

        $this->actingAs($owner)->post(route('plans.action_drafts.steps.prepare', [$plan, $draft]), [
            'rebuild' => 1,
        ])->assertRedirect();
        $this->actingAs($owner)->get(route('plans.show', $plan))
            ->assertOk()->assertSee('data-plan-draft-ready-spotlight', false);

        $steps = $draft->fresh()->steps;
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]), [
            'accept_mode' => 'bundle',
            'steps_fingerprint' => app(PlanActionDraftStepService::class)->fingerprint($steps),
        ])->assertRedirect();
        $this->actingAs($owner)->get(route('plans.show', $plan))
            ->assertOk()->assertDontSee('data-plan-draft-ready-spotlight', false);
        $this->assertNull($plan->fresh()->action_draft_spotlight_acknowledged_at);
    }

    public function test_team_editor_viewer_and_stranger_never_see_or_acknowledge_owner_guide(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, true);
        $draft = $this->draft($plan);
        $this->prepare($owner, $plan, $draft);

        foreach ([PlanMember::ROLE_EDITOR, PlanMember::ROLE_VIEWER] as $role) {
            $member = User::factory()->create();
            PlanMember::create([
                'plan_id' => $plan->id, 'user_id' => $member->id,
                'role' => $role, 'invited_by_user_id' => $owner->id, 'joined_at' => now(),
            ]);
            $this->actingAs($member)->get(route('plans.show', $plan))
                ->assertOk()->assertDontSee('data-plan-draft-ready-spotlight', false);
            $this->actingAs($member)->post(route('plans.action_drafts.spotlight.acknowledge', $plan), [
                'action' => 'open',
            ])->assertForbidden();
        }

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get(route('plans.show', $plan))->assertNotFound();
        $this->actingAs($stranger)->post(route('plans.action_drafts.spotlight.acknowledge', $plan), [
            'action' => 'dismiss',
        ])->assertForbidden();
        $this->assertNull($plan->fresh()->action_draft_spotlight_acknowledged_at);
        $this->actingAs($owner)->get(route('plans.show', $plan))
            ->assertOk()->assertSee('data-plan-draft-ready-spotlight', false);
    }

    public function test_bad_action_or_no_ready_draft_cannot_mark_spotlight_acknowledged(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $this->actingAs($owner)->post(route('plans.action_drafts.spotlight.acknowledge', $plan), [
            'action' => 'open',
        ])->assertRedirect();
        $this->assertNull($plan->fresh()->action_draft_spotlight_acknowledged_at);

        $draft = $this->draft($plan);
        $this->prepare($owner, $plan, $draft);
        $this->actingAs($owner)->post(route('plans.action_drafts.spotlight.acknowledge', $plan), [
            'action' => 'unknown',
        ])->assertSessionHasErrors('action');
        $this->assertNull($plan->fresh()->action_draft_spotlight_acknowledged_at);
    }

    public function test_animation_is_single_iteration_and_accessible_for_reduced_motion(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('prefers-reduced-motion: no-preference', $css);
        $this->assertStringContainsString('plan-draft-ready-outline 850ms ease-out 1 both', $css);
    }
}
