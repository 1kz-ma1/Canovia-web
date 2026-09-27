<?php

namespace Tests\Feature;

use App\Data\UserStateData;
use App\Enums\BehaviorEventType;
use App\Enums\UserBehaviorState;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\ReleaseNote;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\RecommendationPersonalizationService;
use App\Services\RecommendationService;
use App\Services\UserBehaviorService;
use App\Services\UserStateService;
use App\Support\ReleaseNotes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NavigationPerformanceV4118Test extends TestCase
{
    use RefreshDatabase;

    public function test_behavior_history_is_loaded_once_across_state_and_recommendation_services(): void
    {
        $actorToken = str_repeat('p', 64);
        $plan = $this->planWithTasks(3);

        BehaviorEvent::create([
            'actor_token' => $actorToken,
            'event_type' => BehaviorEventType::WorkStarted,
            'plan_id' => $plan->id,
            'task_id' => $plan->tasks->first()->id,
            'session_id' => 'history-event',
            'occurred_at' => now()->subHour(),
            'metadata' => ['source' => 'dashboard', 'start_latency_seconds' => 20],
        ]);
        WorkSession::create([
            'actor_token' => $actorToken,
            'browser_session_id' => 'history-session',
            'plan_id' => $plan->id,
            'task_id' => $plan->tasks->first()->id,
            'status' => 'completed',
            'started_at' => now()->subMinutes(20),
            'ended_at' => now()->subMinutes(10),
            'actual_seconds' => 600,
            'paused_seconds' => 0,
            'source' => 'dashboard',
        ]);

        $plan = $plan->fresh(['tasks', 'workLogs', 'availabilityRules', 'availabilityOverrides']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $baseline = app(UserBehaviorService::class)->baseline($actorToken);
        $state = app(UserStateService::class)->calculate($actorToken, $baseline, collect([$plan]));
        app(RecommendationPersonalizationService::class)->profile($actorToken);
        app(RecommendationService::class)->recommend(
            collect([$plan]),
            $state,
            actorToken: $actorToken,
        );
        app(RecommendationService::class)->recommend(
            collect([$plan]),
            $state,
            actorToken: $actorToken,
            excludedTaskIds: [$plan->tasks->first()->id],
        );

        $groups = $this->queryGroups(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $groups['behavior_events'] ?? 0);
        $this->assertSame(1, $groups['work_sessions'] ?? 0);
    }

    public function test_daily_state_capture_uses_one_upsert_query(): void
    {
        $state = new UserStateData(
            60,
            30,
            55,
            50,
            UserBehaviorState::Normal,
            ['計測テスト'],
            0.5,
        );

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(UserStateService::class)->captureDaily(str_repeat('s', 64), $state);
        $groups = $this->queryGroups(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $groups['user_state_snapshots'] ?? 0);
        $this->assertDatabaseCount('user_state_snapshots', 1);
        $this->assertDatabaseHas('user_state_snapshots', [
            'actor_token' => str_repeat('s', 64),
            'state' => UserBehaviorState::Normal->value,
        ]);
    }

    public function test_release_notes_use_file_cache_in_production_and_invalidate_on_change(): void
    {
        $this->app['env'] = 'production';
        Cache::store('file')->forget('canovia.release_notes.published.v1');

        $note = ReleaseNote::create([
            'version' => 'perf-test',
            'title' => 'Performance release',
            'summary' => 'cached',
            'highlights' => [],
            'published_at' => now()->subMinute(),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertTrue(ReleaseNotes::all()->contains('title', 'Performance release'));
        $this->assertTrue(ReleaseNotes::all()->contains('title', 'Performance release'));
        $groups = $this->queryGroups(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $groups['release_notes'] ?? 0);
        $this->assertSame(0, $groups['information_schema'] ?? 0);

        $note->update(['title' => 'Performance release updated']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertTrue(ReleaseNotes::all()->contains('title', 'Performance release updated'));
        $groups = $this->queryGroups(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $groups['release_notes'] ?? 0);

        Cache::store('file')->forget('canovia.release_notes.published.v1');
    }

    public function test_navigation_route_does_not_reload_work_sessions_for_each_candidate(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $plan = $this->planWithTasks(4, $user);

        WorkSession::create([
            'actor_token' => str_repeat('n', 64),
            'browser_session_id' => 'nav-session',
            'plan_id' => $plan->id,
            'task_id' => $plan->tasks->first()->id,
            'status' => 'completed',
            'started_at' => now()->subMinutes(20),
            'ended_at' => now()->subMinutes(10),
            'actual_seconds' => 600,
            'paused_seconds' => 0,
            'source' => 'navigation',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($user)
            ->withSession(['pace_keeper.actor_token' => str_repeat('n', 64)])
            ->get('/navigate');

        $groups = $this->queryGroups(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertSame(1, $groups['work_sessions'] ?? 0);
    }

    public function test_normal_navigation_gives_immediate_feedback_and_locks_duplicate_taps(): void
    {
        $source = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('let navigationLocked = false;', $source);
        $this->assertStringContainsString('if (navigationLocked)', $source);
        $this->assertStringContainsString("link.setAttribute('aria-busy', 'true')", $source);
        $this->assertStringContainsString('}, 120);', $source);
        $this->assertStringContainsString('resetNavigationLock();', $source);
    }

    private function planWithTasks(int $count, ?User $user = null): Plan
    {
        $plan = Plan::create([
            'user_id' => $user?->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Performance plan',
            'start_date' => today()->subDay(),
            'deadline' => today()->addDays(30),
            'is_public' => false,
        ]);

        foreach (range(1, $count) as $index) {
            Task::create([
                'plan_id' => $plan->id,
                'title' => 'Task '.$index,
                'estimated_minutes' => 30,
                'remaining_minutes' => 30,
                'progress_percent' => 0,
                'status' => 'todo',
                'priority' => $index,
                'activation_cost' => 3,
                'sort_order' => $index,
            ]);
        }

        return $plan->fresh(['tasks']);
    }

    /** @param array<int,array{query:string}> $queries */
    private function queryGroups(array $queries): array
    {
        $groups = [];

        foreach ($queries as $query) {
            preg_match('/(?:from|into|update) ["`]?([a-z_]+)/i', $query['query'], $matches);
            if (! isset($matches[1])) {
                continue;
            }

            $table = $matches[1];
            $groups[$table] = ($groups[$table] ?? 0) + 1;
        }

        return $groups;
    }
}
