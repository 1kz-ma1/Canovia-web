<?php

namespace Tests\Feature;

use App\Models\GoalContext;
use App\Models\GoalContextFact;
use App\Models\Plan;
use App\Models\User;
use App\Services\GoalContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GoalContextFoundationV4114Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_plan_creation_creates_low_readiness_goal_context_without_changing_existing_redirect(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('plans.store'), [
            'title' => 'サッカーが上手くなりたい',
            'category' => 'その他',
            'create_request_id' => (string) Str::uuid(),
        ]);

        $plan = Plan::query()->where('title', 'サッカーが上手くなりたい')->firstOrFail();

        $response->assertRedirect(route('plans.show', $plan));

        $context = GoalContext::query()->where('plan_id', $plan->id)->firstOrFail();
        $this->assertSame('サッカーが上手くなりたい', $context->desired_state);
        $this->assertSame(25, $context->readiness_score);
        $this->assertSame('low', $context->readiness_state);
        $this->assertSame('discovery', $context->status);

        $this->assertDatabaseHas('goal_context_facts', [
            'goal_context_id' => $context->id,
            'type' => 'target',
            'key' => 'goal_title',
            'source' => 'user_answer',
            'state' => 'confirmed',
        ]);
    }

    public function test_existing_plan_gets_one_goal_context_lazily_and_repeated_ensure_is_idempotent(): void
    {
        [$user, $plan] = $this->scenario('車を月10台売りたい', '就活・キャリア');

        $service = app(GoalContextService::class);
        $first = $service->ensureForPlan($plan, $user->id);
        $second = $service->ensureForPlan($plan, $user->id);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('goal_contexts', 1);
        $this->assertSame(
            1,
            GoalContextFact::query()
                ->where('goal_context_id', $first->id)
                ->where('key', 'goal_title')
                ->count(),
        );
    }

    public function test_candidate_ai_hint_and_known_unknown_do_not_increase_readiness(): void
    {
        [$user, $plan] = $this->scenario('営業成績を上げたい', 'その他');
        $service = app(GoalContextService::class);
        $context = $service->ensureForPlan($plan, $user->id);

        $service->recordFact(
            $context,
            type: 'current_state',
            label: '現在の販売台数候補',
            value: ['value' => 4, 'unit' => '台/月'],
            source: 'native_ai',
            state: 'candidate',
            key: 'monthly_sales',
            confidence: 0.62,
        );

        $service->recordFact(
            $context,
            type: 'unknown',
            label: '見積から成約への転換率',
            value: null,
            source: 'system',
            state: 'unknown',
            key: 'quote_to_close_rate',
            confidence: 1,
            importance: 5,
        );

        $context->refresh();

        $this->assertSame(25, $context->readiness_score);
        $this->assertSame('low', $context->readiness_state);
    }

    public function test_confirmed_context_facts_raise_readiness_deterministically(): void
    {
        [$user, $plan] = $this->scenario('車を月10台売りたい', 'その他');
        $service = app(GoalContextService::class);
        $context = $service->ensureForPlan($plan, $user->id);

        $service->recordFact(
            $context,
            type: 'current_state',
            label: '現在の販売台数',
            value: ['value' => 4, 'unit' => '台/月'],
            source: 'user_answer',
            state: 'confirmed',
            key: 'monthly_sales',
        );
        $this->assertSame(50, $context->fresh()->readiness_score);

        $service->recordFact(
            $context,
            type: 'signal',
            label: '成功指標',
            value: ['value' => 10, 'unit' => '台/月'],
            source: 'user_answer',
            state: 'confirmed',
            key: 'monthly_sales_target',
        );
        $this->assertSame(70, $context->fresh()->readiness_score);
        $this->assertSame('high', $context->fresh()->readiness_state);

        $service->recordFact(
            $context,
            type: 'constraint',
            label: '販売期間',
            value: ['period' => 'monthly'],
            source: 'user_answer',
            state: 'confirmed',
            key: 'sales_period',
        );
        $this->assertSame(80, $context->fresh()->readiness_score);

        $service->recordFact(
            $context,
            type: 'driver',
            label: '見積から成約への転換',
            value: ['metric' => 'quote_to_close_rate'],
            source: 'user_answer',
            state: 'confirmed',
            key: 'quote_to_close_rate',
        );
        $this->assertSame(100, $context->fresh()->readiness_score);
        $this->assertSame('active', $context->fresh()->status);
    }

    public function test_confirmed_fact_supersedes_candidate_or_unknown_with_same_key(): void
    {
        [$user, $plan] = $this->scenario('サッカーが上手くなりたい', 'その他');
        $service = app(GoalContextService::class);
        $context = $service->ensureForPlan($plan, $user->id);

        $candidate = $service->recordFact(
            $context,
            type: 'current_state',
            label: '経験',
            value: ['text' => '昔やっていたかもしれない'],
            source: 'native_ai',
            state: 'candidate',
            key: 'experience',
            confidence: 0.55,
        );

        $unknown = $service->recordFact(
            $context,
            type: 'unknown',
            label: '経験',
            value: null,
            source: 'system',
            state: 'unknown',
            key: 'experience',
        );

        $confirmed = $service->recordFact(
            $context,
            type: 'current_state',
            label: '経験',
            value: ['text' => '中学まで経験、5年ブランク'],
            source: 'user_answer',
            state: 'confirmed',
            key: 'experience',
        );

        $this->assertSame('superseded', $candidate->fresh()->state);
        $this->assertSame('superseded', $unknown->fresh()->state);
        $this->assertSame('confirmed', $confirmed->state);
    }

    public function test_plan_builder_receives_confirmed_hints_unknowns_and_measurement_rule(): void
    {
        [$user, $plan] = $this->scenario('サッカーが上手くなりたい', 'その他');
        $service = app(GoalContextService::class);
        $context = $service->ensureForPlan($plan, $user->id);

        $service->recordFact(
            $context,
            type: 'current_state',
            label: '経験',
            value: ['text' => '中学まで経験、5年ブランク'],
            source: 'user_answer',
            state: 'confirmed',
            key: 'experience',
        );
        $service->recordFact(
            $context,
            type: 'driver',
            label: 'スタミナが課題かもしれない',
            value: ['text' => '未確認'],
            source: 'native_ai',
            state: 'candidate',
            key: 'stamina',
            confidence: 0.52,
        );
        $service->recordFact(
            $context,
            type: 'unknown',
            label: '実戦でのパフォーマンス',
            value: null,
            source: 'system',
            state: 'unknown',
            key: 'match_performance',
            importance: 5,
        );

        $response = $this->actingAs($user)
            ->get(route('plans.ai_task_assistant.show', $plan))
            ->assertOk();

        $response
            ->assertSee('Canoviaが今わかっていること')
            ->assertSee('現在地の把握 50%')
            ->assertSee('CONFIRMED FACTS:', false)
            ->assertSee('UNCONFIRMED HINTS:', false)
            ->assertSee('KNOWN UNKNOWNS:', false)
            ->assertSee('実戦でのパフォーマンス')
            ->assertSee('Measurement Task')
            ->assertSee('KNOWN UNKNOWNSや重要な不足情報を推測で埋めない');
    }

    public function test_plan_title_change_updates_initial_goal_but_preserves_richer_desired_state(): void
    {
        [$user, $plan] = $this->scenario('営業を頑張る', 'その他');
        $service = app(GoalContextService::class);
        $context = $service->ensureForPlan($plan, $user->id);

        $this->actingAs($user)->put(route('plans.update', $plan), [
            'title' => '車を月10台売る',
            'category' => 'その他',
        ])->assertRedirect(route('plans.show', $plan));

        $this->assertSame('車を月10台売る', $context->fresh()->desired_state);

        $context->update(['desired_state' => '月10台を安定して販売し、再現できる営業プロセスを作る']);

        $this->actingAs($user)->put(route('plans.update', $plan->fresh()), [
            'title' => '販売目標 月10台',
            'category' => 'その他',
        ])->assertRedirect(route('plans.show', $plan));

        $this->assertSame(
            '月10台を安定して販売し、再現できる営業プロセスを作る',
            $context->fresh()->desired_state,
        );
    }

    private function scenario(string $title, string $category): array
    {
        $user = User::factory()->create();

        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => null,
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'auto',
            'start_date' => today(),
            'deadline' => null,
            'is_public' => false,
        ]);

        return [$user, $plan];
    }
}
