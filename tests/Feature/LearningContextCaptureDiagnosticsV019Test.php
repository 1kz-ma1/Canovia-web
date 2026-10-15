<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\User;
use App\Services\LearningContextCaptureDiagnostics;
use App\Services\LearningContextSourceCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LearningContextCaptureDiagnosticsV019Test extends TestCase
{
    use RefreshDatabase;

    /** @return array{User,Plan,Task} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'SYNTHETIC_CAPTURE_PRIVATE_TITLE',
            'category' => '資格学習',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(10),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'SYNTHETIC_CAPTURE_PRIVATE_TASK',
            'description' => 'SECRET_CAPTURE_PRIVATE_DESCRIPTION',
            'status' => 'doing',
            'progress_percent' => 11,
            'priority' => 1,
            'sort_order' => 1,
        ]);
        return [$owner, $plan, $task];
    }

    private function collector(): LearningContextCaptureDiagnostics
    {
        $collector = new LearningContextCaptureDiagnostics;
        // Bind one local in-memory collector to the test container, so the
        // service's observations can be asserted without any global logging.
        app()->instance(LearningContextCaptureDiagnostics::class, $collector);
        return $collector;
    }

    public function test_diagnostics_default_off_and_unavailable_in_staging_or_production_even_if_requested(): void
    {
        [$owner, $plan] = $this->fixture();
        $collector = $this->collector();
        $capture = app(LearningContextSourceCaptureService::class);

        $baseline = $capture->capture($owner, $plan->id);
        $this->assertNotNull($baseline);
        $this->assertSame(0, $collector->snapshot()['total']);

        config(['intelligence.learning_context_watch.capture_diagnostics_enabled' => true]);
        $this->assertTrue($collector->enabled());
        $this->assertSame($baseline, $capture->capture($owner, $plan->id));
        $this->assertSame(1, $collector->snapshot()['total']);

        config(['app.env' => 'staging']);
        $this->assertFalse($collector->enabled());
        $this->assertSame($baseline, $capture->capture($owner, $plan->id));
        $this->assertSame(1, $collector->snapshot()['total']);

        config(['app.env' => 'production']);
        $this->assertFalse($collector->enabled());
        $this->assertSame($baseline, $capture->capture($owner, $plan->id));
        $this->assertSame(1, $collector->snapshot()['total']);
    }

    public function test_only_fixed_aggregate_buckets_observe_owner_capture_and_denied_actor(): void
    {
        [$owner, $plan] = $this->fixture();
        $other = User::factory()->create();
        $collector = $this->collector();
        config(['intelligence.learning_context_watch.capture_diagnostics_enabled' => true]);
        $capture = app(LearningContextSourceCaptureService::class);

        $record = $capture->capture($owner, $plan->id);
        $this->assertNotNull($record);
        $this->assertNull($capture->capture($other, $plan->id));
        $this->assertTrue($capture->isCurrent($owner, $plan->id, $record['source_revision']));
        $this->assertFalse($capture->isCurrent($other, $plan->id, $record['source_revision']));

        $stats = $collector->snapshot();
        $this->assertSame(4, $stats['total']);
        $this->assertSame(2, $stats['outcomes']['captured']);
        $this->assertSame(2, $stats['outcomes']['denied']);
        $this->assertSame(2, $stats['task_rows']['1_8']);
        $this->assertSame(2, $stats['practice_rows']['0']);
        $this->assertSame(2, $stats['task_rows']['not_counted']);
        $this->assertSame(2, $stats['practice_rows']['not_counted']);
        $this->assertSame(4, array_sum($stats['duration']));
        $this->assertFalse($stats['persistent']);
        $this->assertFalse($stats['authoritative']);
        $this->assertSame(
            ['schema', 'total', 'outcomes', 'duration', 'task_rows', 'practice_rows', 'persistent', 'authoritative'],
            array_keys($stats),
        );
        $safeJson = json_encode($stats, JSON_THROW_ON_ERROR);
        foreach ([
            'SYNTHETIC_CAPTURE_PRIVATE_TITLE',
            'SYNTHETIC_CAPTURE_PRIVATE_TASK',
            'SECRET_CAPTURE_PRIVATE_DESCRIPTION',
            $record['source_revision'],
            'actor_id', 'plan_id', 'user_id', 'question',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $safeJson);
        }
    }

    public function test_task_cap_records_coarse_size_and_preserves_fail_closed_capture(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $collector = $this->collector();
        config(['intelligence.learning_context_watch.capture_diagnostics_enabled' => true]);
        $capture = app(LearningContextSourceCaptureService::class);
        $original = $capture->capture($owner, $plan->id);
        $this->assertNotNull($original);

        $now = now();
        $rows = [];
        for ($i = 0; $i < 256; ++$i) {
            $rows[] = [
                'plan_id' => $plan->id, 'title' => 'SYNTHETIC_OVER_CAP',
                'status' => 'todo', 'progress_percent' => 0,
                'priority' => 1, 'sort_order' => $i + 10,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        // Split the insert to stay below the SQLite bind-variable limit.
        foreach (array_chunk($rows, 50) as $chunk) {
            Task::query()->insert($chunk);
        }
        $this->assertNull($capture->capture($owner, $plan->id));
        $this->assertFalse($capture->isCurrent($owner, $plan->id, $original['source_revision']));
        $stats = $collector->snapshot();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['outcomes']['task_cap']);
        $this->assertSame(2, $stats['task_rows']['over_cap']);
        $this->assertSame(2, $stats['practice_rows']['not_counted']);
        $this->assertSame(1, $stats['outcomes']['captured']);
    }

    public function test_practice_cap_records_only_bucket_without_partial_revision(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $collector = $this->collector();
        config(['intelligence.learning_context_watch.capture_diagnostics_enabled' => true]);
        $capture = app(LearningContextSourceCaptureService::class);
        $baseline = $capture->capture($owner, $plan->id);
        $this->assertNotNull($baseline);

        $now = now();
        for ($start = 0; $start < 513; $start += 70) {
            $batch = [];
            for ($i = $start; $i < min($start + 70, 513); ++$i) {
                $batch[] = [
                    'user_id' => $owner->id, 'plan_id' => $plan->id,
                    'task_id' => $task->id,
                    'request_hash' => hash('sha256', 'v019-synthetic-'.$plan->id.'-'.$i),
                    'exercise_title' => 'SECRET_PRACTICE_PAYLOAD',
                    'questions' => '[]', 'answers' => '[]', 'assessment' => '{}',
                    'score_percent' => 60, 'recommended_task_progress_percent' => 30,
                    'applied_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            StudyPracticeAttempt::query()->insert($batch);
        }
        $this->assertNull($capture->capture($owner, $plan->id));
        $this->assertFalse($capture->isCurrent($owner, $plan->id, $baseline['source_revision']));
        $stats = $collector->snapshot();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['outcomes']['practice_cap']);
        $this->assertSame(2, $stats['practice_rows']['over_cap']);
        // Baseline plus two over-cap capture attempts all counted the one Task.
        $this->assertSame(3, $stats['task_rows']['1_8']);
        $this->assertStringNotContainsString('SECRET_PRACTICE_PAYLOAD',
            json_encode($stats, JSON_THROW_ON_ERROR));
    }

    public function test_histogram_drops_raw_and_adversarial_dimensions(): void
    {
        $collector = $this->collector();
        config(['intelligence.learning_context_watch.capture_diagnostics_enabled' => true]);
        foreach ([0, 5_000_000, 25_000_000, 100_000_000, 500_000_000] as $duration) {
            $collector->record('SECRET_UNKNOWN_OUTCOME', $duration, 99999, 99999);
        }
        $stats = $collector->snapshot();
        $this->assertSame(5, $stats['outcomes']['error']);
        $this->assertSame(5, $stats['task_rows']['over_cap']);
        $this->assertSame(5, $stats['practice_rows']['over_cap']);
        foreach (['lt_5ms', '5_25ms', '25_100ms', '100_500ms', 'gte_500ms'] as $bucket) {
            $this->assertSame(1, $stats['duration'][$bucket]);
        }
        $this->assertStringNotContainsString('SECRET_UNKNOWN_OUTCOME',
            json_encode($stats, JSON_THROW_ON_ERROR));
    }
}
