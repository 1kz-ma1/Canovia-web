<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\LearningContextSourceCaptureService;
use App\Services\LearningContextSourceEpochService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LearningContextOwnerPlanEditV012Test extends TestCase
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

    private function plan(?User $owner = null): array
    {
        $owner ??= User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'PRIVATE_STUDY_ORIGINAL',
            'category' => '資格学習',
            'priority' => 1, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(12),
            'is_public' => false, 'is_collaborative' => false,
            'accent_key' => 'sky', 'roadmap_world' => 'default',
        ]);
        return [$owner, $plan];
    }

    private function payload(Plan $plan, array $overrides = []): array
    {
        return array_replace([
            'title' => (string) $plan->title,
            'category' => (string) $plan->category,
            'priority' => (int) $plan->priority,
            'priority_mode' => (string) $plan->priority_mode,
            'start_date' => $plan->start_date?->toDateString(),
            'deadline' => $plan->deadline?->toDateString(),
        ], $overrides);
    }

    public function test_owner_edit_deadline_and_title_changes_source_and_shadow_epoch_once(): void
    {
        [$owner, $plan] = $this->plan();
        $source = app(LearningContextSourceCaptureService::class);
        $before = $source->capture($owner, $plan->id);
        $this->assertNotNull($before);
        $this->actingAs($owner);

        $payload = $this->payload($plan, [
            'title' => 'PRIVATE_STUDY_UPDATED',
            'deadline' => today()->addDays(18)->toDateString(),
        ]);
        $this->put(route('plans.update', $plan), $payload)
            ->assertRedirect(route('plans.show', $plan))
            ->assertSessionHasNoErrors();

        $this->assertSame('PRIVATE_STUDY_UPDATED', $plan->fresh()->title);
        $this->assertSame(today()->addDays(18)->toDateString(),
            $plan->fresh()->deadline->toDateString());
        $this->assertSame(1,
            app(LearningContextSourceEpochService::class)->inspect($plan->id)['epoch']);
        $this->assertSame('plan_updated',
            app(LearningContextSourceEpochService::class)->inspect($plan->id)['last_mutation_kind']);
        $this->assertNotSame($before['source_revision'],
            $source->capture($owner, $plan->id)['source_revision']);
        $this->assertDatabaseHas('plan_activity_logs', [
            'plan_id' => $plan->id, 'event_type' => 'plan_updated',
        ]);

        // Exact same update: no new source writes, but preserve existing
        // activity-log behavior for explicit owner confirmation.
        $this->put(route('plans.update', $plan), $payload)
            ->assertRedirect(route('plans.show', $plan))
            ->assertSessionHasNoErrors();
        $this->assertSame(1,
            app(LearningContextSourceEpochService::class)->inspect($plan->id)['epoch']);
    }

    public function test_invalid_deadline_leaves_plan_epoch_activity_and_goal_title_unmodified(): void
    {
        [$owner, $plan] = $this->plan();
        $this->actingAs($owner);
        $this->from(route('plans.edit', $plan))
            ->put(route('plans.update', $plan), $this->payload($plan, [
                'title' => 'MUST_NOT_COMMIT',
                'start_date' => today()->addDays(20)->toDateString(),
                'deadline' => today()->addDays(15)->toDateString(),
            ]))->assertRedirect(route('plans.edit', $plan))
            ->assertSessionHasErrors('deadline');
        $this->assertSame('PRIVATE_STUDY_ORIGINAL', $plan->fresh()->title);
        $this->assertNull(app(LearningContextSourceEpochService::class)
            ->inspect($plan->id));
        $this->assertDatabaseMissing('plan_activity_logs', [
            'plan_id' => $plan->id, 'event_type' => 'plan_updated',
        ]);
    }

    public function test_owner_only_and_other_domain_does_not_allocate_study_shadow(): void
    {
        [$owner, $plan] = $this->plan();
        $stranger = User::factory()->create();
        $this->actingAs($stranger)
            ->put(route('plans.update', $plan), $this->payload($plan, [
                'title' => 'UNAUTHORIZED',
            ]))->assertForbidden();
        $this->assertSame('PRIVATE_STUDY_ORIGINAL', $plan->fresh()->title);
        $this->assertNull(app(LearningContextSourceEpochService::class)
            ->inspect($plan->id));

        $this->actingAs($owner)
            ->put(route('plans.update', $plan), $this->payload($plan, [
                'category' => '個人開発',
                'title' => 'DEVELOPMENT_EDITED',
            ]))->assertRedirect(route('plans.show', $plan));
        $this->assertSame('DEVELOPMENT_EDITED', $plan->fresh()->title);
        // Leaving Study invalidates capture by access/eligibility; a shadow
        // counter scoped only to eligible Study Plans does not certify this.
        $this->assertNull(app(LearningContextSourceCaptureService::class)
            ->capture($owner, $plan->id));
        $this->assertNull(app(LearningContextSourceEpochService::class)
            ->inspect($plan->id));

        $this->put(route('plans.update', $plan), $this->payload($plan->fresh(), [
            'title' => 'DEVELOPMENT_EDITED_AGAIN',
        ]))->assertRedirect(route('plans.show', $plan));
        $this->assertNull(app(LearningContextSourceEpochService::class)
            ->inspect($plan->id));
    }

    public function test_study_owner_edit_with_shadow_gate_off_updates_normally(): void
    {
        config(['intelligence.learning_context_watch.epoch_shadow_enabled' => false]);
        [$owner, $plan] = $this->plan();
        $this->actingAs($owner);
        $this->put(route('plans.update', $plan), $this->payload($plan, [
            'title' => 'SHADOW_OFF_STILL_EDITABLE',
        ]))->assertRedirect(route('plans.show', $plan));
        $this->assertSame('SHADOW_OFF_STILL_EDITABLE', $plan->fresh()->title);
        $this->assertDatabaseCount('learning_context_source_epochs', 0);
    }
}
