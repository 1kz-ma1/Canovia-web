<?php

namespace Tests\Feature;

use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\UserStateSnapshot;
use App\Models\WorkSession;
use App\Services\ContinuityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class HomeCoreBundleCostReductionV5192Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'session.driver' => 'array',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_core_bundle_batches_timeline_adjustments_and_skips_collaboration_log_query_when_unused(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        foreach (range(1, 8) as $index) {
            $plan = $this->plan($user, 'Plan '.$index);
            $this->task($plan, 'Task '.$index);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)
            ->withHeader('X-Canovia-Instant-Navigation', 'prefetch')
            ->get('/instant/core-bundle?surfaces=roadmap,timeline,calendar')
            ->assertOk();

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn ($sql) => mb_strtolower((string) $sql))
            ->values();

        DB::disableQueryLog();

        $adjustmentSelects = $queries->filter(
            fn (string $sql) => $this->selectsFrom($sql, 'plan_adjustments')
        );
        $activitySelects = $queries->filter(
            fn (string $sql) => $this->selectsFrom($sql, 'plan_activity_logs')
        );

        $this->assertSame(
            1,
            $adjustmentSelects->count(),
            'Timeline adjustments must be eager loaded once for all Plans, not once per Plan.',
        );
        $this->assertSame(
            0,
            $activitySelects->count(),
            'Non-collaborative accounts must not pay for Timeline collaboration activity queries.',
        );
    }

    public function test_repeated_home_view_uses_session_fast_path_for_record_once_dedupe(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'Home');
        $this->task($plan, 'Next Task');

        $this->actingAs($user)->get('/')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get('/')->assertOk();

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn ($sql) => mb_strtolower((string) $sql))
            ->values();

        DB::disableQueryLog();

        $dedupeQueries = $queries->filter(
            fn (string $sql) => str_contains($sql, 'behavior_events')
                && str_contains($sql, 'exists')
        );

        $this->assertSame(
            0,
            $dedupeQueries->count(),
            'Repeated Home views in the same session should not query behavior_events only to dedupe recordOnce events.',
        );

        $this->assertGreaterThanOrEqual(1, BehaviorEvent::query()->count());
    }

    public function test_home_state_snapshot_write_is_throttled_but_new_day_boundary_is_preserved(): void
    {
        Carbon::setTestNow('2026-10-03 10:00:00');

        try {
            $user = User::factory()->create(['first_run_completed_at' => now()]);
            $plan = $this->plan($user, 'Snapshot');
            $this->task($plan, 'Snapshot Task');

            $this->actingAs($user)->get('/')->assertOk();

            $snapshot = UserStateSnapshot::query()->firstOrFail();
            $initialUpdatedAt = $snapshot->updated_at?->copy();

            Carbon::setTestNow('2026-10-03 10:05:00');
            $this->get('/')->assertOk();

            $this->assertTrue(
                UserStateSnapshot::query()->firstOrFail()->updated_at?->equalTo($initialUpdatedAt),
                'Home should not rewrite the daily snapshot on every repeated view.',
            );

            Carbon::setTestNow('2026-10-03 10:11:00');
            $this->get('/')->assertOk();

            $this->assertTrue(
                UserStateSnapshot::query()->firstOrFail()->updated_at?->greaterThan($initialUpdatedAt),
                'Home should refresh the snapshot after the throttle window.',
            );

            Carbon::setTestNow('2026-10-04 00:01:00');
            $this->get('/')->assertOk();

            $this->assertDatabaseHas('user_state_snapshots', [
                'actor_token' => $snapshot->actor_token,
                'snapshot_date' => '2026-10-04',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_home_work_session_context_batches_plan_and_task_hydration(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'Continuity');
        $task = $this->task($plan, 'Continue Task');
        $actorToken = Str::random(64);

        $session = WorkSession::query()->create([
            'actor_token' => $actorToken,
            'browser_session_id' => 'browser-v5192',
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'status' => 'active',
            'intended_minutes' => 30,
            'started_at' => now()->subMinute(),
            'source' => 'dashboard',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $context = app(ContinuityService::class)->homeContext(
            collect([$plan]),
            $actorToken,
        );

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn ($sql) => mb_strtolower((string) $sql))
            ->values();

        DB::disableQueryLog();

        $this->assertSame($session->id, $context['active_work_session']?->id);
        $this->assertSame($task->id, data_get($context, 'continuity.task_id'));
        $this->assertCount(0, $context['pending_plan_updates']);

        $this->assertSame(
            2,
            $queries->filter(fn (string $sql) => $this->selectsFrom($sql, 'work_sessions'))->count(),
        );
        $this->assertSame(
            1,
            $queries->filter(fn (string $sql) => $this->selectsFrom($sql, 'plans'))->count(),
            'The latest active session must reuse the same hydrated Plan relation instance.',
        );
        $this->assertSame(
            1,
            $queries->filter(fn (string $sql) => $this->selectsFrom($sql, 'tasks'))->count(),
            'The latest active session must reuse the same hydrated Task relation instance.',
        );
    }

    public function test_v5192_source_contract_keeps_batch_and_session_fast_paths(): void
    {
        $timeline = file_get_contents(app_path('Services/TimelinePageDataService.php'));
        $logger = file_get_contents(app_path('Services/BehaviorEventLogger.php'));
        $home = file_get_contents(app_path('Services/HomePageDataService.php'));

        $this->assertStringContainsString(
            "(new EloquentCollection(\$plans->all()))->loadMissing('adjustments')",
            $timeline,
        );
        $this->assertStringContainsString(
            "contains(fn (Plan \$plan) => (bool) \$plan->is_collaborative)",
            $timeline,
        );
        $this->assertStringContainsString('recordedRecentlyInSession', $logger);
        $this->assertStringContainsString('ONCE_SESSION_PREFIX', $logger);
        $this->assertStringContainsString('STATE_SNAPSHOT_INTERVAL_SECONDS = 600', $home);
        $this->assertStringContainsString('STATE_SNAPSHOT_DATE_SESSION_KEY', $home);
    }

    private function selectsFrom(string $sql, string $table): bool
    {
        if (! str_starts_with(ltrim($sql), 'select')) {
            return false;
        }

        return preg_match(
            '/\\bfrom\\s+["]?'.preg_quote($table, '/').'["]?\\b/i',
            $sql,
        ) === 1;
    }

    private function plan(User $user, string $title): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => 'その他',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'next_action_note' => $title.'を進める',
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);
    }
}
