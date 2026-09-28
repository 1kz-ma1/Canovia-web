<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\ProductKey;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\PlanResource;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SpaceStationV442Test extends TestCase
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

    public function test_space_station_renders_capture_review_and_companion_as_one_hub(): void
    {
        [$user] = $this->scenario();
        $this->grantPremium($user);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('data-space-station-panel', false)
            ->assertSee('まずはここへ投げる')
            ->assertSee('name="return_to" value="space_station"', false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="source_file"', false)
            ->assertSee('ここから相談する')
            ->assertSee('name="entry_type" value="global"', false)
            ->assertSee('data-map-companion-form', false);
    }

    public function test_capture_from_space_station_creates_only_inbox_item_and_returns_to_station_focus(): void
    {
        [$user] = $this->scenario();

        $response = $this->actingAs($user)
            ->post(route('inbox.store'), [
                'content' => 'READMEを直したので、あとでどこへつなぐか整理したい',
                'return_to' => 'space_station',
            ]);

        $response->assertRedirect(
            route('map.index').'#focus='.rawurlencode('intent:space-station')
        );

        $item = InboxItem::query()->firstOrFail();

        $this->assertSame('new', $item->status);
        $this->assertSame('text', $item->source_type);
        $this->assertDatabaseCount('plan_resources', 0);
        $this->assertDatabaseCount('task_evidences', 0);

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('LATEST INPUT')
            ->assertSee('READMEを直したので');
    }

    public function test_ai_routing_candidate_is_shown_as_l0_connection_but_stops_before_mutation(): void
    {
        [$user] = $this->scenario();
        $this->grantPremium($user);

        $item = InboxItem::query()->create([
            'user_id' => $user->id,
            'source_type' => 'text',
            'status' => 'new',
            'title' => '実装結果',
            'content' => '認証画面の修正が完了した',
            'metadata' => [],
        ]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'destination' => 'task_evidence',
                    'reason' => '既存Taskで確認できた作業結果だから',
                    'confidence' => 91,
                    'suggested_plan_title' => 'Canovia開発',
                    'suggested_task_title' => '認証を直す',
                ]),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route('inbox.suggest', $item), [
                'return_to' => 'space_station',
            ])
            ->assertRedirect(route('map.index').'#focus='.rawurlencode('intent:space-station'));

        $item->refresh();
        $this->assertSame('review', $item->status);
        $this->assertSame('task_evidence', data_get($item->metadata, 'routing_suggestion.destination'));
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('plan_resources', 0);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('data-space-station-routing-candidate', false)
            ->assertSee('振り返り')
            ->assertSee('Task Evidence')
            ->assertSee('既存Taskで確認できた作業結果だから')
            ->assertSee('確信度 91%')
            ->assertSee('Plan候補: Canovia開発')
            ->assertSee('Task候補: 認証を直す')
            ->assertSee('確定するまでPlan / Task / Evidence等の正規データは作りません。');
    }

    public function test_human_confirmation_from_space_station_uses_existing_inbox_routing_boundary(): void
    {
        [$user, $plan] = $this->scenario();

        $item = InboxItem::query()->create([
            'user_id' => $user->id,
            'source_type' => 'url',
            'status' => 'review',
            'title' => 'Canovia repository',
            'source_url' => 'https://github.com/1kz-ma1/Canovia',
            'metadata' => [
                'routing_suggestion' => [
                    'destination' => 'plan_resource',
                    'reason' => 'Planで参照するGitHub URLだから',
                    'confidence' => 95,
                    'suggested_plan_title' => 'Canovia開発',
                    'suggested_task_title' => null,
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('inbox.route', $item), [
                'destination' => 'plan_resource',
                'plan_id' => $plan->id,
                'return_to' => 'space_station',
            ])
            ->assertRedirect(route('map.index').'#focus='.rawurlencode('intent:space-station'))
            ->assertSessionHasNoErrors();

        $resource = PlanResource::query()->firstOrFail();

        $this->assertSame($plan->id, $resource->plan_id);
        $this->assertSame('github', $resource->provider);
        $this->assertSame('processed', $item->fresh()->status);
        $this->assertSame(
            'plan_resource',
            data_get($item->fresh()->metadata, 'routing_confirmed.destination')
        );
    }

    public function test_space_station_projection_key_tracks_routing_state_without_exposing_content(): void
    {
        [$user] = $this->scenario();

        $before = $this->actingAs($user)->get(route('map.index'))->viewData('graph');
        $beforeKey = $before['projection_key'];

        InboxItem::query()->create([
            'user_id' => $user->id,
            'source_type' => 'text',
            'status' => 'new',
            'title' => '秘密の入力',
            'content' => 'Telemetryやprojection key payloadへ直接保存したくない本文',
            'metadata' => [],
        ]);

        $after = $this->actingAs($user)->get(route('map.index'))->viewData('graph');

        $this->assertNotSame($beforeKey, $after['projection_key']);
        $this->assertNotSame('', data_get($after, 'space_station.state_key'));
        $this->assertStringNotContainsString(
            'Telemetryやprojection key payloadへ直接保存したくない本文',
            (string) $after['projection_key'],
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
            'description' => 'Space Stationを実装する',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        return [$user, $plan];
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
            'id' => 'resp_space_station',
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
