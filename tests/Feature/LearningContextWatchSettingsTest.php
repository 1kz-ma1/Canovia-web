<?php

namespace Tests\Feature;

use App\Models\LearningContextChangeRecord;
use App\Models\LearningContextWatchState;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LearningContextWatchSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function plan(User $owner): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'SYNTHETIC_OWNER_LEARNING_PLAN',
            'category' => '資格学習',
            'priority' => 1, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(9),
            'is_public' => false, 'is_collaborative' => false,
        ]);
    }

    private function task(Plan $plan): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'PRIVATE_DNS_TASK',
            'description' => 'SECRET_TASK_DESCRIPTION_NOT_EXPORTED',
            'status' => 'doing',
            'progress_percent' => 10,
            'priority' => 1,
            'sort_order' => 1,
        ]);
    }

    private function url(Plan $plan): string
    {
        return route('workspace.study.context_watch.show', ['plan' => $plan->id]);
    }

    private function enableUrl(Plan $plan): string
    {
        return route('workspace.study.context_watch.enable', ['plan' => $plan->id]);
    }

    private function refreshUrl(Plan $plan): string
    {
        return route('workspace.study.context_watch.refresh', ['plan' => $plan->id]);
    }

    private function disableUrl(Plan $plan): string
    {
        return route('workspace.study.context_watch.disable', ['plan' => $plan->id]);
    }

    public function test_default_off_does_not_expose_settings_or_create_records(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);

        $this->actingAs($owner);
        $this->get($this->url($plan))->assertNotFound();
        $this->post($this->enableUrl($plan), ['consent' => 1])->assertNotFound();
        $this->post($this->refreshUrl($plan))->assertNotFound();
        $this->assertDatabaseCount('learning_context_watch_states', 0);
        $this->assertDatabaseCount('learning_context_change_records', 0);

        $this->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertDontSee('data-learning-watch-settings-entry');
    }

    public function test_explicit_checkbox_creates_one_encrypted_owner_baseline_and_no_notifications(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $this->task($plan);
        config(['intelligence.learning_context_watch.persistence_enabled' => true]);

        $this->actingAs($owner);
        $this->get($this->url($plan))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('data-learning-watch-enable')
            ->assertSee('name="consent"', false)
            ->assertSee('バックグラウンドでの自動チェック');
        $this->post($this->enableUrl($plan), [])->assertSessionHasErrors(['consent']);
        $this->assertDatabaseCount('learning_context_watch_states', 0);
        $this->post($this->enableUrl($plan), ['consent' => '1'])
            ->assertRedirect($this->url($plan))
            ->assertSessionHas('success');
        $this->assertDatabaseCount('learning_context_watch_states', 1);
        $this->get($this->url($plan))
            ->assertOk()
            ->assertSee('ON（記録中）')
            ->assertSee('data-learning-watch-manual-check')
            ->assertSee('data-learning-watch-disable')
            ->assertDontSee('SECRET_TASK_DESCRIPTION_NOT_EXPORTED');

        $before = LearningContextWatchState::query()->sole()->source_revision;
        // A repeated POST MUST NOT rebaseline; explicit consent is not assumed.
        $this->post($this->enableUrl($plan), ['consent' => '1'])
            ->assertRedirect($this->url($plan));
        $this->assertSame($before, LearningContextWatchState::query()->sole()->source_revision);
        $this->assertDatabaseCount('learning_context_watch_states', 1);
        $this->assertDatabaseCount('learning_context_change_records', 0);
    }

    public function test_manual_comparison_is_bounded_deduped_and_never_delivers(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $task = $this->task($plan);
        config(['intelligence.learning_context_watch.persistence_enabled' => true]);

        $this->actingAs($owner);
        $this->post($this->enableUrl($plan), ['consent' => '1'])
            ->assertRedirect($this->url($plan));
        $this->post($this->refreshUrl($plan))
            ->assertRedirect($this->url($plan))
            ->assertSessionHas('success');
        $this->assertDatabaseCount('learning_context_change_records', 0);

        $task->update(['status' => 'paused']);
        $this->post($this->refreshUrl($plan))
            ->assertRedirect($this->url($plan))
            ->assertSessionHas('success');
        $this->assertDatabaseCount('learning_context_change_records', 1);
        $candidate = LearningContextChangeRecord::query()->sole();
        $this->assertSame('review_only', $candidate->status);
        $this->assertSame(['recorded_task_paused'], $candidate->event_kinds);
        $this->assertDatabaseMissing('learning_context_change_records', ['status' => 'delivered']);
        $this->get($this->url($plan))
            ->assertSee('data-learning-watch-candidate-count')
            ->assertSee('1件');

        $this->post($this->refreshUrl($plan))->assertSessionHas('success');
        $this->assertDatabaseCount('learning_context_change_records', 1);
        $task->update(['status' => 'doing']);
        $this->post($this->refreshUrl($plan))->assertSessionHas('success');
        $this->assertSame('superseded', $candidate->fresh()->status);
        $task->update(['status' => 'paused']);
        $this->post($this->refreshUrl($plan))->assertSessionHas('success');
        $this->assertDatabaseCount('learning_context_change_records', 1);
    }

    public function test_other_actor_and_guest_cannot_read_or_mutate_plan_settings(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $plan = $this->plan($owner);
        config(['intelligence.learning_context_watch.persistence_enabled' => true]);

        $this->get($this->url($plan))->assertRedirect();
        $this->post($this->enableUrl($plan), ['consent' => '1'])->assertRedirect();
        $this->post($this->refreshUrl($plan))->assertRedirect();
        $this->delete($this->disableUrl($plan))->assertRedirect();
        $this->actingAs($other);
        $this->get($this->url($plan))->assertNotFound();
        $this->post($this->enableUrl($plan), ['consent' => '1'])->assertNotFound();
        $this->post($this->refreshUrl($plan))->assertNotFound();
        $this->delete($this->disableUrl($plan))->assertNotFound();
        $this->assertDatabaseCount('learning_context_watch_states', 0);

        $this->actingAs($owner);
        $this->post($this->enableUrl($plan), ['consent' => '1'])->assertRedirect();
        $this->actingAs($other);
        $this->get($this->url($plan))->assertNotFound()
            ->assertDontSee('SYNTHETIC_OWNER_LEARNING_PLAN');
    }

    public function test_guest_cookie_or_collaborative_public_and_development_plans_cannot_opt_in(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        config(['intelligence.learning_context_watch.persistence_enabled' => true]);
        $this->actingAs($owner);

        $plan->update(['is_collaborative' => true]);
        $this->post($this->enableUrl($plan), ['consent' => '1'])->assertNotFound();
        $plan->update(['is_collaborative' => false, 'is_public' => true]);
        $this->post($this->enableUrl($plan), ['consent' => '1'])->assertNotFound();
        $plan->update(['is_public' => false, 'category' => 'ソフトウェア開発']);
        $this->post($this->enableUrl($plan), ['consent' => '1'])->assertNotFound();
        $this->assertDatabaseCount('learning_context_watch_states', 0);
    }

    public function test_erase_remains_accessible_when_feature_is_off_or_study_classification_changes(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $this->task($plan);
        config(['intelligence.learning_context_watch.persistence_enabled' => true]);
        $this->actingAs($owner);
        $this->post($this->enableUrl($plan), ['consent' => '1'])->assertRedirect();
        $plan->update(['category' => 'ソフトウェア開発', 'is_public' => true]);
        config(['intelligence.learning_context_watch.persistence_enabled' => false]);
        $this->get($this->url($plan))->assertOk()
            ->assertSee('data-learning-watch-paused')
            ->assertSee('data-learning-watch-disable')
            ->assertDontSee('data-learning-watch-manual-check');
        $this->post($this->refreshUrl($plan))->assertNotFound();
        $this->delete($this->disableUrl($plan))
            ->assertRedirect(route('workspace.study.top'));
        $this->assertDatabaseCount('learning_context_watch_states', 0);
        $this->assertDatabaseCount('learning_context_change_records', 0);
        $this->get($this->url($plan))->assertNotFound();
    }

    public function test_staging_forbids_opt_in_and_manual_refresh_but_existing_data_can_be_erased(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        config(['intelligence.learning_context_watch.persistence_enabled' => true]);
        $this->actingAs($owner);
        $this->post($this->enableUrl($plan), ['consent' => '1'])->assertRedirect();
        $this->assertDatabaseCount('learning_context_watch_states', 1);

        config(['app.env' => 'staging']);
        $this->post($this->refreshUrl($plan))->assertNotFound();
        $this->post($this->enableUrl($plan), ['consent' => '1'])->assertNotFound();
        $this->get($this->url($plan))->assertOk()
            ->assertSee('data-learning-watch-disable')
            ->assertDontSee('data-learning-watch-manual-check');
        $this->delete($this->disableUrl($plan))->assertRedirect();
        $this->assertDatabaseCount('learning_context_watch_states', 0);
    }
}
