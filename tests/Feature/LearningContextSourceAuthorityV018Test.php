<?php

namespace Tests\Feature;

use App\Models\LearningContextWatchState;
use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\User;
use App\Services\LearningContextSourceCaptureService;
use App\Services\LearningContextSourceEpochService;
use App\Services\LearningContextWatchPersistenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * v0.18 authority contract: an optional application epoch is diagnostics
 * only. Runtime Watch freshness and access rely on the full history hash.
 * SQLite, synthetic owner fixtures, no sender/scheduler/provider calls.
 */
final class LearningContextSourceAuthorityV018Test extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, Plan, Task} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'SYNTHETIC_V018_PRIVATE_STUDY',
            'category' => '資格学習',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(12),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'SYNTHETIC_V018_TASK',
            'description' => 'not shared',
            'status' => 'doing',
            'progress_percent' => 18,
            'priority' => 1,
            'sort_order' => 1,
        ]);
        return [$owner, $plan, $task];
    }

    private function enableBoth(): LearningContextWatchPersistenceService
    {
        // Test-only switches: the production defaults remain unchanged.
        config([
            'intelligence.learning_context_watch.epoch_shadow_enabled' => true,
            'intelligence.learning_context_watch.persistence_enabled' => true,
        ]);
        return app(LearningContextWatchPersistenceService::class);
    }

    private function seedShadowUnderPlanLock(Plan $plan): int
    {
        return DB::transaction(function () use ($plan): int {
            $locked = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            // This synthetic first-party source write is coupled to the
            // diagnostic record in one transaction, unlike later raw SQL.
            DB::table('tasks')->where('plan_id', $locked->id)->update([
                'progress_percent' => 19,
            ]);
            return app(LearningContextSourceEpochService::class)
                ->record($locked, 'task_updated');
        });
    }

    public function test_raw_task_update_invalidates_full_hash_while_shadow_epoch_stays_unchanged(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $watch = $this->enableBoth();
        $this->assertSame(1, $this->seedShadowUnderPlanLock($plan));
        $this->actingAs($owner);
        $this->assertTrue($watch->optIn($owner, $plan->id));

        $capture = app(LearningContextSourceCaptureService::class);
        $before = $capture->capture($owner, $plan->id);
        $this->assertNotNull($before);
        $this->assertSame(1, app(LearningContextSourceEpochService::class)
            ->inspect($plan->id)['epoch']);

        // Bypass the application Epoch writer entirely: only the hashed
        // Task title changes, not its timestamp or the shadow counter.
        DB::table('tasks')->where('id', $task->id)
            ->update(['title' => 'RAW_DIRECT_SQL_REVISION']);
        $after = $capture->capture($owner, $plan->id);
        $this->assertNotSame($before['source_revision'], $after['source_revision']);
        $this->assertFalse($capture->isCurrent($owner, $plan->id, $before['source_revision']));
        $this->assertSame(1, app(LearningContextSourceEpochService::class)
            ->inspect($plan->id)['epoch']);

        $result = $watch->advance($owner, $plan->id);
        $this->assertSame('updated_no_candidate', $result['status']);
        $this->assertFalse($result['notification_eligible']);
        $this->assertFalse($result['mutation_performed']);
        $this->assertSame($after['source_revision'],
            LearningContextWatchState::query()->sole()->source_revision);
        $this->assertDatabaseCount('learning_context_change_records', 0);
    }

    public function test_old_applied_practice_correction_outside_visible_eight_is_not_hidden_by_shadow(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $watch = $this->enableBoth();
        $this->assertSame(1, $this->seedShadowUnderPlanLock($plan));
        $old = null;
        for ($i = 0; $i < 11; ++$i) {
            $attempt = StudyPracticeAttempt::query()->create([
                'user_id' => $owner->id,
                'plan_id' => $plan->id,
                'task_id' => $task->id,
                'request_hash' => hash('sha256', 'v018-applied-'.$plan->id.'-'.$i),
                'exercise_title' => 'SYNTHETIC_ONLY',
                'questions' => [], 'answers' => [], 'assessment' => [],
                'score_percent' => 60, 'weaknesses' => [],
                'recommended_task_progress_percent' => 22,
                'applied_at' => now()->subDays(25 - $i),
            ]);
            $old ??= $attempt;
        }
        $this->actingAs($owner);
        $this->assertTrue($watch->optIn($owner, $plan->id));
        $capture = app(LearningContextSourceCaptureService::class);
        $before = $capture->capture($owner, $plan->id);
        $this->assertSame(8, $before['snapshot']['facts']['recent_practice']['applied_attempts_considered']);

        // An uninstrumented historical correction is older than the eight
        // displayed items, but remains part of the authoritative digest.
        DB::table('study_practice_attempts')->where('id', $old->id)
            ->update(['score_percent' => 13]);
        $after = $capture->capture($owner, $plan->id);
        $this->assertNotSame($before['source_revision'], $after['source_revision']);
        $this->assertSame(
            $before['snapshot']['facts']['recent_practice']['latest_score_percent'],
            $after['snapshot']['facts']['recent_practice']['latest_score_percent'],
        );
        $this->assertSame(1, app(LearningContextSourceEpochService::class)
            ->inspect($plan->id)['epoch']);

        $result = $watch->advance($owner, $plan->id);
        $this->assertSame('updated_no_candidate', $result['status']);
        $this->assertFalse($result['notification_eligible']);
        $this->assertFalse($result['mutation_performed']);
        $this->assertSame($after['source_revision'],
            LearningContextWatchState::query()->sole()->source_revision);
        $this->assertDatabaseCount('learning_context_change_records', 0);
    }

    public function test_diagnostic_epoch_drift_does_not_generate_watch_change_or_override_owner_policy(): void
    {
        [$owner, $plan] = $this->fixture();
        $watch = $this->enableBoth();
        $this->actingAs($owner);
        $this->assertTrue($watch->optIn($owner, $plan->id));
        $capture = app(LearningContextSourceCaptureService::class);
        $original = $capture->capture($owner, $plan->id);
        $this->assertNull(app(LearningContextSourceEpochService::class)->inspect($plan->id));

        // Explicitly simulate an erroneous diagnostic-only counter drift.
        // No application writer or source mutation is invoked.
        DB::table('learning_context_source_epochs')->insert([
            'plan_id' => $plan->id, 'epoch' => 8,
            'last_mutation_kind' => 'task_updated',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(8, app(LearningContextSourceEpochService::class)
            ->inspect($plan->id)['epoch']);
        $this->assertSame($original['source_revision'],
            $capture->capture($owner, $plan->id)['source_revision']);
        $this->assertSame('unchanged', $watch->advance($owner, $plan->id)['status']);
        $this->assertDatabaseCount('learning_context_change_records', 0);

        // A raw owner-visibility change must deny Watch even if epoch does
        // not move, and must not release a review or send a notification.
        DB::table('plans')->where('id', $plan->id)->update(['is_public' => true]);
        $result = $watch->advance($owner, $plan->id);
        $this->assertSame('unauthorized', $result['status']);
        $this->assertFalse($result['notification_eligible']);
        $this->assertFalse($result['mutation_performed']);
        $this->assertNull($capture->capture($owner, $plan->id));
        $this->assertSame(8, app(LearningContextSourceEpochService::class)
            ->inspect($plan->id)['epoch']);
    }
}
