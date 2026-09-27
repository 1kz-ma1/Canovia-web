<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\ProductKey;
use App\Models\CompanionThread;
use App\Models\FutureMemo;
use App\Models\GoalContext;
use App\Models\InboxItem;
use App\Models\NativeAiRun;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\CompanionContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConversationalOnboardingV4116Test extends TestCase
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
            'features.flags.'.FeatureKey::ConversationalOnboarding->value => [
                'enabled' => true,
                'environment' => null,
                'platform' => 'all',
                'minimum_app_version' => null,
            ],
            'features.flags.'.FeatureKey::CanoviaCompanion->value => [
                'enabled' => true,
                'environment' => null,
                'platform' => 'all',
                'minimum_app_version' => null,
            ],
        ]);
    }

    public function test_new_account_enters_first_companion_instead_of_empty_home(): void
    {
        $this->post(route('auth.register'), [
            'name' => 'New User',
            'email' => 'new-user@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
            ->assertRedirect(route('first_run.show'))
            ->assertSessionHas('status', 'アカウントを作成しました。まずCanoviaに、今進めたいことをそのまま話してみてください。');

        $this->get(route('first_run.show'))
            ->assertOk()
            ->assertSee('はじめまして')
            ->assertDontSee('今はスキップ');

        $this->post(route('first_run.start'))
            ->assertRedirect(route('plans.create'));

        $this->get(route('plans.create'))
            ->assertOk()
            ->assertSee('FIRST COMPANION', false)
            ->assertSee('まず、話すところから始めよう')
            ->assertSee('Canoviaと始める');
    }

    public function test_free_user_gets_native_first_companion_reply_and_goal_context_stays_source_of_truth(): void
    {
        $user = User::factory()->create();

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'reply' => '応用情報を目指しているんですね。まず、今の状態を教えてください。',
                    'memories' => [],
                ]),
                200,
            ),
        ]);

        $response = $this->actingAs($user)
            ->post(route('goal_discovery.store'), [
                'desired_state' => '応用情報技術者試験に合格したい',
            ]);

        $context = GoalContext::query()->firstOrFail();

        $response->assertRedirect(route('goal_discovery.show', $context));
        $this->assertSame(25, $context->readiness_score);
        $this->assertSame('low', $context->readiness_state);
        $this->assertDatabaseHas('goal_context_facts', [
            'goal_context_id' => $context->id,
            'key' => 'goal_title',
            'state' => 'confirmed',
        ]);
        $this->assertDatabaseHas('goal_discovery_messages', [
            'goal_context_id' => $context->id,
            'role' => 'user',
            'content' => '応用情報技術者試験に合格したい',
        ]);
        $this->assertDatabaseHas('goal_discovery_messages', [
            'goal_context_id' => $context->id,
            'role' => 'assistant',
            'content' => '応用情報を目指しているんですね。まず、今の状態を教えてください。',
        ]);

        $run = NativeAiRun::query()->firstOrFail();
        $this->assertSame(FeatureKey::ConversationalOnboarding->value, $run->feature_key);
        $this->assertSame('conversational_onboarding_reply', $run->purpose);

        $this->actingAs($user)
            ->get(route('goal_discovery.show', $context))
            ->assertOk()
            ->assertSee('YOU', false)
            ->assertSee('CANOVIA', false)
            ->assertSee('応用情報を目指しているんですね。まず、今の状態を教えてください。')
            ->assertSee('今はどんな状態に近い？');

        Http::assertSentCount(1);
    }

    public function test_provider_unavailable_falls_back_to_deterministic_goal_discovery_without_blocking_free_path(): void
    {
        config(['native_ai.driver' => 'disabled']);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('goal_discovery.store'), [
                'desired_state' => 'サッカーが上手くなりたい',
            ])
            ->assertRedirect();

        $context = GoalContext::query()->firstOrFail();

        $this->assertDatabaseCount('native_ai_runs', 0);
        $this->assertDatabaseHas('goal_discovery_messages', [
            'goal_context_id' => $context->id,
            'role' => 'assistant',
        ]);

        $this->actingAs($user)
            ->get(route('goal_discovery.show', $context))
            ->assertOk()
            ->assertSee('今はどんな状態に近い？')
            ->assertSee('これから始める');
    }

    public function test_only_literal_explicit_user_text_can_be_captured_as_internal_memory(): void
    {
        $user = User::factory()->create();
        $input = 'まずAPに合格したい。いつか英語も勉強したい';

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'reply' => 'まずAP合格を主目標として整理します。今の学習状況を教えてください。',
                    'memories' => [[
                        'kind' => 'want_to_do',
                        'category' => 'study',
                        'source_quote' => 'いつか英語も勉強したい',
                    ]],
                ]),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route('goal_discovery.store'), ['desired_state' => $input])
            ->assertRedirect();

        $memory = FutureMemo::query()->firstOrFail();

        $this->assertSame($user->id, $memory->user_id);
        $this->assertSame('want_to_do', $memory->kind);
        $this->assertSame('study', $memory->category);
        $this->assertSame('いつか英語も勉強したい', $memory->content);
        $this->assertSame('ai_explicit_capture', $memory->source);
        $this->assertSame('goal_discovery', $memory->source_context_type);
        $this->assertTrue($memory->use_for_ai);
        $this->assertNotNull($memory->captured_at);
    }

    public function test_ai_cannot_store_invented_memory_that_is_not_a_literal_quote(): void
    {
        $user = User::factory()->create();

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'reply' => '今の状況をもう少し教えてください。',
                    'memories' => [[
                        'kind' => 'value',
                        'category' => 'life',
                        'source_quote' => '家族との時間を最優先にしたい',
                    ]],
                ]),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route('goal_discovery.store'), [
                'desired_state' => '副業を始めたい',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('future_memos', 0);
    }

    public function test_companion_can_capture_explicit_memory_without_a_user_managed_future_memo_step(): void
    {
        $user = User::factory()->create();
        $this->grantPremium($user);

        $thread = CompanionThread::query()->create([
            'user_id' => $user->id,
            'status' => CompanionThread::STATUS_ACTIVE,
            'context_scope' => ['scope' => 'global'],
        ]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'reply' => '今はCanovia開発を優先しつつ、その関心は必要な時に文脈として使えます。',
                    'memories' => [[
                        'kind' => 'interest',
                        'category' => 'creation',
                        'source_quote' => 'いつかゲーム開発もやりたい',
                    ]],
                    'candidates' => [],
                ]),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route('companion.messages.store', $thread), [
                'request_id' => (string) Str::uuid(),
                'source_path' => '/',
                'content' => '今はCanoviaを作ってるけど、いつかゲーム開発もやりたい',
            ])
            ->assertRedirect(route('companion.show', $thread))
            ->assertSessionHasNoErrors();

        $memory = FutureMemo::query()->firstOrFail();
        $this->assertSame('いつかゲーム開発もやりたい', $memory->content);
        $this->assertSame('companion_thread', $memory->source_context_type);
        $this->assertSame($thread->id, $memory->source_context_id);

        $snapshot = app(CompanionContextService::class)->snapshot($user->fresh());
        $this->assertSame(
            'いつかゲーム開発もやりたい',
            data_get($snapshot, 'memory.0.content'),
        );
    }

    public function test_normal_plan_surfaces_no_longer_ask_user_to_manage_future_memos(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('plans.create.manual'))
            ->assertOk()
            ->assertDontSee(route('future_memos.index'), false)
            ->assertDontSee('未来メモを作る');

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Canoviaと始める')
            ->assertDontSee('目標を一緒に探す');

        InboxItem::query()->create([
            'user_id' => $user->id,
            'source_type' => 'text',
            'status' => 'new',
            'title' => '将来やりたいこと',
            'content' => 'いつか別のサービスも作りたい',
            'metadata' => [],
        ]);

        $this->actingAs($user)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertDontSee('Future Memo種別')
            ->assertDontSee('value="future_memo"', false);
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

    private function responseBody(array $data): array
    {
        return [
            'id' => 'resp_'.Str::random(10),
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
            ]],
            'usage' => [
                'input_tokens' => 120,
                'output_tokens' => 80,
                'total_tokens' => 200,
            ],
        ];
    }
}
