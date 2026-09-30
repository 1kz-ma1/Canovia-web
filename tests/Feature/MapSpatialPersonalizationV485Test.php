<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\IntentNavigationGraphService;
use App\Services\PersonalizedSatellitePromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MapSpatialPersonalizationV485Test extends TestCase
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

    public function test_personalized_shortcuts_use_anchor_aware_score_sensitive_placement_with_explanation(): void
    {
        $promotion = app(PersonalizedSatellitePromotionService::class);

        $result = $promotion->promote(collect([
            $this->candidate('shortcut:plan', 'intent:plan'),
            $this->candidate('shortcut:execution', 'intent:execution'),
        ]), 2);

        $plan = $result['nodes']->firstWhere('id', 'shortcut:plan');
        $execution = $result['nodes']->firstWhere('id', 'shortcut:execution');

        $this->assertIsArray($plan);
        $this->assertIsArray($execution);

        $this->assertSame('satellite-1', $plan['position_role']);
        $this->assertSame(50, data_get($plan, 'position.x'));
        $this->assertSame(18.0, (float) data_get($plan, 'position.y'));

        $this->assertSame('satellite-2', $execution['position_role']);
        $this->assertSame(82.0, (float) data_get($execution, 'position.x'));
        $this->assertSame(50, data_get($execution, 'position.y'));

        $this->assertSame('behavioral_attention', data_get($plan, 'personalization.mode'));
        $this->assertSame('strong', data_get($plan, 'personalization.strength'));
        $this->assertContains(
            '優先度が高い',
            data_get($plan, 'personalization.reason_labels', []),
        );
        $this->assertContains(
            '最近よく使っている',
            data_get($plan, 'personalization.reason_labels', []),
        );
        $this->assertStringContainsString(
            '近道として表示しています',
            (string) data_get($plan, 'personalization.explanation'),
        );

        $semantic = app(IntentNavigationGraphService::class)->build();
        $this->assertCount(5, $semantic['nodes']);
        $this->assertFalse($semantic['nodes']->contains(
            fn (array $node) => str_starts_with((string) ($node['type'] ?? ''), 'satellite_')
        ));
    }

    public function test_map_renders_personalization_reason_and_local_hide_without_mutating_canonical_plan_or_task(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $actorToken = Str::random(64);

        $plan = $this->plan($user, 'Canoviaを改善する', 1);
        $task = $this->task($plan, 'Mapを改善する');

        BehaviorEvent::query()->create([
            'actor_token' => $actorToken,
            'event_type' => BehaviorEventType::WorkStarted,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'session_id' => 'v485-personalization',
            'occurred_at' => now(),
            'metadata' => [],
        ]);

        $beforePlan = $plan->only(['priority', 'title', 'deadline']);
        $beforeTask = $task->only([
            'status',
            'progress_percent',
            'remaining_minutes',
            'priority',
        ]);

        $response = $this
            ->withSession(['pace_keeper.actor_token' => $actorToken])
            ->actingAs($user)
            ->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('data-map-personalized-node', false)
            ->assertSee('data-map-personalization-strength=', false)
            ->assertSee('PERSONALIZED')
            ->assertSee('ここにある理由')
            ->assertSee('よく使うContextへの近道')
            ->assertSee('この端末では非表示')
            ->assertSee('data-map-personalization-hidden-status', false)
            ->assertSee('data-map-personalization-reset', false);

        $plan->refresh();
        $task->refresh();

        $this->assertSame($beforePlan, $plan->only(['priority', 'title', 'deadline']));
        $this->assertSame($beforeTask, $task->only([
            'status',
            'progress_percent',
            'remaining_minutes',
            'priority',
        ]));
    }

    public function test_weak_signals_do_not_invent_personalized_shortcuts_or_reasons(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = $this->plan($user, 'まだ使っていないPlan', 5);
        $task = $this->task($plan, '未着手Task', status: 'todo', progress: 0);

        $response = $this
            ->actingAs($user)
            ->get(route('map.index'));

        $response->assertOk();

        $graph = $response->viewData('graph');
        $shortcut = $graph['nodes']->firstWhere('id', 'satellite:plan:'.$plan->id);

        $this->assertNull($shortcut);
        $this->assertSame('todo', $task->fresh()->status);
        $this->assertSame(0, (int) $task->fresh()->progress_percent);
    }

    private function candidate(string $id, string $anchor): array
    {
        return [
            'id' => $id,
            'kind' => 'plan',
            'node_type' => 'satellite_plan',
            'entity_id' => 1,
            'eyebrow' => 'PLAN',
            'label' => 'Plan',
            'subtitle' => 'shortcut',
            'available_action' => '/map?level=l3&plan=1',
            'anchor_node_id' => $anchor,
            'signals' => [
                'importance' => 1.0,
                'usage_frequency' => 1.0,
                'recency' => 1.0,
                'continuity' => 1.0,
            ],
            'classic_surface' => [
                'actions' => [[
                    'label' => '開く',
                    'url' => '/map?level=l3&plan=1',
                    'primary' => true,
                    'navigation_kind' => 'satellite',
                ]],
            ],
        ];
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
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }

    private function task(
        Plan $plan,
        string $title,
        string $status = 'doing',
        int $progress = 15,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 60,
            'remaining_minutes' => 45,
            'progress_percent' => $progress,
            'status' => $status,
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }
}
