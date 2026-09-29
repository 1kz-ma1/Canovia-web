<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContextCenteredExecutionMapV476Test extends TestCase
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

    public function test_l3_centers_selected_plan_without_changing_primary_action_identity(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $response = $this->actingAs($user)->get(route('map.index', [
            'level' => 'l3',
            'intent' => 'execution',
            'plan' => $plan->id,
        ]));

        $response->assertOk();
        $graph = $response->viewData('graph');

        $this->assertSame('plan:'.$plan->id, $graph['center_node_id']);
        $this->assertSame('task:'.$task->id, $graph['primary_node_id']);
        $this->assertTrue($graph['has_primary_action']);

        $planNode = $graph['nodes']->firstWhere('id', 'plan:'.$plan->id);
        $taskNode = $graph['nodes']->firstWhere('id', 'task:'.$task->id);

        $this->assertSame('context-plan', $planNode['position_role']);
        $this->assertSame(['x' => 50, 'y' => 50], $planNode['position']);
        $this->assertSame('hub', $planNode['state']);

        $this->assertSame('action-primary', $taskNode['position_role']);
        $this->assertSame(['x' => 70, 'y' => 31], $taskNode['position']);
        $this->assertSame('primary', $taskNode['state']);
    }

    public function test_plan_remains_the_l3_center_even_when_no_primary_task_exists(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Taskなしでも残るPlan Context',
            'description' => 'Primary ActionがなくてもMap Contextは存在する',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $graph = $this->actingAs($user)
            ->get(route('map.index', [
                'level' => 'l3',
                'intent' => 'execution',
                'plan' => $plan->id,
            ]))
            ->assertOk()
            ->viewData('graph');

        $this->assertSame('plan:'.$plan->id, $graph['center_node_id']);
        $this->assertNull($graph['primary_node_id']);
        $this->assertFalse($graph['has_primary_action']);
    }

    public function test_l3_html_marks_plan_as_center_and_primary_task_as_focusable_action(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $html = $this->actingAs($user)
            ->get(route('map.index', [
                'level' => 'l3',
                'intent' => 'execution',
                'plan' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('中央は現在のPlan Contextです')
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<div[^>]*class="[^"]*is-map-center[^"]*"[^>]*data-map-node-id="plan:'.preg_quote((string) $plan->id, '/').'"[^>]*data-map-position-role="context-plan"[^>]*>/s',
            $html,
        );

        $this->assertMatchesRegularExpression(
            '/<div[^>]*data-map-node-id="task:'.preg_quote((string) $task->id, '/').'"[^>]*data-map-position-role="action-primary"[^>]*data-map-is-primary="1"[^>]*data-map-node-entry-mode="focus"[^>]*>/s',
            $html,
        );

        $this->assertStringContainsString('data-map-position-role="action-primary"', $html);
    }

    public function test_new_context_centered_roles_are_accepted_as_structural_telemetry(): void
    {
        [$user] = $this->scenario();

        foreach ([
            ['node_type' => 'plan', 'position_role' => 'context-plan', 'is_primary' => false],
            ['node_type' => 'task', 'position_role' => 'action-primary', 'is_primary' => true],
        ] as $index => $sample) {
            $flowId = (string) Str::uuid();

            $this->actingAs($user)
                ->postJson(route('behavior_events.store'), [
                    'event_type' => BehaviorEventType::MapNodeFocused->value,
                    'metadata' => [
                        'flow_id' => $flowId,
                        'surface' => 'web',
                        'device' => 'desktop',
                        'node_type' => $sample['node_type'],
                        'position_role' => $sample['position_role'],
                        'action_role' => 'secondary',
                        'is_primary' => $sample['is_primary'],
                        'elapsed_ms' => 300 + $index,
                        'step_count' => $index + 1,
                    ],
                ])
                ->assertNoContent();

            $event = BehaviorEvent::query()
                ->where('event_type', BehaviorEventType::MapNodeFocused->value)
                ->where('metadata->flow_id', $flowId)
                ->firstOrFail();

            $this->assertSame($sample['position_role'], data_get($event->metadata, 'position_role'));
        }
    }

    private function scenario(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Context Centered Executionを仕上げる',
            'description' => 'Plan中心からTask Focusへ進む',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Primary Actionを進める',
            'description' => 'Planの周囲に置く現在Task',
            'estimated_minutes' => 60,
            'remaining_minutes' => 45,
            'progress_percent' => 25,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
