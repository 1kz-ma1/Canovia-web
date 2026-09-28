<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\User;
use App\Services\IntentNavigationGraphService;
use App\Services\PersonalizedSatellitePromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalizedSatellitesV444Test extends TestCase
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

    public function test_satellites_promote_existing_contexts_without_changing_fixed_l0_semantic_graph(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $actorToken = Str::random(64);

        $plans = collect([
            $this->plan($user, 'Canovia', '個人開発', 1),
            $this->plan($user, 'AP対策', '資格学習', 2),
            $this->plan($user, 'SNS運用', '制作活動', 3),
            $this->plan($user, '共同制作', '個人開発', 4, collaborative: true),
            $this->plan($user, '低優先Plan', '未分類', 5),
        ]);

        foreach ($plans as $index => $plan) {
            $task = $this->task($plan, '継続Task '.$index, priority: 1);

            foreach ([1, 3] as $daysAgo) {
                BehaviorEvent::query()->create([
                    'actor_token' => $actorToken,
                    'event_type' => BehaviorEventType::WorkStarted,
                    'plan_id' => $plan->id,
                    'task_id' => $task->id,
                    'session_id' => 'satellite-'.$plan->id.'-'.$daysAgo,
                    'occurred_at' => now()->subDays($daysAgo),
                    'metadata' => [],
                ]);
            }
        }

        $response = $this
            ->withSession(['pace_keeper.actor_token' => $actorToken])
            ->actingAs($user)
            ->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('data-map-node-id="intent:space-station"', false)
            ->assertSee('data-map-node-id="intent:plan"', false)
            ->assertSee('data-map-node-id="intent:execution"', false)
            ->assertSee('data-map-node-id="intent:reflection"', false)
            ->assertSee('data-map-node-id="intent:collaboration"', false)
            ->assertSee('is-personalized-satellite', false);

        $graph = $response->viewData('graph');
        $satellites = $graph['nodes']
            ->filter(fn (array $node) => str_starts_with((string) ($node['type'] ?? ''), 'satellite_'))
            ->values();

        $this->assertCount(4, $satellites);
        $this->assertSame(4, data_get($graph, 'personalized_satellites.count'));
        $this->assertSame('intent:space-station', $graph['center_node_id']);
        $this->assertNull($graph['primary_node_id']);
        $this->assertSame(
            ['satellite-1', 'satellite-2', 'satellite-3', 'satellite-4'],
            $satellites->pluck('position_role')->all(),
        );

        $semantic = app(IntentNavigationGraphService::class)->build();
        $this->assertCount(5, $semantic['nodes']);
        $this->assertFalse($semantic['nodes']->contains(
            fn (array $node) => str_starts_with((string) ($node['type'] ?? ''), 'satellite_')
        ));
    }

    public function test_promotion_score_uses_only_the_four_declared_axes_and_caps_at_four(): void
    {
        $promotion = app(PersonalizedSatellitePromotionService::class);

        $this->assertSame(0.35, $promotion->score([
            'importance' => 1,
            'usage_frequency' => 0,
            'recency' => 0,
            'continuity' => 0,
            'ignored_signal' => 1,
        ]));

        $this->assertSame(0.50, $promotion->score([
            'importance' => 0,
            'usage_frequency' => 0.4,
            'recency' => 1,
            'continuity' => 1,
        ]));

        $candidates = collect(range(1, 6))->map(fn (int $rank) => [
            'id' => 'candidate:'.$rank,
            'kind' => 'plan',
            'node_type' => 'satellite_plan',
            'entity_id' => $rank,
            'eyebrow' => 'PLAN SATELLITE',
            'label' => 'Plan '.$rank,
            'subtitle' => 'shortcut',
            'available_action' => '/map?plan='.$rank,
            'anchor_node_id' => 'intent:plan',
            'signals' => [
                'importance' => 1 - (($rank - 1) * 0.08),
                'usage_frequency' => 1,
                'recency' => 1,
                'continuity' => 1,
            ],
            'classic_surface' => [
                'actions' => [[
                    'label' => '開く',
                    'url' => '/map?plan='.$rank,
                    'primary' => true,
                    'navigation_kind' => 'satellite',
                ]],
            ],
        ]);

        $result = $promotion->promote($candidates, 4);

        $this->assertCount(4, $result['nodes']);
        $this->assertCount(4, $result['edges']);
        $this->assertSame('candidate:1', data_get($result, 'nodes.0.id'));
        $this->assertSame(['x' => 50, 'y' => 12], data_get($result, 'nodes.0.position'));
        $this->assertSame(['x' => 12, 'y' => 50], data_get($result, 'nodes.3.position'));
    }

    public function test_repeated_ai_practice_can_be_promoted_as_a_tool_satellite(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $actorToken = Str::random(64);
        $plan = $this->plan($user, '応用情報に合格する', '資格学習', 1);
        $task = $this->task($plan, 'AP過去問演習を進める', priority: 1);

        foreach ([1, 2, 4] as $index => $daysAgo) {
            StudyPracticeAttempt::query()->create([
                'plan_id' => $plan->id,
                'task_id' => $task->id,
                'user_id' => $user->id,
                'actor_token' => $actorToken,
                'request_hash' => hash('sha256', 'satellite-practice-'.$index),
                'exercise_title' => 'AP演習',
                'questions' => [],
                'answers' => [],
                'assessment' => [],
                'score_percent' => 80,
                'recommended_task_progress_percent' => 20,
                'created_at' => now()->subDays($daysAgo),
                'updated_at' => now()->subDays($daysAgo),
            ]);
        }

        $response = $this
            ->withSession(['pace_keeper.actor_token' => $actorToken])
            ->actingAs($user)
            ->get(route('map.index'));

        $response->assertOk();

        $graph = $response->viewData('graph');
        $tool = $graph['nodes']->first(
            fn (array $node) => ($node['type'] ?? null) === 'satellite_tool'
        );

        $this->assertIsArray($tool);
        $this->assertSame('AI演習', $tool['label']);
        $this->assertSame('satellite', data_get($tool, 'direct_navigation.kind'));
        $this->assertStringContainsString(
            route('plans.tasks.study_practice.show', [$plan, $task]),
            (string) data_get($tool, 'direct_navigation.url'),
        );
        $this->assertGreaterThan(0, data_get($tool, 'promotion_signals.usage_frequency'));
        $this->assertGreaterThan(0, data_get($tool, 'promotion_signals.continuity'));
    }

    public function test_shared_plan_satellite_anchors_to_collaboration_intent(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $actorToken = Str::random(64);
        $plan = $this->plan($user, '共同卒制', '制作活動', 1, collaborative: true);
        $task = $this->task($plan, '共同作業を進める', priority: 1);

        BehaviorEvent::query()->create([
            'actor_token' => $actorToken,
            'event_type' => BehaviorEventType::WorkStarted,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'session_id' => 'shared-satellite',
            'occurred_at' => now(),
            'metadata' => [],
        ]);

        $graph = $this
            ->withSession(['pace_keeper.actor_token' => $actorToken])
            ->actingAs($user)
            ->get(route('map.index'))
            ->viewData('graph');

        $satellite = $graph['nodes']->firstWhere('id', 'satellite:plan:'.$plan->id);
        $edge = $graph['edges']->firstWhere('target', 'satellite:plan:'.$plan->id);

        $this->assertIsArray($satellite);
        $this->assertSame('SHARED SATELLITE', $satellite['eyebrow']);
        $this->assertSame('intent:collaboration', $edge['source']);
        $this->assertSame('personalized_shortcut', $edge['relation']);
    }

    public function test_satellite_telemetry_keeps_only_structural_values(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $flowId = (string) Str::uuid();

        $this->actingAs($user)
            ->postJson(route('behavior_events.store'), [
                'event_type' => BehaviorEventType::MapClassicActionOpened->value,
                'metadata' => [
                    'flow_id' => $flowId,
                    'surface' => 'web',
                    'device' => 'desktop',
                    'node_type' => 'satellite_plan',
                    'position_role' => 'satellite-1',
                    'action_role' => 'satellite',
                    'is_primary' => false,
                    'promotion_score' => 0.92,
                    'plan_title' => '保存しないPlan名',
                ],
            ])
            ->assertNoContent();

        $event = BehaviorEvent::query()
            ->where('event_type', BehaviorEventType::MapClassicActionOpened->value)
            ->firstOrFail();

        $this->assertSame('satellite_plan', data_get($event->metadata, 'node_type'));
        $this->assertSame('satellite-1', data_get($event->metadata, 'position_role'));
        $this->assertSame('satellite', data_get($event->metadata, 'action_role'));
        $this->assertArrayNotHasKey('promotion_score', $event->metadata);
        $this->assertArrayNotHasKey('plan_title', $event->metadata);
    }

    private function plan(
        User $user,
        string $title,
        string $category,
        int $priority,
        bool $collaborative = false,
    ): Plan {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title.'の説明',
            'category' => $category,
            'priority' => $priority,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => $collaborative,
        ]);
    }

    private function task(Plan $plan, string $title, int $priority): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 15,
            'status' => 'doing',
            'priority' => $priority,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }
}
