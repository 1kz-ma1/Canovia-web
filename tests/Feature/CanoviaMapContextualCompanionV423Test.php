<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\ProductKey;
use App\Models\CompanionThread;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\CompanionContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanoviaMapContextualCompanionV423Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'openai',
            'native_ai.providers.openai.base_url' => 'https://api.openai.com/v1',
            'native_ai.providers.openai.api_key' => 'test-key',
            'native_ai.providers.openai.model' => 'gpt-5.6-luna',
            'features.flags.'.FeatureKey::CanoviaCompanion->value => [
                'enabled' => true,
                'environment' => null,
                'platform' => 'all',
                'minimum_app_version' => null,
            ],
        ]);
    }

    public function test_map_surface_launches_companion_with_only_map_node_id_as_client_context(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $this->actingAs($user)
            ->get(route('map.index', ['level' => 'l3']))
            ->assertOk()
            ->assertSee('name="entry_type" value="map"', false)
            ->assertSee('name="map_node_id" value="task:'.$task->id.'"', false)
            ->assertSee('このContextについて相談')
            ->assertDontSee('name="map_context"', false);
    }

    public function test_map_task_entry_rebuilds_selected_node_and_one_hop_context_on_server(): void
    {
        [$user, $plan, $task, $next] = $this->scenario();
        $this->grantPremium($user);

        $response = $this->actingAs($user)
            ->post(route('companion.entry'), [
                'entry_type' => 'map',
                'map_node_id' => 'task:'.$task->id,
                'source_path' => '/map?level=l3#focus=task%3A'.$task->id,
                'source_route' => 'map.index',
            ]);

        $thread = CompanionThread::firstOrFail();

        $response->assertRedirect(route('companion.show', $thread));
        $this->assertSame($plan->id, $thread->plan_id);
        $this->assertSame($task->id, $thread->task_id);
        $this->assertSame('map', data_get($thread->context_scope, 'entry_type'));
        $this->assertSame('task:'.$task->id, data_get($thread->context_scope, 'entry_key'));
        $this->assertSame('task:'.$task->id, data_get($thread->context_scope, 'map_node_id'));
        $this->assertSame('task:'.$task->id, data_get($thread->context_scope, 'map_context.selected_node.id'));
        $this->assertSame($task->title, data_get($thread->context_scope, 'map_context.selected_node.label'));

        $neighborIds = collect(data_get($thread->context_scope, 'map_context.surrounding_nodes', []))
            ->pluck('node.id')
            ->all();

        $this->assertContains('plan:'.$plan->id, $neighborIds);
        $this->assertContains('task:'.$next->id, $neighborIds);

        $neighborTypes = collect(data_get($thread->context_scope, 'map_context.surrounding_nodes', []))
            ->pluck('node.type')
            ->all();
        $this->assertContains('tool', $neighborTypes);

        $snapshot = app(CompanionContextService::class)->snapshot(
            $user,
            $plan,
            $task,
            $thread->context_scope,
        );

        $this->assertSame('map', data_get($snapshot, 'entry.type'));
        $this->assertSame('Map · '.$task->title, data_get($snapshot, 'entry.label'));
        $this->assertSame('task:'.$task->id, data_get($snapshot, 'entry.map_context.selected_node.id'));

        $this->actingAs($user)
            ->get(route('companion.show', $thread))
            ->assertOk()
            ->assertSee('Map · '.$task->title)
            ->assertSee($next->title);
    }

    public function test_map_tool_entry_reuses_existing_task_thread_but_refreshes_map_context(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $this->actingAs($user)
            ->post(route('companion.entry'), [
                'entry_type' => 'task',
                'task_id' => $task->id,
                'source_path' => '/tasks/'.$task->id.'/edit',
                'source_route' => 'tasks.edit',
            ])
            ->assertRedirect();

        $thread = CompanionThread::firstOrFail();

        $mapHtml = $this->actingAs($user)
            ->get(route('map.index', ['level' => 'l3']))
            ->assertOk()
            ->getContent();

        preg_match('/data-map-node-id="([^"]+)"[^>]*data-map-node-type="tool"/s', $mapHtml, $matches);
        $toolNodeId = (string) ($matches[1] ?? '');
        $this->assertNotSame('', $toolNodeId);

        $this->actingAs($user)
            ->post(route('companion.entry'), [
                'entry_type' => 'map',
                'map_node_id' => $toolNodeId,
                'source_path' => '/map?level=l3#focus='.rawurlencode($toolNodeId),
                'source_route' => 'map.index',
            ])
            ->assertRedirect(route('companion.show', $thread));

        $this->assertDatabaseCount('companion_threads', 1);

        $thread->refresh();
        $this->assertSame('map', data_get($thread->context_scope, 'entry_type'));
        $this->assertSame('task:'.$task->id, data_get($thread->context_scope, 'entry_key'));
        $this->assertSame($toolNodeId, data_get($thread->context_scope, 'map_context.selected_node.id'));
        $this->assertSame('task:'.$task->id, data_get($thread->context_scope, 'map_context.primary_action.id'));
    }

    public function test_map_entry_rejects_node_that_is_not_in_latest_projection(): void
    {
        [$user] = $this->scenario();
        $this->grantPremium($user);

        $this->actingAs($user)
            ->from(route('map.index'))
            ->post(route('companion.entry'), [
                'entry_type' => 'map',
                'map_node_id' => 'task:999999',
                'source_path' => '/map?level=l3#focus=task%3A999999',
                'source_route' => 'map.index',
            ])
            ->assertRedirect(route('map.index'))
            ->assertSessionHasErrors('map_node_id');

        $this->assertDatabaseCount('companion_threads', 0);
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
            'title' => 'Map Companionを作る',
            'description' => '選択中の世界について話せるようにする',
            'category' => '仕事',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Contextual Companionを実装する',
            'description' => 'Map Focusを会話Contextへ渡す',
            'next_action_note' => '選択Nodeと1-hopをサーバーで再構築する',
            'estimated_minutes' => 60,
            'remaining_minutes' => 45,
            'progress_percent' => 25,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        $next = Task::query()->create([
            'plan_id' => $plan->id,
            'depends_on_task_id' => $task->id,
            'title' => 'Mapの会話導線を磨く',
            'description' => 'Companion後のUXを整える',
            'estimated_minutes' => 45,
            'remaining_minutes' => 45,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 2,
            'activation_cost' => 1,
            'sort_order' => 2,
        ]);

        return [$user, $plan, $task, $next];
    }

    private function grantPremium(User $user): void
    {
        UserProductGrant::query()->create([
            'user_id' => $user->id,
            'product_key' => ProductKey::PremiumCore,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
            'metadata' => ['test' => true],
        ]);
    }
}
