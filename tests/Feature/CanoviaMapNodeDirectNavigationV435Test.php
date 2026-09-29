<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Enums\EvidenceSource;
use App\Models\BehaviorEvent;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanoviaMapNodeDirectNavigationV435Test extends TestCase
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

    public function test_direct_navigation_remains_available_while_execution_tasks_stay_focus_first(): void
    {
        [$user, $plan, $current, $next] = $this->scenario();

        $response = $this->actingAs($user)->get(route('map.index', ['level' => 'l3']));
        $response->assertOk()
            ->assertSee('data-map-node-focus', false)
            ->assertSee('data-map-direct-navigation', false)
            ->assertSee('詳細を見る →')
            ->assertSee('開く ↗');

        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/data-map-direct-navigation[^>]*data-map-node-id="plan:'.preg_quote((string) $plan->id, '/').'"/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/data-map-direct-navigation[^>]*data-map-node-id="evidence:[0-9]+"/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/data-map-direct-navigation[^>]*data-map-node-id="inbox:pending"/s',
            $html,
        );

        $this->assertDoesNotMatchRegularExpression(
            '/data-map-direct-navigation[^>]*data-map-node-id="task:'.preg_quote((string) $current->id, '/').'"/s',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-map-direct-navigation[^>]*data-map-node-id="task:'.preg_quote((string) $next->id, '/').'"/s',
            $html,
        );
    }

    public function test_direct_action_role_is_whitelisted_without_storing_user_content(): void
    {
        [$user] = $this->scenario();
        $flowId = (string) Str::uuid();

        $this->actingAs($user)
            ->postJson(route('behavior_events.store'), [
                'event_type' => BehaviorEventType::MapClassicActionOpened->value,
                'metadata' => [
                    'flow_id' => $flowId,
                    'surface' => 'web',
                    'device' => 'desktop',
                    'node_type' => 'plan',
                    'position_role' => 'future-plan',
                    'action_role' => 'direct',
                    'is_primary' => false,
                    'elapsed_ms' => 1800,
                    'step_count' => 1,
                    'private_text' => 'do not persist',
                ],
            ])
            ->assertNoContent();

        $event = BehaviorEvent::query()
            ->where('event_type', BehaviorEventType::MapClassicActionOpened->value)
            ->firstOrFail();

        $this->assertSame('direct', data_get($event->metadata, 'action_role'));
        $this->assertSame('plan', data_get($event->metadata, 'node_type'));
        $this->assertArrayNotHasKey('private_text', $event->metadata);
    }

    public function test_instant_navigation_skips_node_focus_links_but_keeps_explicit_direct_links_eligible(): void
    {
        $instant = file_get_contents(resource_path('js/instant-navigation.mjs'));
        $living = file_get_contents(resource_path('js/living-map.mjs'));

        $this->assertStringContainsString("link.hasAttribute('data-map-node-focus')", $instant);
        $this->assertStringContainsString("event.target.closest?.('[data-map-direct-open]')", $living);
        $this->assertStringContainsString("link.closest?.('[data-map-direct-navigation]')", $living);
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
            'title' => 'Node導線を整理する',
            'description' => 'Contextと直接遷移を分離する',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $current = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '現在Task',
            'description' => 'PrimaryはToolbarから直接進める',
            'estimated_minutes' => 45,
            'remaining_minutes' => 30,
            'progress_percent' => 30,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        $next = Task::query()->create([
            'plan_id' => $plan->id,
            'depends_on_task_id' => $current->id,
            'title' => '次のTask',
            'description' => 'Plan確認と編集の意味が分かれるのでFocus-first',
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 2,
            'activation_cost' => 1,
            'sort_order' => 2,
        ]);

        TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $current->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native,
            'type' => 'focus_session_completed',
            'external_key' => 'v435-direct-evidence',
            'confidence' => 0.8,
            'occurred_at' => now(),
            'metadata' => ['actual_minutes' => 20],
        ]);

        InboxItem::query()->create([
            'user_id' => $user->id,
            'source_type' => 'text',
            'status' => 'new',
            'title' => '整理待ちメモ',
            'content' => 'Inboxへ直接移動できる',
            'metadata' => [],
        ]);

        return [$user, $plan, $current, $next];
    }
}
