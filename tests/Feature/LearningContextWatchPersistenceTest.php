<?php

namespace Tests\Feature;

use App\Models\LearningContextChangeRecord;
use App\Models\LearningContextWatchState;
use App\Models\Plan;
use App\Models\Task;
use App\Models\StudyPracticeAttempt;
use App\Models\User;
use App\Services\LearningContextWatchPersistenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LearningContextWatchPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function plan(User $owner, string $title = 'PRIVATE_SYNTHETIC_LEARNING'): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title, 'category' => '資格学習',
            'priority' => 1, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(10),
            'is_public' => false, 'is_collaborative' => false,
        ]);
    }

    private function task(Plan $plan): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id, 'title' => 'PRIVATE_DNS_TASK_TITLE',
            'description' => 'SECRET_TASK_DESCRIPTION_NOT_STORED',
            'status' => 'doing', 'progress_percent' => 25,
            'priority' => 1, 'sort_order' => 1,
        ]);
    }

    private function enable(): LearningContextWatchPersistenceService
    {
        config(['intelligence.learning_context_watch.persistence_enabled' => true]);
        return app(LearningContextWatchPersistenceService::class);
    }

    public function test_feature_off_never_inserts_or_advances_without_owner_explicit_opt_in(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $service = app(LearningContextWatchPersistenceService::class);
        $this->actingAs($owner);
        $this->assertFalse($service->optIn($owner, $plan->id));
        $this->assertSame('disabled', $service->advance($owner, $plan->id)['status']);
        $this->assertDatabaseCount('learning_context_watch_states', 0);
        $this->assertDatabaseCount('learning_context_change_records', 0);

        $service = $this->enable();
        $this->assertSame('not_opted_in', $service->advance($owner, $plan->id)['status']);
        $this->assertDatabaseCount('learning_context_watch_states', 0);
    }

    public function test_owner_opt_in_encrypts_state_and_same_revision_is_idempotent(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $this->task($plan);
        $service = $this->enable();
        $this->actingAs($owner);
        $this->assertTrue($service->optIn($owner, $plan->id));
        $this->assertTrue($service->optIn($owner, $plan->id));
        $this->assertDatabaseCount('learning_context_watch_states', 1);
        $state = LearningContextWatchState::query()->sole();
        $this->assertSame($owner->id, $state->user_id);
        $this->assertSame($plan->id, $state->plan_id);
        $this->assertSame('enabled', $state->status);
        $this->assertSame('canovia.learning_context_source_capture.v0.2',
            $state->encrypted_baseline['schema']);

        $stored = DB::table('learning_context_watch_states')
            ->where('id', $state->id)->value('encrypted_baseline');
        $this->assertStringNotContainsString('PRIVATE_DNS_TASK_TITLE', $stored);
        $this->assertStringNotContainsString('SECRET_TASK_DESCRIPTION_NOT_STORED', $stored);
        $this->assertNotSame('PRIVATE_SYNTHETIC_LEARNING', $stored);
        $this->assertSame('unchanged', $service->advance($owner, $plan->id)['status']);
        $this->assertDatabaseCount('learning_context_change_records', 0);
    }

    public function test_review_only_record_is_deduped_even_after_repeated_a_b_transition(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $task = $this->task($plan);
        $service = $this->enable();
        $this->actingAs($owner);
        $this->assertTrue($service->optIn($owner, $plan->id));

        $task->update(['status' => 'paused']);
        $r = $service->advance($owner, $plan->id);
        $this->assertSame('recorded_review_candidate', $r['status']);
        $this->assertFalse($r['notification_eligible']);
        $this->assertFalse($r['mutation_performed']);
        $record = LearningContextChangeRecord::query()->sole();
        $firstPairHash = $record->dedupe_fingerprint;
        $this->assertSame('review_only', $record->status);
        $this->assertSame(['recorded_task_paused'], $record->event_kinds);
        $this->assertSame('unchanged', $service->advance($owner, $plan->id)['status']);

        $task->update(['status' => 'doing']);
        $this->assertSame('updated_no_candidate', $service->advance($owner, $plan->id)['status']);
        $this->assertSame('superseded', $record->fresh()->status);

        $task->update(['status' => 'paused']);
        $this->assertSame('duplicate_suppressed', $service->advance($owner, $plan->id)['status']);
        $this->assertDatabaseCount('learning_context_change_records', 1);
        $this->assertSame($firstPairHash, LearningContextChangeRecord::query()->sole()->dedupe_fingerprint);
        $this->assertSame('superseded', LearningContextChangeRecord::query()->sole()->status);
    }

    public function test_a_corrected_source_revision_supersedes_previous_candidate_without_delivery(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $task = $this->task($plan);
        $service = $this->enable();
        $this->actingAs($owner);
        $this->assertTrue($service->optIn($owner, $plan->id));

        $task->update(['status' => 'paused']);
        $this->assertSame('recorded_review_candidate', $service->advance($owner, $plan->id)['status']);
        $oldHash = LearningContextWatchState::query()->sole()->source_revision;
        $task->update(['progress_percent' => 35]);
        $this->assertSame('updated_no_candidate', $service->advance($owner, $plan->id)['status']);
        $this->assertNotSame($oldHash, LearningContextWatchState::query()->sole()->source_revision);
        $this->assertSame('superseded', LearningContextChangeRecord::query()->sole()->status);
        $this->assertDatabaseMissing('learning_context_change_records', ['status' => 'delivered']);
    }

    public function test_old_practice_correction_supersedes_prior_review_candidate_without_new_alert(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $task = $this->task($plan);
        $old = null;
        for ($i = 0; $i < 11; ++$i) {
            $attempt = StudyPracticeAttempt::query()->create([
                'user_id' => $owner->id,
                'plan_id' => $plan->id,
                'task_id' => $task->id,
                'request_hash' => hash('sha256', 'historical-persistence-'.$plan->id.'-'.$i),
                'exercise_title' => 'SYNTHETIC_OWNER_STUDY',
                'questions' => [['body' => 'PRIVATE_QUESTION']],
                'answers' => [['answer' => 'PRIVATE_ANSWER']],
                'assessment' => ['rationale' => 'PRIVATE_ASSESSMENT'],
                'score_percent' => 60,
                'weaknesses' => [],
                'recommended_task_progress_percent' => 30,
                'applied_at' => now()->subDays(23 - $i),
            ]);
            $old ??= $attempt;
        }

        $service = $this->enable();
        $this->actingAs($owner);
        $this->assertTrue($service->optIn($owner, $plan->id));
        $task->update(['status' => 'paused']);
        $this->assertSame('recorded_review_candidate',
            $service->advance($owner, $plan->id)['status']);
        $before = LearningContextWatchState::query()->sole()->source_revision;
        $candidate = LearningContextChangeRecord::query()->sole();

        // The oldest row falls outside the latest-eight displayed sample.
        // Its correction must retire the previously persisted review only.
        $old->update(['score_percent' => 14]);
        $this->assertSame('updated_no_candidate',
            $service->advance($owner, $plan->id)['status']);
        $this->assertNotSame($before,
            LearningContextWatchState::query()->sole()->source_revision);
        $this->assertSame('superseded', $candidate->fresh()->status);
        $this->assertDatabaseCount('learning_context_change_records', 1);
        $this->assertDatabaseMissing('learning_context_change_records', ['status' => 'delivered']);

        $old->delete();
        $this->assertSame('updated_no_candidate',
            $service->advance($owner, $plan->id)['status']);
        $this->assertDatabaseCount('learning_context_change_records', 1);
        $this->assertSame('unchanged', $service->advance($owner, $plan->id)['status']);
    }

    public function test_owner_opt_out_erases_baseline_and_records_even_when_flag_disabled(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $task = $this->task($plan);
        $service = $this->enable();
        $this->actingAs($owner);
        $this->assertTrue($service->optIn($owner, $plan->id));
        $task->update(['status' => 'paused']);
        $this->assertSame('recorded_review_candidate', $service->advance($owner, $plan->id)['status']);

        config(['intelligence.learning_context_watch.persistence_enabled' => false]);
        $this->assertTrue($service->optOut($owner, $plan->id));
        $this->assertDatabaseCount('learning_context_watch_states', 0);
        $this->assertDatabaseCount('learning_context_change_records', 0);
    }

    public function test_different_actor_cannot_opt_in_read_or_opt_out_other_owner(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $plan = $this->plan($owner);
        $service = $this->enable();

        $this->actingAs($foreign);
        $this->assertFalse($service->optIn($owner, $plan->id));
        $this->assertFalse($service->optIn($foreign, $plan->id));
        $this->assertSame('unauthorized', $service->advance($foreign, $plan->id)['status']);
        $this->assertFalse($service->optOut($foreign, $plan->id));

        $this->actingAs($owner);
        $this->assertTrue($service->optIn($owner, $plan->id));
        $this->actingAs($foreign);
        $this->assertFalse($service->optOut($owner, $plan->id));
        $this->assertDatabaseCount('learning_context_watch_states', 1);
        $this->assertDatabaseCount('learning_context_change_records', 0);
    }

    public function test_public_collaborative_nonstudy_or_transferred_plan_never_advances(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $plan = $this->plan($owner);
        $service = $this->enable();
        $this->actingAs($owner);
        $this->assertTrue($service->optIn($owner, $plan->id));

        $plan->update(['is_collaborative' => true]);
        $this->assertSame('unauthorized', $service->advance($owner, $plan->id)['status']);
        $plan->update(['is_collaborative' => false, 'is_public' => true]);
        $this->assertSame('unauthorized', $service->advance($owner, $plan->id)['status']);
        $plan->update(['is_public' => false, 'category' => 'ソフトウェア開発']);
        $this->assertSame('capture_unavailable', $service->advance($owner, $plan->id)['status']);
        $plan->update(['category' => '資格学習', 'user_id' => $other->id]);
        $this->assertSame('unauthorized', $service->advance($owner, $plan->id)['status']);

        // The new owner has to explicitly opt in again; previous owner data
        // is atomically replaced and the former owner's baseline is erased.
        $this->actingAs($other);
        $this->assertSame('not_opted_in', $service->advance($other, $plan->id)['status']);
        $this->assertTrue($service->optIn($other, $plan->id));
        $this->assertSame($other->id, LearningContextWatchState::query()->sole()->user_id);
        $this->assertDatabaseCount('learning_context_change_records', 0);
    }

    public function test_feature_staging_lockdown_and_source_title_change_require_reconsent(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $service = $this->enable();
        $this->actingAs($owner);
        $this->assertTrue($service->optIn($owner, $plan->id));

        $plan->update(['title' => 'NEW_PRIVATE_TITLE']);
        $this->assertSame('rebaseline_required', $service->advance($owner, $plan->id)['status']);
        $this->assertSame('PRIVATE_SYNTHETIC_LEARNING',
            LearningContextWatchState::query()->sole()->encrypted_baseline['snapshot']['facts']['plan']['title']);
        $this->assertTrue($service->optIn($owner, $plan->id));
        $this->assertSame('unchanged', $service->advance($owner, $plan->id)['status']);

        config(['app.env' => 'staging']);
        $this->assertFalse($service->optIn($owner, $plan->id));
        $this->assertSame('disabled', $service->advance($owner, $plan->id)['status']);
        $this->assertTrue($service->optOut($owner, $plan->id));
        $this->assertDatabaseCount('learning_context_watch_states', 0);
    }

    public function test_owner_can_erase_after_plan_becomes_public_or_nonstudy(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $service = $this->enable();
        $this->actingAs($owner);
        $this->assertTrue($service->optIn($owner, $plan->id));
        $plan->update(['is_public' => true, 'category' => 'ソフトウェア開発']);
        $this->assertSame('unauthorized', $service->advance($owner, $plan->id)['status']);
        config(['intelligence.learning_context_watch.persistence_enabled' => false]);
        $this->assertTrue($service->optOut($owner, $plan->id));
        $this->assertDatabaseCount('learning_context_watch_states', 0);
        $this->assertDatabaseCount('learning_context_change_records', 0);
    }

    public function test_expiry_and_retained_history_are_bounded_on_next_advance(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $task = $this->task($plan);
        $service = $this->enable();
        $this->actingAs($owner);
        $this->assertTrue($service->optIn($owner, $plan->id));
        $revision = LearningContextWatchState::query()->sole()->source_revision;

        for ($i = 1; $i <= 40; ++$i) {
            LearningContextChangeRecord::query()->create([
                'user_id' => $owner->id,
                'plan_id' => $plan->id,
                'source_revision' => $revision,
                'dedupe_fingerprint' => str_pad(dechex($i), 64, '0', STR_PAD_LEFT),
                'event_kinds' => ['recorded_task_paused'],
                'status' => 'review_only',
                'expires_at' => $i <= 5 ? now()->subDay() : now()->addDays(30),
            ]);
        }
        $this->assertDatabaseCount('learning_context_change_records', 40);
        $task->update(['status' => 'paused']);
        $this->assertSame('recorded_review_candidate', $service->advance($owner, $plan->id)['status']);
        $this->assertDatabaseCount('learning_context_change_records', 32);
        $this->assertSame(1, LearningContextChangeRecord::query()->where('status', 'review_only')->count());
        $this->assertSame(0, LearningContextChangeRecord::query()
            ->where('expires_at', '<=', now())->count());
    }

    public function test_migration_replay_does_not_erase_stored_records_and_cascade_erases_on_plan_delete(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $task = $this->task($plan);
        $service = $this->enable();
        $this->actingAs($owner);
        $this->assertTrue($service->optIn($owner, $plan->id));
        $task->update(['status' => 'paused']);
        $this->assertSame('recorded_review_candidate', $service->advance($owner, $plan->id)['status']);

        $migration = require database_path('migrations/2026_10_15_110000_create_learning_context_watch_persistence.php');
        // Applied ledger entries are skipped on a normal migration replay.
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        try {
            $migration->up();
            $this->fail('Existing tables must require manual state review.');
        } catch (\RuntimeException $e) {
            $this->assertSame('RELEASE20_WATCH_EXISTING_TABLE_BLOCK', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('learning_context_watch_states'));
        $this->assertTrue(Schema::hasTable('learning_context_change_records'));
        $this->assertDatabaseCount('learning_context_watch_states', 1);
        $this->assertDatabaseCount('learning_context_change_records', 1);

        $plan->delete();
        $this->assertDatabaseCount('learning_context_watch_states', 0);
        $this->assertDatabaseCount('learning_context_change_records', 0);
    }
}
