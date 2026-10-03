<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\Task;
use App\Models\WorkLog;
use App\Models\WorkSession;
use App\Services\UserBehaviorService;
use App\Services\UserStateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BehaviorTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_owned_plan_tab_event_is_persisted_once_within_the_deduplication_window(): void
    {
        $plan = $this->createPlan('Plan A');
        $this->withSession(['pace_keeper.actor_token' => Str::random(64)]);
        $this->withCookie((string) config('session.cookie'), $this->app['session']->getId());
        $payload = [
            'event_type' => BehaviorEventType::PlanTabViewed->value,
            'plan_id' => $plan->id,
            'metadata' => ['plan_switches' => 1],
        ];

        $this->withCredentials()
            ->withCookie($this->ownerCookie($plan), $plan->owner_token)
            ->postJson(route('behavior_events.store'), $payload)
            ->assertNoContent();
        $this->withCookie($this->ownerCookie($plan), $plan->owner_token)
            ->postJson(route('behavior_events.store'), $payload)
            ->assertNoContent();

        $this->assertDatabaseCount('behavior_events', 1);
        $this->assertDatabaseHas('behavior_events', [
            'event_type' => BehaviorEventType::PlanTabViewed->value,
            'plan_id' => $plan->id,
        ]);
    }

    public function test_client_event_cannot_reference_a_plan_the_browser_does_not_own(): void
    {
        $ownedPlan = $this->createPlan('Owned');
        $foreignPlan = $this->createPlan('Foreign');
        $foreignTask = $this->createTask($foreignPlan, 'Foreign task');

        $this->withCredentials()
            ->withCookie($this->ownerCookie($ownedPlan), $ownedPlan->owner_token)
            ->postJson(route('behavior_events.store'), [
                'event_type' => BehaviorEventType::TaskViewed->value,
                'plan_id' => $foreignPlan->id,
                'task_id' => $foreignTask->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('behavior_events', 0);
    }

    public function test_work_session_start_records_the_observable_start_events(): void
    {
        $plan = $this->createPlan('Plan A');
        $task = $this->createTask($plan, 'Start me');

        $response = $this->withCookie($this->ownerCookie($plan), $plan->owner_token)
            ->post(route('work_sessions.start'), [
                'task_id' => $task->id,
                'intended_minutes' => 15,
                'source' => 'dashboard',
            ]);

        $session = \App\Models\WorkSession::firstOrFail();
        $response->assertRedirect(route('work_sessions.active', $session));
        $this->assertDatabaseHas('work_sessions', [
            'task_id' => $task->id,
            'status' => 'active',
            'intended_minutes' => 15,
        ]);
        $this->assertDatabaseHas('behavior_events', ['event_type' => BehaviorEventType::TaskStarted->value, 'task_id' => $task->id]);
        $this->assertDatabaseHas('behavior_events', ['event_type' => BehaviorEventType::WorkStarted->value, 'task_id' => $task->id]);
        $this->assertDatabaseHas('behavior_events', ['event_type' => BehaviorEventType::RecommendationAccepted->value, 'task_id' => $task->id]);
        $this->assertSame('todo', $task->fresh()->status, 'WorkSession開始だけでTaskを固定の現在Taskにしない');
    }


    public function test_pause_time_is_excluded_and_completed_session_hands_off_to_shared_plan_update_flow(): void
    {
        Carbon::setTestNow('2026-09-04 10:00:00');
        $plan = $this->createPlan('Pause plan');
        $task = $this->createTask($plan, 'Pause task');

        $this->withCookie($this->ownerCookie($plan), $plan->owner_token)
            ->post(route('work_sessions.start'), [
                'task_id' => $task->id,
                'intended_minutes' => 30,
                'source' => 'dashboard',
            ]);

        $session = WorkSession::firstOrFail();
        Carbon::setTestNow('2026-09-04 10:05:00');
        $this->withCookie($this->ownerCookie($plan), $plan->owner_token)
            ->post(route('work_sessions.pause', $session));

        Carbon::setTestNow('2026-09-04 10:15:00');
        $this->withCookie($this->ownerCookie($plan), $plan->owner_token)
            ->post(route('work_sessions.resume', $session));

        Carbon::setTestNow('2026-09-04 10:20:00');
        $this->withCookie($this->ownerCookie($plan), $plan->owner_token)
            ->post(route('work_sessions.complete', $session))
            ->assertRedirect(route('plans.review_assistant.show', [
                'plan' => $plan,
                'work_session_id' => $session->id,
            ]));

        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame(600, $session->actual_seconds);
        $this->assertSame('todo', $task->fresh()->status, 'タイマー終了だけではTask進捗を変更しない');
        $this->assertDatabaseHas('work_logs', [
            'work_session_id' => $session->id,
            'actual_minutes' => 10,
            'outcome' => '作業セッションを終了',
        ]);
        $this->assertDatabaseHas('task_evidences', [
            'task_id' => $task->id,
            'source' => 'native',
            'type' => 'focus_session_completed',
            'external_key' => 'work-session:'.$session->id.':completed',
        ]);
        $this->assertSame(0, $task->fresh()->progress_percent, 'Focus Timer EvidenceだけでTask進捗を変更しない');

        Carbon::setTestNow();
    }

    public function test_completed_work_session_prompt_reuses_saved_facts_without_four_choice_review(): void
    {
        Carbon::setTestNow('2026-09-04 13:00:00');
        $plan = $this->createPlan('AI handoff plan');
        $task = $this->createTask($plan, 'Continue through AI');
        $actorToken = Str::random(64);

        $this->withSession(['pace_keeper.actor_token' => $actorToken])
            ->withCookie($this->ownerCookie($plan), $plan->owner_token)
            ->post(route('work_sessions.start'), [
                'task_id' => $task->id,
                'intended_minutes' => 30,
                'source' => 'dashboard',
            ]);
        $session = WorkSession::firstOrFail();

        Carbon::setTestNow('2026-09-04 13:30:00');
        $this->withSession(['pace_keeper.actor_token' => $actorToken])
            ->withCookie($this->ownerCookie($plan), $plan->owner_token)
            ->post(route('work_sessions.complete', $session));

        $response = $this->withCookie($this->ownerCookie($plan), $plan->owner_token)
            ->post(route('plans.review_assistant.prompt', $plan), [
                'flow' => 'result_recording',
                'work_session_id' => $session->id,
                'activity_summary' => '',
            ]);

        $response->assertRedirect(route('plans.review_assistant.show', $plan));
        $draft = session('plan_review_drafts.' . $plan->id);
        $this->assertSame('work_session', $draft['source_context']);
        $this->assertSame($session->id, $draft['work_session_id']);
        $this->assertSame($task->id, $draft['task_id']);
        $this->assertSame(30, $draft['actual_minutes']);
        $this->assertStringContainsString('WorkLog保存済み: はい', $draft['prompt']);
        $this->assertStringContainsString('同じ作業をrecord_resultで再登録してはいけない', $draft['prompt']);
        $this->assertStringContainsString('自由形式の質問を原則1回だけ', $draft['prompt']);
        $this->assertSame('todo', $task->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_manual_plan_update_prompt_can_be_generated_without_structured_input(): void
    {
        $plan = $this->createPlan('Minimal input plan');
        $this->createTask($plan, 'Existing task');

        $this->withCookie($this->ownerCookie($plan), $plan->owner_token)
            ->post(route('plans.review_assistant.prompt', $plan), [
                'flow' => 'result_recording',
                'activity_summary' => '',
            ])
            ->assertRedirect(route('plans.review_assistant.show', $plan));

        $draft = session('plan_review_drafts.' . $plan->id);
        $this->assertSame('manual', $draft['source_context']);
        $this->assertNull($draft['actual_minutes']);
        $this->assertNull($draft['task_id']);
        $this->assertStringContainsString('今回の報告はまだ入力されていません', $draft['prompt']);
        $this->assertStringContainsString('今回この計画について何がありましたか？', $draft['prompt']);
    }

    public function test_state_calculation_has_safe_fallbacks_without_behavior_data(): void
    {
        $actorToken = Str::random(64);
        $baseline = app(UserBehaviorService::class)->baseline($actorToken);
        $state = app(UserStateService::class)->calculate($actorToken, $baseline);

        $this->assertSame(0, $baseline->sampleCount);
        $this->assertSame(180, $baseline->startLatencySeconds);
        $this->assertSame(25, $baseline->medianWorkMinutes);
        $this->assertGreaterThanOrEqual(0, $state->actionReadiness);
        $this->assertLessThanOrEqual(100, $state->actionReadiness);
        $this->assertGreaterThanOrEqual(0, $state->decisionLoad);
        $this->assertLessThanOrEqual(100, $state->decisionLoad);
    }

    public function test_state_calculation_handles_one_behavior_event_without_a_work_session(): void
    {
        $actorToken = Str::random(64);

        BehaviorEvent::create([
            'actor_token' => $actorToken,
            'event_type' => BehaviorEventType::WorkStarted,
            'session_id' => 'single-event-session',
            'occurred_at' => now(),
            'metadata' => ['start_latency_seconds' => 90],
        ]);

        $baseline = app(UserBehaviorService::class)->baseline($actorToken);
        $state = app(UserStateService::class)->calculate($actorToken, $baseline);

        $this->assertSame(1, $baseline->sampleCount);
        $this->assertSame(90, $baseline->startLatencySeconds);
        $this->assertGreaterThan(0, $state->consistency);
        $this->assertGreaterThan(0, $state->confidence);
        $this->assertDatabaseCount('work_sessions', 0);
    }

    public function test_dashboard_renders_without_behavior_events_or_work_sessions(): void
    {
        $actorToken = Str::random(64);
        $plan = $this->createPlan('No behavior yet');
        $task = $this->createTask($plan, 'First task');

        WorkLog::create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'task_title_snapshot' => $task->title,
            'worked_on' => today()->subDay(),
            'actual_minutes' => 15,
            'progress_delta_percent' => 0,
        ]);

        $baseline = app(UserBehaviorService::class)->baseline($actorToken);
        $state = app(UserStateService::class)->calculate(
            $actorToken,
            $baseline,
            Plan::with(['tasks', 'workLogs'])->get(),
        );

        $this->assertDatabaseCount('behavior_events', 0);
        $this->assertDatabaseCount('work_sessions', 0);
        $this->assertGreaterThan(0, $state->consistency);

        $this->withSession(['pace_keeper.actor_token' => $actorToken])
            ->withCookie($this->ownerCookie($plan), $plan->owner_token)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('次の行動を決める');

        $this->assertDatabaseHas('user_state_snapshots', ['actor_token' => $actorToken]);
    }

    public function test_multiple_behavior_events_and_work_log_dates_use_base_collections_for_state_aggregation(): void
    {
        $actorToken = Str::random(64);
        $plan = $this->createPlan('Collection boundary');
        $task = $this->createTask($plan, 'Aggregate safely');

        foreach ([
            [BehaviorEventType::WorkStarted, now()->subDays(2), ['start_latency_seconds' => 120]],
            [BehaviorEventType::WorkStarted, now()->subDay(), ['start_latency_seconds' => 240]],
            [BehaviorEventType::PlanTabViewed, now()->subMinutes(10), ['plan_switches' => 2]],
        ] as [$type, $occurredAt, $metadata]) {
            BehaviorEvent::create([
                'actor_token' => $actorToken,
                'event_type' => $type,
                'plan_id' => $plan->id,
                'task_id' => $type === BehaviorEventType::WorkStarted ? $task->id : null,
                'session_id' => 'collection-boundary-session',
                'occurred_at' => $occurredAt,
                'metadata' => $metadata,
            ]);
        }

        WorkLog::create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'task_title_snapshot' => $task->title,
            'worked_on' => today()->subDays(3),
            'actual_minutes' => 20,
            'progress_delta_percent' => 0,
        ]);

        $baseline = app(UserBehaviorService::class)->baseline($actorToken);
        $state = app(UserStateService::class)->calculate(
            $actorToken,
            $baseline,
            Plan::with(['tasks', 'workLogs'])->get(),
        );

        $this->assertSame(180, $baseline->startLatencySeconds);
        $this->assertGreaterThan(0, $baseline->sampleCount);
        $this->assertGreaterThan(0, $state->consistency);
        $this->assertDatabaseCount('work_sessions', 0);

        $this->withSession(['pace_keeper.actor_token' => $actorToken])
            ->withCookie($this->ownerCookie($plan), $plan->owner_token)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Collection boundary');

        $this->withSession(['pace_keeper.actor_token' => $actorToken])
            ->withCookie($this->ownerCookie($plan), $plan->owner_token)
            ->get(route('navigation.index'))
            ->assertOk()
            ->assertSee('今日やること');
    }

    public function test_navigation_opens_with_an_immediate_recommendation_and_start_action(): void
    {
        $plan = $this->createPlan('Immediate plan');
        $task = $this->createTask($plan, 'Start immediately');

        $this->withCookie($this->ownerCookie($plan), $plan->owner_token)
            ->get(route('navigation.index'))
            ->assertOk()
            ->assertSee('おすすめ')
            ->assertSee($task->title)
            ->assertSee('このまま開始')
            ->assertDontSee('条件を変える')
            ->assertDontSee('今日はどう進めたい？');

        $this->assertDatabaseHas('behavior_events', [
            'event_type' => BehaviorEventType::RecommendationShown->value,
            'task_id' => $task->id,
        ]);
    }

    public function test_legacy_navigation_configuration_query_no_longer_opens_a_secondary_flow(): void
    {
        $plan = $this->createPlan('Config plan');
        $this->createTask($plan, 'Config task');

        $this->withCookie($this->ownerCookie($plan), $plan->owner_token)
            ->get(route('navigation.index', ['configure' => 1]))
            ->assertOk()
            ->assertDontSee('条件を変える')
            ->assertDontSee('今日はどう進めたい？');
    }

    private function createPlan(string $title): Plan
    {
        return Plan::create([
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'start_date' => today()->subDays(2),
            'deadline' => today()->addDays(10),
            'is_public' => false,
        ]);
    }

    private function createTask(Plan $plan, string $title): Task
    {
        return Task::create([
            'plan_id' => $plan->id,
            'title' => $title,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 3,
            'activation_cost' => 3,
            'sort_order' => 1,
        ]);
    }

    private function ownerCookie(Plan $plan): string
    {
        return 'pace_keeper_owner_token_' . $plan->id;
    }
}
