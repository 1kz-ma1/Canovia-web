<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\User;
use App\Services\LearningContextSourceCaptureService;
use App\Services\LearningContextSourceEpochService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class LearningContextSourceEpochShadowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'SYNTHETIC_AP_SOURCE_PLAN',
            'category' => '資格学習',
            'priority' => 1, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(10),
            'is_public' => false, 'is_collaborative' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'DNS',
            'status' => 'doing',
            'progress_percent' => 20,
            'priority' => 1, 'sort_order' => 1,
        ]);
        return [$user, $plan, $task];
    }

    private function enabled(): LearningContextSourceEpochService
    {
        config(['intelligence.learning_context_watch.epoch_shadow_enabled' => true]);
        return app(LearningContextSourceEpochService::class);
    }

    private function record(Plan $plan, string $kind): ?int
    {
        $service = app(LearningContextSourceEpochService::class);
        return DB::transaction(function () use ($plan, $kind, $service): ?int {
            $locked = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            return $service->record($locked, $kind);
        });
    }

    public function test_shadow_gate_off_and_staging_deny_writes_and_do_not_affect_source_capture(): void
    {
        [$owner, $plan] = $this->fixture();
        $shadow = app(LearningContextSourceEpochService::class);
        $capture = app(LearningContextSourceCaptureService::class);
        $before = $capture->capture($owner, $plan->id);
        $this->assertNull($shadow->record($plan, 'task_updated'));
        $this->assertNull($shadow->inspect($plan->id));
        $this->assertDatabaseCount('learning_context_source_epochs', 0);

        $this->enabled();
        config(['app.env' => 'staging']);
        $this->assertFalse($shadow->enabled());
        $this->assertNull($shadow->record($plan, 'task_updated'));
        $this->assertNull($shadow->inspect($plan->id));
        $this->assertSame($before['source_revision'],
            $capture->capture($owner, $plan->id)['source_revision']);
        $this->assertDatabaseCount('learning_context_source_epochs', 0);
    }

    public function test_monotonic_epoch_commits_only_with_source_transaction_and_is_never_authoritative(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $shadow = $this->enabled();
        $capture = app(LearningContextSourceCaptureService::class);
        $old = $capture->capture($owner, $plan->id);
        $this->assertNull($shadow->inspect($plan->id));

        $first = DB::transaction(function () use ($plan, $task, $shadow) {
            $locked = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $task->update(['status' => 'paused']);
            return $shadow->record($locked, 'task_updated');
        });
        $this->assertSame(1, $first);
        $this->assertSame(['epoch' => 1, 'last_mutation_kind' => 'task_updated', 'authoritative' => false],
            $shadow->inspect($plan->id));
        $this->assertNotSame($old['source_revision'],
            $capture->capture($owner, $plan->id)['source_revision']);

        $this->assertSame(2, $this->record($plan, 'practice_applied'));
        $this->assertSame(3, $this->record($plan, 'approved_result_changed'));
        $this->assertSame(3, $shadow->inspect($plan->id)['epoch']);
        $this->assertDatabaseCount('learning_context_source_epochs', 1);
        $this->assertDatabaseHas('learning_context_source_epochs', [
            'plan_id' => $plan->id, 'epoch' => 3,
            'last_mutation_kind' => 'approved_result_changed',
        ]);
        $this->assertDatabaseMissing('learning_context_source_epochs', ['epoch' => 4]);
        $this->assertSame('canovia.learning_context_source_capture.v0.2',
            $capture->capture($owner, $plan->id)['schema']);
    }

    public function test_rollback_reverts_task_and_epoch_together_and_does_not_create_second_row(): void
    {
        [, $plan, $task] = $this->fixture();
        $shadow = $this->enabled();
        $this->assertSame(1, $this->record($plan, 'task_created'));

        try {
            DB::transaction(function () use ($plan, $task, $shadow): void {
                $locked = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
                $task->update(['status' => 'paused']);
                $this->assertSame(2, $shadow->record($locked, 'task_updated'));
                throw new RuntimeException('synthetic mutation failure');
            });
            $this->fail('Expected full rollback.');
        } catch (RuntimeException $error) {
            $this->assertSame('synthetic mutation failure', $error->getMessage());
        }

        $this->assertSame('doing', $task->fresh()->status);
        $this->assertSame(1, $shadow->inspect($plan->id)['epoch']);
        $this->assertDatabaseCount('learning_context_source_epochs', 1);
        $plan->delete();
        $this->assertDatabaseCount('learning_context_source_epochs', 0);
    }

    public function test_invalid_kind_and_overflow_fail_closed_with_no_mutation(): void
    {
        [, $plan] = $this->fixture();
        $shadow = $this->enabled();
        try {
            $this->record($plan, 'PRIVATE_TOPIC');
            $this->fail('Unknown mutation kind must fail.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('learning_context_source_epochs', 0);
        }
        $this->assertSame(1, $this->record($plan, 'task_created'));
        DB::table('learning_context_source_epochs')->where('plan_id', $plan->id)
            ->update(['epoch' => PHP_INT_MAX]);

        try {
            $this->record($plan, 'task_updated');
            $this->fail('Counter overflow must fail.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('overflow', $error->getMessage());
        }
        $this->assertSame(PHP_INT_MAX, $shadow->inspect($plan->id)['epoch']);
    }

    public function test_existing_old_row_correction_changes_authoritative_hash_but_not_partial_epoch(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $shadow = $this->enabled();
        $oldAttempt = null;
        for ($i = 0; $i < 11; ++$i) {
            $attempt = StudyPracticeAttempt::query()->create([
                'plan_id' => $plan->id,
                'task_id' => $task->id,
                'user_id' => $owner->id,
                'request_hash' => hash('sha256', 'epoch-shadow-old-'.$plan->id.'-'.$i),
                'exercise_title' => 'SYNTHETIC_ONLY',
                'questions' => [], 'answers' => [], 'assessment' => [],
                'score_percent' => 60, 'weaknesses' => [],
                'recommended_task_progress_percent' => 30,
                'applied_at' => now()->subDays(22 - $i),
            ]);
            $oldAttempt ??= $attempt;
        }
        $baseline = app(LearningContextSourceCaptureService::class)
            ->capture($owner, $plan->id);
        $this->assertSame(1, $this->record($plan, 'task_created'));
        $oldAttempt->update(['score_percent' => 15]); // deliberate uninstrumented legacy writer
        $fresh = app(LearningContextSourceCaptureService::class)
            ->capture($owner, $plan->id);

        $this->assertNotSame($baseline['source_revision'], $fresh['source_revision']);
        $this->assertSame($baseline['snapshot'], $fresh['snapshot']);
        $this->assertSame(1, $shadow->inspect($plan->id)['epoch']);
        $this->assertFalse($shadow->inspect($plan->id)['authoritative']);
        $this->assertFalse(app(LearningContextSourceCaptureService::class)
            ->isCurrent($owner, $plan->id, $baseline['source_revision']));
    }

    public function test_first_party_http_task_update_and_delete_record_in_same_transaction(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $this->enabled();
        $this->withoutVite();
        $this->actingAs($owner);

        $payload = [
            'title' => 'HTTP_STUDY_SOURCE_CHANGED',
            'description' => 'PRIVATE_NOT_STORED_IN_EPOCH',
            'estimated_minutes' => 25, 'remaining_minutes' => 25,
            'progress_percent' => 30, 'status' => 'doing',
            'priority' => 1, 'activation_cost' => 1,
        ];
        $this->put(route('tasks.update', $task), $payload)
            ->assertRedirect(route('plans.show', $plan));
        $this->assertSame(1, DB::table('learning_context_source_epochs')
            ->where('plan_id', $plan->id)->value('epoch'));
        $this->assertSame('task_updated', DB::table('learning_context_source_epochs')
            ->where('plan_id', $plan->id)->value('last_mutation_kind'));
        $this->assertStringNotContainsString('PRIVATE_NOT_STORED_IN_EPOCH',
            json_encode(DB::table('learning_context_source_epochs')->first(), JSON_THROW_ON_ERROR));

        $this->delete(route('tasks.destroy', $task))
            ->assertRedirect(route('plans.show', $plan));
        $this->assertSame(2, DB::table('learning_context_source_epochs')
            ->where('plan_id', $plan->id)->value('epoch'));
        $this->assertFalse(Task::query()->whereKey($task->id)->exists());
    }

    public function test_native_practice_applies_once_and_epoch_does_not_advance_on_retry(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $shadow = $this->enabled();
        $this->withoutVite();
        $this->actingAs($owner);
        $attempt = StudyPracticeAttempt::query()->create([
            'user_id' => $owner->id, 'plan_id' => $plan->id,
            'task_id' => $task->id,
            'request_hash' => hash('sha256', 'epoch-native-apply-'.$plan->id),
            'exercise_title' => 'SYNTHETIC_DNS_PRACTICE',
            'questions' => [['id' => 'q1']],
            'answers' => [['answer' => 'A']],
            'assessment' => ['score_percent' => 80],
            'score_percent' => 80,
            'weaknesses' => ['DNS'],
            'recommended_task_progress_percent' => 55,
            'evidence_summary' => 'Synthetic result only',
            'next_action' => 'Review weakness',
        ]);
        $payload = ['attempt_id' => $attempt->id, 'request_hash' => $attempt->request_hash];
        $url = route('plans.tasks.study_practice.apply', [$plan, $task]);
        $this->post($url, $payload)->assertRedirect();
        $this->assertNotNull($attempt->fresh()->applied_at);
        $this->assertSame([
            'epoch' => 1,
            'last_mutation_kind' => 'practice_applied',
            'authoritative' => false,
        ], $shadow->inspect($plan->id));

        $this->post($url, $payload)->assertRedirect();
        $this->assertSame(1, $shadow->inspect($plan->id)['epoch']);
    }

    public function test_no_epoch_for_unrelated_development_or_collaborative_plan_even_if_shadow_enabled(): void
    {
        [, $plan] = $this->fixture();
        $shadow = $this->enabled();
        $plan->update(['category' => 'ソフトウェア開発']);
        $this->assertNull($this->record($plan->fresh(), 'task_updated'));
        $this->assertDatabaseCount('learning_context_source_epochs', 0);

        $plan->update(['category' => '資格学習', 'is_collaborative' => true]);
        $this->assertNull($this->record($plan->fresh(), 'task_updated'));
        $this->assertDatabaseCount('learning_context_source_epochs', 0);

        $plan->update(['is_collaborative' => false, 'is_public' => true]);
        $this->assertNull($this->record($plan->fresh(), 'task_updated'));
        $this->assertNull($shadow->inspect($plan->id));
    }

    public function test_migration_replay_preserves_epoch_and_incomplete_table_is_rejected(): void
    {
        [, $plan] = $this->fixture();
        $this->enabled();
        $this->assertSame(1, $this->record($plan, 'task_created'));

        $migration = require database_path(
            'migrations/2026_10_15_120000_create_learning_context_source_epochs_table.php'
        );
        $migration->up();
        $this->assertSame(1,
            app(LearningContextSourceEpochService::class)->inspect($plan->id)['epoch']);
        $this->assertTrue(Schema::hasTable('learning_context_source_epochs'));
    }
}
