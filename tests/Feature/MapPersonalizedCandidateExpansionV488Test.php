<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Enums\EvidenceSource;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\WorkLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MapPersonalizedCandidateExpansionV488Test extends TestCase
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

    public function test_recent_canonical_records_can_promote_existing_reflection_lens_without_active_plan(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '完了済みの重要Plan', 1);
        $done = $this->task($plan, '成果を残したTask', 'done', 100);

        $this->workLog($plan, $done, 0);
        $this->evidence($user, $plan, $done, 1);
        $this->workLog($plan, $done, 3);

        $response = $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('data-map-node-type="satellite_reflection"', false)
            ->assertSee('最近の実績')
            ->assertSee('最近実績が増えた');

        $graph = $response->viewData('graph');
        $shortcut = $graph['nodes']->firstWhere('id', 'satellite:reflection:recent');

        $this->assertIsArray($shortcut);
        $this->assertSame('satellite_reflection', $shortcut['type']);
        $this->assertSame('satellite-3', $shortcut['position_role']);
        $this->assertSame('intent:reflection', data_get(
            $graph['edges']->firstWhere('target', 'satellite:reflection:recent'),
            'source',
        ));
        $this->assertSame(
            route('map.index', [
                'level' => 'l2',
                'intent' => 'reflection',
                'reflection_context' => 'recent',
            ]),
            data_get($shortcut, 'direct_navigation.url'),
        );
        $this->assertContains(
            '最近実績が増えた',
            data_get($shortcut, 'personalization.reason_labels', []),
        );
        $this->assertStringContainsString(
            '最近の実績を振り返る近道',
            (string) data_get($shortcut, 'personalization.explanation'),
        );
    }

    public function test_one_low_signal_record_does_not_promote_reflection_shortcut(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '単発記録だけのPlan', 5);
        $done = $this->task($plan, '単発Task', 'done', 100);

        $this->workLog($plan, $done, 0);

        $graph = $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->viewData('graph');

        $this->assertNull(
            $graph['nodes']->firstWhere('id', 'satellite:reflection:recent')
        );
    }

    public function test_two_recent_records_still_need_to_cross_the_existing_promotion_threshold(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '低優先度で単日の実績だけあるPlan', 5);
        $done = $this->task($plan, '同日に記録したTask', 'done', 100);

        $this->workLog($plan, $done, 0);
        $this->evidence($user, $plan, $done, 0);

        $graph = $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->viewData('graph');

        $this->assertNull(
            $graph['nodes']->firstWhere('id', 'satellite:reflection:recent')
        );
    }

    public function test_plan_and_reflection_shortcuts_can_coexist_on_distinct_semantic_anchors(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $actorToken = Str::random(64);

        $activePlan = $this->plan($user, '継続中Plan', 1);
        $activeTask = $this->task($activePlan, '進行中Task', 'doing', 45);

        foreach ([0, 1, 2] as $daysAgo) {
            BehaviorEvent::query()->create([
                'actor_token' => $actorToken,
                'event_type' => BehaviorEventType::WorkStarted,
                'plan_id' => $activePlan->id,
                'task_id' => $activeTask->id,
                'session_id' => 'v488-active-'.$daysAgo,
                'occurred_at' => now()->subDays($daysAgo),
                'metadata' => [],
            ]);
        }

        $completedPlan = $this->plan($user, '振り返り対象Plan', 1);
        $done = $this->task($completedPlan, '完了Task', 'done', 100);
        $this->workLog($completedPlan, $done, 0);
        $this->evidence($user, $completedPlan, $done, 1);
        $this->workLog($completedPlan, $done, 2);

        $graph = $this
            ->withSession(['pace_keeper.actor_token' => $actorToken])
            ->actingAs($user)
            ->get(route('map.index'))
            ->viewData('graph');

        $shortcuts = $graph['nodes']
            ->filter(fn (array $node) => str_starts_with(
                (string) ($node['type'] ?? ''),
                'satellite_',
            ))
            ->values();

        $this->assertCount(2, $shortcuts);
        $this->assertNotNull($shortcuts->firstWhere('id', 'satellite:plan:'.$activePlan->id));
        $this->assertNotNull($shortcuts->firstWhere('id', 'satellite:reflection:recent'));
        $this->assertSame(
            ['satellite-1', 'satellite-3'],
            $shortcuts->pluck('position_role')->sort()->values()->all(),
        );
    }

    public function test_reflection_satellite_telemetry_accepts_only_structural_values(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $flowId = (string) Str::uuid();

        $this->actingAs($user)
            ->postJson(route('behavior_events.store'), [
                'event_type' => BehaviorEventType::MapClassicActionOpened->value,
                'metadata' => [
                    'flow_id' => $flowId,
                    'surface' => 'web',
                    'device' => 'desktop',
                    'node_type' => 'satellite_reflection',
                    'position_role' => 'satellite-3',
                    'action_role' => 'satellite',
                    'promotion_score' => 0.91,
                    'reflection_label' => '保存しない',
                ],
            ])
            ->assertNoContent();

        $event = BehaviorEvent::query()
            ->where('event_type', BehaviorEventType::MapClassicActionOpened->value)
            ->firstOrFail();

        $this->assertSame('satellite_reflection', data_get($event->metadata, 'node_type'));
        $this->assertSame('satellite-3', data_get($event->metadata, 'position_role'));
        $this->assertSame('satellite', data_get($event->metadata, 'action_role'));
        $this->assertArrayNotHasKey('promotion_score', $event->metadata);
        $this->assertArrayNotHasKey('reflection_label', $event->metadata);
    }

    private function plan(User $user, string $title, int $priority): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => '個人開発',
            'priority' => $priority,
            'priority_mode' => 'manual',
            'start_date' => today()->subMonth(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }

    private function task(
        Plan $plan,
        string $title,
        string $status,
        int $progress,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 60,
            'remaining_minutes' => $progress >= 100 ? 0 : 60,
            'progress_percent' => $progress,
            'status' => $status,
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }

    private function workLog(Plan $plan, Task $task, int $daysAgo): WorkLog
    {
        $log = WorkLog::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'task_title_snapshot' => $task->title,
            'worked_on' => today()->subDays($daysAgo),
            'actual_minutes' => 30,
            'progress_delta_percent' => 10,
            'progress_before_percent' => 80,
            'progress_after_percent' => 90,
            'remaining_minutes_before' => 30,
            'remaining_minutes_after' => 0,
            'memo' => 'V48.8 reflection candidate test',
            'outcome' => '成果を記録した',
        ]);

        $log->forceFill([
            'created_at' => now()->subDays($daysAgo),
            'updated_at' => now()->subDays($daysAgo),
        ])->save();

        return $log;
    }

    private function evidence(
        User $user,
        Plan $plan,
        Task $task,
        int $daysAgo,
    ): TaskEvidence {
        return TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'actor_token' => Str::random(64),
            'source' => EvidenceSource::Native,
            'type' => 'guided_execution_reflected',
            'external_key' => 'v488-'.$task->id.'-'.$daysAgo,
            'confidence' => 0.8,
            'occurred_at' => now()->subDays($daysAgo),
            'metadata' => [
                'intent' => '候補拡張を確認する',
                'actual_outcome' => 'Reflection candidate用Evidence',
            ],
        ]);
    }
}
