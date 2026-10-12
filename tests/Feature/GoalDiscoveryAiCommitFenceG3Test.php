<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Models\FutureMemo;
use App\Models\GoalContext;
use App\Models\GoalContextFact;
use App\Models\GoalDiscoveryMessage;
use App\Models\NativeAiRun;
use App\Models\Plan;
use App\Models\User;
use App\Services\NativeAiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class GoalDiscoveryAiCommitFenceG3Test extends TestCase
{
    use RefreshDatabase;

    private const USER_INPUT = 'まず資格に合格して、いつかゲームも作りたい';
    private const MEMORY_QUOTE = 'いつかゲームも作りたい';

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
            'native_ai.providers.openai.model' => 'test-model',
            'features.flags.'.FeatureKey::ConversationalOnboarding->value => [
                'enabled' => true, 'environment' => null, 'platform' => 'all',
                'minimum_app_version' => null,
            ],
        ]);
    }

    public function test_unchanged_free_onboarding_commits_reply_and_literal_memory(): void
    {
        $actor = User::factory()->create();
        $this->fakeAiReply();

        $this->submitGoal($actor);

        $this->assertDatabaseHas('goal_discovery_messages', [
            'role' => 'assistant', 'content' => '今の目標について、現在地を教えてください。',
        ]);
        $this->assertDatabaseHas('future_memos', [
            'user_id' => $actor->id, 'content' => self::MEMORY_QUOTE,
            'source' => 'ai_explicit_capture',
        ]);
        $this->assertSame('succeeded', NativeAiRun::query()->firstOrFail()->status);
        $this->assertDatabaseCount('goal_discovery_messages', 2);
        Http::assertSentCount(1);
    }

    public function test_context_owner_transfer_after_provider_blocks_reply_and_memory(): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();
        $this->fakeAiReply();
        $this->afterGateway(fn () => GoalContext::query()->firstOrFail()->update(['user_id' => $other->id]));

        $this->submitGoal($actor);
        $this->assertWithheldAiOutput();
    }

    public function test_confirmed_goal_fact_change_after_provider_blocks_reply_and_memory(): void
    {
        $actor = User::factory()->create();
        $this->fakeAiReply();
        $this->afterGateway(function (): void {
            $context = GoalContext::query()->firstOrFail();
            GoalContextFact::query()->create([
                'goal_context_id' => $context->id,
                'type' => 'constraint',
                'key' => 'new_constraint',
                'label' => '時間の制約',
                'value_json' => ['text' => '平日は30分まで'],
                'source' => 'user_answer',
                'state' => 'confirmed',
                'confidence' => 1,
                'importance' => 3,
            ]);
        });

        $this->submitGoal($actor);
        $this->assertWithheldAiOutput();
        $this->assertDatabaseCount('goal_context_facts', 2);
    }

    public function test_new_user_message_after_provider_prevents_old_reply_from_being_appended(): void
    {
        $actor = User::factory()->create();
        $this->fakeAiReply();
        $this->afterGateway(function (): void {
            GoalContext::query()->firstOrFail()->discoveryMessages()->create([
                'role' => 'user', 'content' => '今の目標を訂正します', 'metadata' => ['kind' => 'correction'],
            ]);
        });

        $this->submitGoal($actor);
        $this->assertWithheldAiOutput(2);
    }

    public function test_plan_attachment_after_provider_invalidates_plan_free_ai_reply(): void
    {
        $actor = User::factory()->create();
        $this->fakeAiReply();
        $this->afterGateway(function () use ($actor): void {
            $plan = Plan::query()->create([
                'user_id' => $actor->id,
                'owner_token' => Str::random(64),
                'public_slug' => (string) Str::uuid(),
                'title' => '新しい目標',
                'category' => 'その他',
                'start_date' => today(),
                'deadline' => today()->addMonth(),
                'is_public' => false,
            ]);
            GoalContext::query()->firstOrFail()->update(['plan_id' => $plan->id]);
        });

        $this->submitGoal($actor);
        $this->assertWithheldAiOutput();
    }

    public function test_purpose_entitlement_removed_after_provider_does_not_commit_reply_or_memory(): void
    {
        $actor = User::factory()->create();
        $this->fakeAiReply();
        $this->afterGateway(fn () => config([
            'entitlements.features.'.FeatureKey::ConversationalOnboarding->value.'.free' => false,
        ]));

        $this->submitGoal($actor);
        $this->assertWithheldAiOutput();
    }

    public function test_feature_switch_off_after_provider_does_not_commit_reply_or_memory(): void
    {
        $actor = User::factory()->create();
        $this->fakeAiReply();
        $this->afterGateway(fn () => config([
            'features.flags.'.FeatureKey::ConversationalOnboarding->value.'.enabled' => false,
        ]));

        $this->submitGoal($actor);
        $this->assertWithheldAiOutput();
    }

    public function test_provider_failure_still_allows_current_deterministic_fallback(): void
    {
        $actor = User::factory()->create();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response([
            'error' => ['code' => 'rate_limit_exceeded'],
        ], 429)]);

        $this->submitGoal($actor);

        $this->assertDatabaseHas('goal_discovery_messages', [
            'role' => 'assistant',
        ]);
        $this->assertSame(
            'provider_fallback',
            GoalDiscoveryMessage::query()->where('role', 'assistant')->firstOrFail()->metadata['mode'],
        );
        $this->assertDatabaseCount('future_memos', 0);
    }

    public function test_guest_keeps_deterministic_fallback_without_native_context_write(): void
    {
        config(['native_ai.driver' => 'disabled']);
        $this->post(route('goal_discovery.store'), [
            'desired_state' => self::USER_INPUT,
        ])->assertRedirect();

        $this->assertDatabaseCount('native_ai_runs', 0);
        $this->assertDatabaseHas('goal_discovery_messages', ['role' => 'assistant']);
        $this->assertDatabaseCount('future_memos', 0);
    }

    public function test_assistant_persistence_failure_rolls_back_ai_memory_in_same_transaction(): void
    {
        $actor = User::factory()->create();
        $this->fakeAiReply();
        GoalDiscoveryMessage::creating(function (GoalDiscoveryMessage $message): void {
            if ($message->role === 'assistant') {
                throw new RuntimeException('Synthetic assistant insert failure');
            }
        });

        $this->actingAs($actor)->post(route('goal_discovery.store'), [
            'desired_state' => self::USER_INPUT,
        ])->assertStatus(500);

        // The initial user message stays; the AI-derived Future Memo rolls back.
        $this->assertDatabaseCount('goal_discovery_messages', 1);
        $this->assertDatabaseHas('goal_discovery_messages', ['role' => 'user']);
        $this->assertDatabaseCount('future_memos', 0);
    }

    private function fakeAiReply(): void
    {
        Http::fake(['https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp-goal-discovery-g3',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'reply' => '今の目標について、現在地を教えてください。',
                        'memories' => [[
                            'kind' => 'want_to_do',
                            'category' => 'creation',
                            'source_quote' => self::MEMORY_QUOTE,
                        ]],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
            ]],
            'usage' => ['input_tokens' => 80, 'output_tokens' => 60, 'total_tokens' => 140],
        ], 200)]);
    }

    private function afterGateway(callable $callback): void
    {
        $real = app(NativeAiGateway::class);
        $mock = Mockery::mock(NativeAiGateway::class)->makePartial();
        $mock->shouldReceive('generateStructured')->once()
            ->andReturnUsing(function (...$args) use ($real, $callback) {
                $result = $real->generateStructured(...$args);
                $callback();

                return $result;
            });
        app()->instance(NativeAiGateway::class, $mock);
    }

    private function submitGoal(User $actor): void
    {
        $this->actingAs($actor)->post(route('goal_discovery.store'), [
            'desired_state' => self::USER_INPUT,
        ])->assertRedirect();
    }

    private function assertWithheldAiOutput(int $messageCount = 1): void
    {
        $this->assertDatabaseCount('goal_discovery_messages', $messageCount);
        $this->assertDatabaseMissing('goal_discovery_messages', ['role' => 'assistant']);
        $this->assertDatabaseCount('future_memos', 0);
        $this->assertSame('succeeded', NativeAiRun::query()->firstOrFail()->status);
        Http::assertSentCount(1);
    }
}
