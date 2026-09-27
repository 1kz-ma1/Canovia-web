<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\MapTelemetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanoviaMapTelemetryV424Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_map_renders_validation_hooks_and_client_events_store_only_whitelisted_metadata(): void
    {
        [$user] = $this->scenario();

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('data-event-url="'.route('behavior_events.store').'"', false)
            ->assertSee('data-map-home-fallback', false)
            ->assertSee('data-map-is-primary="1"', false)
            ->assertSee('data-map-action-role="primary"', false);

        $flowId = (string) Str::uuid();

        $this->actingAs($user)
            ->postJson(route('behavior_events.store'), [
                'event_type' => BehaviorEventType::MapNodeFocused->value,
                'metadata' => [
                    'flow_id' => $flowId,
                    'surface' => 'pwa',
                    'device' => 'mobile',
                    'node_type' => 'task',
                    'position_role' => 'now',
                    'is_primary' => true,
                    'elapsed_ms' => 1250,
                    'step_count' => 1,
                    'private_text' => 'never store this',
                ],
            ])
            ->assertNoContent();

        $event = BehaviorEvent::query()
            ->where('event_type', BehaviorEventType::MapNodeFocused->value)
            ->firstOrFail();

        $this->assertSame($flowId, data_get($event->metadata, 'flow_id'));
        $this->assertSame('task', data_get($event->metadata, 'node_type'));
        $this->assertTrue((bool) data_get($event->metadata, 'is_primary'));
        $this->assertArrayNotHasKey('private_text', $event->metadata);
    }

    public function test_work_start_with_map_flow_records_server_authoritative_execution_event(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $flowId = (string) Str::uuid();

        $this->actingAs($user)
            ->post(route('work_sessions.start'), [
                'task_id' => $task->id,
                'intended_minutes' => 25,
                'source' => 'plan',
                'map_flow_id' => $flowId,
                'map_flow_elapsed_ms' => 9200,
                'map_flow_step_count' => 3,
            ])
            ->assertRedirect();

        $event = BehaviorEvent::query()
            ->where('event_type', BehaviorEventType::MapExecutionStarted->value)
            ->firstOrFail();

        $this->assertSame($plan->id, $event->plan_id);
        $this->assertSame($task->id, $event->task_id);
        $this->assertSame($flowId, data_get($event->metadata, 'flow_id'));
        $this->assertSame(9200, data_get($event->metadata, 'elapsed_ms'));
        $this->assertSame(3, data_get($event->metadata, 'step_count'));
    }

    public function test_map_telemetry_summary_aggregates_by_flow_and_exposes_home_reference_latency(): void
    {
        $flowA = (string) Str::uuid();
        $flowB = (string) Str::uuid();
        $actor = Str::random(64);

        foreach ([
            [BehaviorEventType::MapViewed, $flowA, []],
            [BehaviorEventType::MapNodeFocused, $flowA, ['is_primary' => true, 'elapsed_ms' => 2000, 'step_count' => 1]],
            [BehaviorEventType::MapClassicActionOpened, $flowA, ['elapsed_ms' => 3000, 'step_count' => 2]],
            [BehaviorEventType::MapExecutionStarted, $flowA, ['elapsed_ms' => 10000, 'step_count' => 3]],
            [BehaviorEventType::MapViewed, $flowB, []],
            [BehaviorEventType::MapNodeFocused, $flowB, ['is_primary' => false, 'elapsed_ms' => 4000, 'step_count' => 1]],
            [BehaviorEventType::MapClassicHomeOpened, $flowB, ['elapsed_ms' => 5000, 'step_count' => 2]],
            [BehaviorEventType::MapBackUsed, $flowB, ['elapsed_ms' => 4500, 'step_count' => 2]],
        ] as [$type, $flow, $extra]) {
            BehaviorEvent::query()->create([
                'actor_token' => $actor,
                'event_type' => $type,
                'session_id' => 'map-telemetry',
                'occurred_at' => now(),
                'metadata' => ['flow_id' => $flow, ...$extra],
            ]);
        }

        BehaviorEvent::query()->create([
            'actor_token' => $actor,
            'event_type' => BehaviorEventType::WorkStarted,
            'session_id' => 'home-start',
            'occurred_at' => now(),
            'metadata' => [
                'source' => 'dashboard',
                'start_latency_seconds' => 8,
            ],
        ]);

        $summary = app(MapTelemetryService::class)->summary(30);

        $this->assertSame(2, $summary['views']);
        $this->assertSame(100.0, $summary['focus_rate']);
        $this->assertSame(50.0, $summary['classic_action_rate']);
        $this->assertSame(50.0, $summary['home_fallback_rate']);
        $this->assertSame(50.0, $summary['execution_rate']);
        $this->assertSame(2000, $summary['median_primary_focus_ms']);
        $this->assertSame(10000, $summary['median_execution_ms']);
        $this->assertSame(3, $summary['median_execution_steps']);
        $this->assertSame(0.5, $summary['back_per_flow']);
        $this->assertSame(8000, $summary['home_median_start_latency_ms']);
    }

    public function test_admin_dashboard_exposes_map_validation_summary_without_auto_promotion_verdict(): void
    {
        $admin = User::factory()->create(['first_run_completed_at' => now()]);
        config(['canovia.super_admin_user_id' => $admin->id]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('LIVING MAP VALIDATION')
            ->assertSee('MapをPrimary Home候補として検証')
            ->assertSee('Primary Homeへの昇格は自動化しません');
    }

    private function scenario(): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Map Telemetryを検証する',
            'description' => 'Primary Home判断の材料を集める',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Map導線を計測する',
            'description' => 'Focusから実行開始までを見る',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
