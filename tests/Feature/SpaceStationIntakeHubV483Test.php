<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\ProductKey;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\PlanResource;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SpaceStationIntakeHubV483Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');

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

    public function test_space_station_capture_auto_interprets_when_ai_is_available_but_does_not_mutate_canonical_targets(): void
    {
        [$user] = $this->scenario();
        $this->grantPremium($user);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'destination' => 'task_evidence',
                    'reason' => '既存Taskで確認できた作業結果だから',
                    'confidence' => 93,
                    'suggested_plan_title' => 'Canovia開発',
                    'suggested_task_title' => 'Space Stationを完成させる',
                ]),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('data-space-station-flow', false)
            ->assertSee('name="intake_mode" value="chat"', false)
            ->assertSee('Space Stationへ送って整理');

        $this->actingAs($user)
            ->post(route('inbox.store'), [
                'content' => 'Space Stationの実装が完了した',
                'intake_mode' => 'chat',
                'return_to' => 'space_station',
            ])
            ->assertRedirect(route('map.index').'#focus='.rawurlencode('intent:space-station'));

        $item = InboxItem::query()->firstOrFail();

        $this->assertSame('review', $item->status);
        $this->assertSame('chat', data_get($item->metadata, 'intake_mode'));
        $this->assertSame('space_station', data_get($item->metadata, 'capture_surface'));
        $this->assertSame('task_evidence', data_get($item->metadata, 'routing_suggestion.destination'));
        $this->assertNull($item->plan_id);
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('plan_resources', 0);

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('data-space-station-flow-step="3"', false)
            ->assertSee('data-space-station-routing-candidate', false)
            ->assertSee('Task Evidence')
            ->assertSee('どこにつなぐか確認')
            ->assertSee('確定するまでは接続されません。');
    }

    public function test_docked_space_station_uses_current_plan_only_as_a_confirmation_candidate(): void
    {
        [$user, $plan] = $this->scenario();

        $params = [
            'level' => 'l3',
            'intent' => 'execution',
            'plan' => $plan->id,
        ];

        $page = $this->actingAs($user)->get(route('map.index', $params));

        $page
            ->assertOk()
            ->assertSee('data-space-station-context-candidate', false)
            ->assertSee('現在のMap Context')
            ->assertSee($plan->title)
            ->assertSee('name="map_plan" value="'.$plan->id.'"', false);

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$page->getContent());
        $xpath = new \DOMXPath($dom);
        $selectedPlan = $xpath->query(
            '//*[@data-space-station-plan]/option[@value="'.$plan->id.'" and @selected]'
        );
        $this->assertSame(1, $selectedPlan->length);

        $this->actingAs($user)
            ->post(route('inbox.store'), [
                'content' => 'このPlanを見ている途中のメモ',
                'intake_mode' => 'chat',
                'return_to' => 'map_station',
                'map_level' => 'l3',
                'map_intent' => 'execution',
                'map_plan' => $plan->id,
            ])
            ->assertRedirect(route('map.index', $params).'#dock=space-station');

        $item = InboxItem::query()->latest('id')->firstOrFail();

        $this->assertNull($item->plan_id);
        $this->assertSame('space_station', data_get($item->metadata, 'capture_surface'));
        $this->assertSame('l3', data_get($item->metadata, 'map_context.level'));
        $this->assertSame('execution', data_get($item->metadata, 'map_context.intent'));
        $this->assertSame($plan->id, data_get($item->metadata, 'map_context.plan_id'));
    }

    public function test_human_confirmed_route_returns_visible_action_to_space_station(): void
    {
        [$user, $plan] = $this->scenario();

        $item = InboxItem::query()->create([
            'user_id' => $user->id,
            'source_type' => 'url',
            'status' => 'review',
            'title' => 'Canovia repository',
            'source_url' => 'https://github.com/1kz-ma1/Canovia',
            'metadata' => [],
        ]);

        $this->actingAs($user)
            ->post(route('inbox.route', $item), [
                'destination' => 'plan_resource',
                'plan_id' => $plan->id,
                'return_to' => 'space_station',
            ])
            ->assertRedirect(route('map.index').'#focus='.rawurlencode('intent:space-station'))
            ->assertSessionHas('space_station_route_result');

        $this->assertDatabaseCount('plan_resources', 1);
        $this->assertSame('processed', $item->fresh()->status);

        $page = $this->actingAs($user)->get(route('map.index'));

        $page
            ->assertOk()
            ->assertSee('data-space-station-flow-step="4"', false)
            ->assertSee('data-space-station-route-result', false)
            ->assertSee('Plan Resourceへ接続しました')
            ->assertSee($plan->title)
            ->assertSee('Resourceを確認')
            ->assertSee(route('plans.resources.index', $plan), false);

        $resource = PlanResource::query()->firstOrFail();
        $this->assertSame($plan->id, $resource->plan_id);
    }

    public function test_execution_request_can_be_confirmed_from_space_station_with_required_fields(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $item = InboxItem::query()->create([
            'user_id' => $user->id,
            'source_type' => 'text',
            'status' => 'review',
            'title' => '次の実装依頼',
            'content' => '次の改善を進めたい',
            'metadata' => [
                'routing_suggestion' => [
                    'destination' => 'execution_request',
                    'reason' => 'これから行う作業依頼だから',
                    'confidence' => 90,
                    'suggested_plan_title' => $plan->title,
                    'suggested_task_title' => $task->title,
                ],
            ],
        ]);

        $params = [
            'level' => 'l3',
            'intent' => 'execution',
            'plan' => $plan->id,
        ];

        $this->actingAs($user)
            ->get(route('map.index', $params))
            ->assertOk()
            ->assertSee('data-space-station-execution-fields', false)
            ->assertSee('name="execution_instruction"', false)
            ->assertSee('name="execution_actor_type"', false)
            ->assertSee('name="execution_available_minutes"', false)
            ->assertSee('data-space-station-task-option', false)
            ->assertSee('data-plan-id="'.$plan->id.'"', false);

        $this->actingAs($user)
            ->post(route('inbox.route', $item), [
                'destination' => 'execution_request',
                'plan_id' => $plan->id,
                'task_id' => $task->id,
                'execution_instruction' => '次の改善案を整理して実装へ進める',
                'execution_actor_type' => 'human_ai',
                'execution_available_minutes' => 45,
                'return_to' => 'space_station',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('plans.tasks.execution_orchestration.show', [$plan, $task]));

        $item->refresh();
        $this->assertSame('processed', $item->status);
        $this->assertSame(
            '次の改善案を整理して実装へ進める',
            data_get($item->metadata, 'execution_request.instruction'),
        );
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
            'title' => 'Canovia開発',
            'description' => 'Space Station Intake Hubを完成させる',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Space Stationを完成させる',
            'description' => 'V48.3',
            'estimated_minutes' => 90,
            'remaining_minutes' => 90,
            'progress_percent' => 10,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }

    private function grantPremium(User $user): UserProductGrant
    {
        return UserProductGrant::query()->create([
            'user_id' => $user->id,
            'product_key' => ProductKey::PremiumCore,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
            'expires_at' => null,
            'metadata' => ['test' => true],
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function responseBody(array $payload): array
    {
        return [
            'id' => 'resp_space_station_v483',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
            ]],
            'usage' => [
                'input_tokens' => 120,
                'output_tokens' => 60,
                'total_tokens' => 180,
            ],
        ];
    }
}
