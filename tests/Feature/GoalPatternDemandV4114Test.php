<?php

namespace Tests\Feature;

use App\Models\GoalContext;
use App\Models\GuidedExecution;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\GoalContextService;
use App\Services\GoalPatternDemandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GoalPatternDemandV4114Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_service_aggregates_goal_patterns_without_needing_a_parallel_demand_log(): void
    {
        $soccerUser = User::factory()->create();
        [$soccerPlan, $soccerTask] = $this->planTask(
            $soccerUser,
            'サッカーが上手くなりたい',
            'その他',
            'チーム練習に参加する',
        );

        $goals = app(GoalContextService::class);
        $soccerContext = $goals->createDraft('サッカーが上手くなりたい', $soccerUser->id);
        $goals->attachPlan($soccerContext, $soccerPlan);
        $goals->recordFact(
            $soccerContext,
            type: 'current_state',
            label: '現在地',
            value: ['stage' => 'active', 'text' => '今も取り組んでいる'],
            source: 'user_answer',
            state: 'confirmed',
            key: 'current_state_stage',
        );
        $goals->recordFact(
            $soccerContext,
            type: 'unknown',
            label: '現在地の観測方法',
            value: null,
            source: 'user_answer',
            state: 'unknown',
            key: 'measurement_method',
        );
        $soccerContext->update([
            'interaction_profile' => [
                'quick_answers' => 3,
                'text_answers' => 0,
                'skips' => 1,
                'answered' => 4,
                'preferred' => 'quick',
                'last_mode' => 'quick',
            ],
        ]);

        $this->guided($soccerUser, $soccerPlan, $soccerTask, 'completed', 'partly');
        $this->guided($soccerUser, $soccerPlan, $soccerTask, 'completed', 'learning_only');

        $salesUser = User::factory()->create();
        [$salesPlan, $salesTask] = $this->planTask(
            $salesUser,
            '車を月10台販売したい',
            'その他',
            '顧客と商談する',
        );
        $salesContext = $goals->createDraft('車を月10台販売したい', $salesUser->id);
        $goals->attachPlan($salesContext, $salesPlan);
        $goals->recordFact(
            $salesContext,
            type: 'driver',
            label: '現在地の観測方法',
            value: ['mode' => 'metric', 'text' => '回数・成功率などの数値'],
            source: 'user_answer',
            state: 'confirmed',
            key: 'measurement_method',
            metadata: ['measurement' => true],
        );
        $this->guided($salesUser, $salesPlan, $salesTask, 'prepared');

        $analysis = app(GoalPatternDemandService::class)->analyze('30');
        $patterns = collect($analysis['pattern_stats']);

        $sports = $patterns->firstWhere('key', 'sports');
        $sales = $patterns->firstWhere('key', 'sales');

        $this->assertNotNull($sports);
        $this->assertNotNull($sales);
        $this->assertSame(1, $sports['unique_identities']);
        $this->assertSame(2, $sports['guided_executions']);
        $this->assertSame(1, $sports['repeat_tasks']);
        $this->assertSame(1, $sports['measurement_unknowns']);
        $this->assertGreaterThan(0, $sports['opportunity_score']);
        $this->assertSame(1, $sales['guided_executions']);
        $this->assertSame(1, $sales['measurement_confirmed']);

        $signals = collect($analysis['signal_stats']);
        $this->assertNotNull($signals->firstWhere('key', 'current_state:active'));
        $this->assertNotNull($signals->firstWhere('key', 'measurement:unknown'));
        $this->assertNotNull($signals->firstWhere('key', 'measurement:metric'));

        $inputs = collect($analysis['input_stats']);
        $quick = $inputs->firstWhere('mode', 'quick');
        $this->assertNotNull($quick);
        $this->assertSame(1, $quick['contexts']);

        $this->assertDatabaseCount('goal_contexts', 2);
        $this->assertDatabaseCount('guided_executions', 3);
    }

    public function test_admin_surface_shows_aggregated_tool_discovery_signals_without_raw_goal_or_reflection_text(): void
    {
        $admin = User::factory()->create();
        config(['canovia.super_admin_user_id' => $admin->id]);

        [$plan, $task] = $this->planTask(
            $admin,
            '営業力を高める',
            'その他',
            '顧客と商談する',
        );

        $goals = app(GoalContextService::class);
        $context = $goals->createDraft('極秘の顧客名A社で販売成績を上げたい', $admin->id);
        $goals->attachPlan($context, $plan);
        $goals->recordFact(
            $context,
            type: 'current_state',
            label: '現在地',
            value: ['text' => 'A社では先月3台だったという非表示にしたい詳細'],
            source: 'user_answer',
            state: 'confirmed',
            key: 'current_state_summary',
        );
        $goals->recordFact(
            $context,
            type: 'driver',
            label: '現在地の観測方法',
            value: ['mode' => 'metric', 'text' => '回数・成功率などの数値'],
            source: 'user_answer',
            state: 'confirmed',
            key: 'measurement_method',
            metadata: ['measurement' => true],
        );

        GuidedExecution::create([
            'user_id' => $admin->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'status' => GuidedExecution::STATUS_COMPLETED,
            'prepare_request_id' => (string) Str::uuid(),
            'reflection_request_id' => (string) Str::uuid(),
            'intent' => '極秘の担当者名を含む商談方針',
            'outcome_rating' => 'partly',
            'actual_outcome' => '極秘の商談結果本文',
            'discoveries' => '極秘の学び本文',
            'next_adjustment' => '極秘の次回施策本文',
            'prepared_at' => now(),
            'reflected_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.goal_pattern_demand.index', ['period' => '30']))
            ->assertOk()
            ->assertSee('Goal Pattern Demand')
            ->assertSee('営業・販売')
            ->assertSee('観測方法: 数値')
            ->assertSee('営業・商談')
            ->assertSee('Human decision only')
            ->assertDontSee('極秘の顧客名A社で販売成績を上げたい')
            ->assertDontSee('A社では先月3台だったという非表示にしたい詳細')
            ->assertDontSee('極秘の商談結果本文')
            ->assertDontSee('極秘の学び本文');
    }

    public function test_opportunity_score_rewards_cross_user_demand_before_raw_repeat_volume(): void
    {
        $service = app(GoalPatternDemandService::class);
        $goals = app(GoalContextService::class);

        $userA = User::factory()->create();
        [$planA, $taskA] = $this->planTask($userA, 'サッカー上達', 'その他', '試合でプレーする');
        $contextA = $goals->createDraft('サッカー上達', $userA->id);
        $goals->attachPlan($contextA, $planA);
        $this->guided($userA, $planA, $taskA, 'completed');
        $this->guided($userA, $planA, $taskA, 'completed');
        $this->guided($userA, $planA, $taskA, 'completed');

        $userB = User::factory()->create();
        [$planB, $taskB] = $this->planTask($userB, '車の販売営業で成果を出す', 'その他', '顧客と商談する');
        $contextB = $goals->createDraft('車の販売営業で成果を出す', $userB->id);
        $goals->attachPlan($contextB, $planB);

        $userC = User::factory()->create();
        [$planC] = $this->planTask($userC, '車をもっと販売する', 'その他', '顧客へ提案する');
        $contextC = $goals->createDraft('車をもっと販売する', $userC->id);
        $goals->attachPlan($contextC, $planC);

        $patterns = collect($service->analyze('30')['pattern_stats']);
        $sports = $patterns->firstWhere('key', 'sports');
        $sales = $patterns->firstWhere('key', 'sales');

        $this->assertSame(1, $sports['unique_identities']);
        $this->assertSame(2, $sales['unique_identities']);
        $this->assertGreaterThanOrEqual(30, $sales['opportunity_score']);
        $this->assertLessThanOrEqual(100, $sports['opportunity_score']);
        $this->assertLessThanOrEqual(100, $sales['opportunity_score']);
    }

    public function test_admin_dashboard_links_to_goal_pattern_demand_and_non_admin_is_forbidden(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();
        config(['canovia.super_admin_user_id' => $admin->id]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Goal Pattern Demand')
            ->assertSee(route('admin.goal_pattern_demand.index'), false);

        $this->actingAs($other)
            ->get(route('admin.goal_pattern_demand.index'))
            ->assertForbidden();
    }

    public function test_pattern_classifier_is_deterministic_for_initial_unknown_goal_examples(): void
    {
        $service = app(GoalPatternDemandService::class);

        $this->assertSame('sports', $service->patternFor('サッカー上手くなりたい')['key']);
        $this->assertSame('sales', $service->patternFor('車の販売営業で月10台売りたい')['key']);
        $this->assertSame('study', $service->patternFor('応用情報に合格したい', '資格学習')['key']);
        $this->assertSame('development', $service->patternFor('新しいアプリを公開したい', '個人開発')['key']);
    }

    private function planTask(User $user, string $title, string $category, string $taskTitle): array
    {
        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title.'の計画',
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::create([
            'plan_id' => $plan->id,
            'title' => $taskTitle,
            'description' => $taskTitle.'を実行する',
            'next_action_note' => $taskTitle,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$plan, $task];
    }

    private function guided(
        User $user,
        Plan $plan,
        Task $task,
        string $status,
        ?string $outcome = null,
    ): GuidedExecution {
        return GuidedExecution::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'status' => $status,
            'prepare_request_id' => (string) Str::uuid(),
            'reflection_request_id' => $status === GuidedExecution::STATUS_COMPLETED ? (string) Str::uuid() : null,
            'intent' => $task->title.'で見るポイントを決める',
            'focus_points' => ['今回意識すること'],
            'observation_points' => ['結果を観察する'],
            'success_signal' => '学びが得られればよい',
            'outcome_rating' => $outcome,
            'actual_outcome' => $status === GuidedExecution::STATUS_COMPLETED ? '実行結果を記録した' : null,
            'next_adjustment' => $status === GuidedExecution::STATUS_COMPLETED ? '次回調整する' : null,
            'prepared_at' => now(),
            'reflected_at' => $status === GuidedExecution::STATUS_COMPLETED ? now() : null,
        ]);
    }
}
