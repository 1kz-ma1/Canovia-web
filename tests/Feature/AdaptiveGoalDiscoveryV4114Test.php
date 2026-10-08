<?php

namespace Tests\Feature;

use App\Models\GoalContext;
use App\Models\GoalContextFact;
use App\Models\Plan;
use App\Models\User;
use App\Services\GoalContextService;
use App\Services\FirstRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdaptiveGoalDiscoveryV4114Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_plan_create_opens_goal_discovery_without_forcing_input_mode_selection(): void
    {
        $this->withCookie(FirstRunService::COOKIE, '1')->get(route('plans.create'))
            ->assertOk()
            ->assertSee('どんな未来にしたい？')
            ->assertSee('サッカーが上手くなりたい')
            ->assertSee('車を月10台売りたい')
            ->assertSee('応用情報技術者試験 合格')
            ->assertSee('最初はこれだけ')
            ->assertSee('手動で細かくPlanを作る')
            ->assertDontSee('入力方法を選択');
    }

    public function test_goal_is_saved_before_plan_exists_and_first_question_is_current_state(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('goal_discovery.store'), [
                'desired_state' => 'サッカーが上手くなりたい',
            ]);

        $context = GoalContext::firstOrFail();

        $response->assertRedirect(route('goal_discovery.show', $context));
        $this->assertNull($context->plan_id);
        $this->assertSame($user->id, $context->user_id);
        $this->assertSame(25, $context->readiness_score);
        $this->assertSame('low', $context->readiness_state);

        $this->actingAs($user)
            ->get(route('goal_discovery.show', $context))
            ->assertOk()
            ->assertSee('今はどんな状態に近い？')
            ->assertSee('これから始める')
            ->assertSee('文章で説明する')
            ->assertSee('現在地の把握 25%');
    }

    public function test_quick_answer_updates_current_state_and_interaction_profile(): void
    {
        [$user, $context] = $this->context('車を月10台売りたい');

        $this->actingAs($user)
            ->post(route('goal_discovery.answer', $context), [
                'question_id' => 'current_state',
                'answer_mode' => 'quick',
                'choice' => 'active',
            ])
            ->assertRedirect(route('goal_discovery.show', $context))
            ->assertSessionHasNoErrors();

        $context->refresh();

        $this->assertSame('今も取り組んでいる', $context->current_state_summary);
        $this->assertSame(50, $context->readiness_score);
        $this->assertSame('medium', $context->readiness_state);
        $this->assertSame(1, data_get($context->interaction_profile, 'quick_answers'));
        $this->assertSame('balanced', data_get($context->interaction_profile, 'preferred'));

        $this->actingAs($user)
            ->get(route('goal_discovery.show', $context))
            ->assertOk()
            ->assertSee('「達成した」と判断できる基準はありそう？')
            ->assertSee('現在地の把握 50%');
    }

    public function test_success_signal_style_is_followed_by_one_detail_question(): void
    {
        [$user, $context] = $this->context('車を月10台売りたい');

        $this->answer($user, $context, 'current_state', 'quick', choice: 'active');
        $this->answer($user, $context, 'success_signal_style', 'quick', choice: 'numeric');

        $this->actingAs($user)
            ->get(route('goal_discovery.show', $context))
            ->assertOk()
            ->assertSee('その基準を、ざっくり一言で教えて')
            ->assertSee('例：月10台');

        $this->answer(
            $user,
            $context,
            'success_signal_detail',
            'text',
            text: '月10台販売できたら達成',
        );

        $context->refresh();

        $this->assertSame(70, $context->readiness_score);
        $this->assertSame('high', $context->readiness_state);
        $this->assertDatabaseHas('goal_context_facts', [
            'goal_context_id' => $context->id,
            'type' => 'signal',
            'key' => 'success_signal',
            'state' => 'confirmed',
        ]);
    }

    public function test_high_readiness_requires_success_signal_even_if_other_coverage_is_large(): void
    {
        [$user, $context] = $this->context('営業成績を上げたい');
        $service = app(GoalContextService::class);

        $service->recordFact(
            $context,
            type: 'current_state',
            label: '現在地',
            value: ['text' => '今月4台'],
            source: 'user_answer',
            key: 'current_state_summary',
        );
        $service->recordFact(
            $context,
            type: 'constraint',
            label: '制約',
            value: ['text' => '今月中'],
            source: 'user_answer',
            key: 'constraints_general',
        );
        $service->recordFact(
            $context,
            type: 'driver',
            label: '観測方法',
            value: ['text' => '商談数と見積数'],
            source: 'user_answer',
            key: 'measurement_method',
        );

        $context->refresh();

        $this->assertSame(80, $context->readiness_score);
        $this->assertSame('medium', $context->readiness_state);
    }

    public function test_two_text_answers_shift_presentation_to_conversational_without_user_selecting_a_mode(): void
    {
        [$user, $context] = $this->context('サッカーが上手くなりたい');

        $this->answer(
            $user,
            $context,
            'current_state',
            'text',
            text: '中学まで経験していて5年ブランク',
        );
        $this->answer(
            $user,
            $context,
            'success_signal_style',
            'text',
            text: '社会人リーグで90分安定してプレーできるようになりたい',
        );

        $context->refresh();

        $this->assertSame(2, data_get($context->interaction_profile, 'text_answers'));
        $this->assertSame('conversational', data_get($context->interaction_profile, 'preferred'));

        $this->actingAs($user)
            ->get(route('goal_discovery.show', $context))
            ->assertOk()
            ->assertSee('進めるうえで、先に知っておくべき制約はある？')
            ->assertSee('これで更新')
            ->assertSee('タップで答えるなら');
    }

    public function test_two_skips_prioritize_provisional_plan_without_fabricating_facts(): void
    {
        [$user, $context] = $this->context('絵が上手くなりたい');

        $this->answer($user, $context, 'current_state', 'skip');
        $this->answer($user, $context, 'success_signal_style', 'skip');

        $context->refresh();

        $this->assertSame(2, data_get($context->interaction_profile, 'skips'));
        $this->assertSame('compact', data_get($context->interaction_profile, 'preferred'));
        $this->assertSame(25, $context->readiness_score);

        $this->actingAs($user)
            ->get(route('goal_discovery.show', $context))
            ->assertOk()
            ->assertSee('質問は一旦ここまででも大丈夫')
            ->assertSee('この情報で仮Planへ進む');

        $this->assertSame(
            2,
            GoalContextFact::query()
                ->where('goal_context_id', $context->id)
                ->where('state', 'unknown')
                ->count(),
        );
    }

    public function test_goal_context_can_be_attached_to_plan_at_any_readiness(): void
    {
        [$user, $context] = $this->context('サッカーが上手くなりたい');

        $this->actingAs($user)
            ->post(route('plans.store'), [
                'goal_context_id' => $context->id,
                'title' => 'client-side tampered title',
                'create_request_id' => (string) Str::uuid(),
            ])
            ->assertSessionHasNoErrors();

        $plan = Plan::firstOrFail();

        $this->assertSame($plan->id, $context->fresh()->plan_id);
        $this->assertSame($context->desired_state, $plan->title);
        $this->assertSame(25, $context->fresh()->readiness_score);

        $this->actingAs($user)
            ->get(route('plans.ai_task_assistant.show', $plan))
            ->assertOk()
            ->assertSee('Canoviaが今わかっていること')
            ->assertSee('現在地の把握 25%');
    }

    public function test_goal_context_cannot_be_attached_by_another_user(): void
    {
        [$owner, $context] = $this->context('車を月10台売りたい');
        $other = User::factory()->create();

        $this->actingAs($other)
            ->post(route('plans.store'), [
                'goal_context_id' => $context->id,
                'title' => $context->desired_state,
                'create_request_id' => (string) Str::uuid(),
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('plans', 0);
        $this->assertNull($context->fresh()->plan_id);
    }

    public function test_stale_question_cannot_overwrite_newer_context(): void
    {
        [$user, $context] = $this->context('資格試験に合格したい');

        $this->answer($user, $context, 'current_state', 'quick', choice: 'starting');

        $this->actingAs($user)
            ->post(route('goal_discovery.answer', $context), [
                'question_id' => 'current_state',
                'answer_mode' => 'quick',
                'choice' => 'active',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('goal_discovery');

        $this->assertSame('これから始める', $context->fresh()->current_state_summary);
    }

    public function test_manual_plan_form_remains_available_as_fallback(): void
    {
        $this->withCookie(FirstRunService::COOKIE, '1')->get(route('plans.create.manual'))
            ->assertOk()
            ->assertSee('まず、目標の名前だけ決めよう。')
            ->assertSee('目標を保存して次の行動へ')
            ->assertSee('>青<', false)
            ->assertSee('>緑<', false)
            ->assertSee('>紫<', false);
    }

    private function context(string $desiredState): array
    {
        $user = User::factory()->create();
        $context = app(GoalContextService::class)->createDraft(
            $desiredState,
            userId: $user->id,
        );

        return [$user, $context];
    }

    private function answer(
        User $user,
        GoalContext $context,
        string $questionId,
        string $mode,
        ?string $choice = null,
        ?string $text = null,
    ): void {
        $this->actingAs($user)
            ->post(route('goal_discovery.answer', $context), [
                'question_id' => $questionId,
                'answer_mode' => $mode,
                'choice' => $choice,
                'answer_text' => $text,
            ])
            ->assertRedirect(route('goal_discovery.show', $context))
            ->assertSessionHasNoErrors();
    }
}
