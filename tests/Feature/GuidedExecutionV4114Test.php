<?php

namespace Tests\Feature;

use App\Models\GuidedExecution;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Services\EvidenceProgressService;
use App\Services\ExecutionActionPolicyService;
use App\Services\PlanToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuidedExecutionV4114Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_real_world_task_prefers_guided_execution_over_timer(): void
    {
        [$user, $plan, $task] = $this->scenario(
            '営業成績を上げる',
            'その他',
            '顧客と商談する',
            '見積前に購入時期を確認し、相手の反応を見る',
        );

        $tools = app(PlanToolService::class)->forTask($plan, $task, true, $user);
        $guided = collect($tools)->firstWhere('id', 'guided_execution');
        $primary = app(ExecutionActionPolicyService::class)->primary($tools);

        $this->assertNotNull($guided);
        $this->assertTrue((bool) $guided['recommended']);
        $this->assertSame('guided_execution', $primary['id']);
        $this->assertFalse($primary['is_fallback']);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('方針を決めて実行する')
            ->assertDontSee('集中タイマーで進める');

        $this->actingAs($user)
            ->get(route('plans.show', $plan))
            ->assertOk()
            ->assertSee('方針を決めて実行する');
    }

    public function test_focus_task_keeps_timer_primary_while_guided_execution_remains_available(): void
    {
        [$user, $plan, $task] = $this->scenario(
            '卒業制作',
            'その他',
            '方向性を考える',
            '次の実装候補を整理する',
        );

        $tools = app(PlanToolService::class)->forTask($plan, $task, true, $user);
        $guided = collect($tools)->firstWhere('id', 'guided_execution');
        $primary = app(ExecutionActionPolicyService::class)->primary($tools);

        $this->assertNotNull($guided);
        $this->assertFalse((bool) $guided['recommended']);
        $this->assertSame('timer', $primary['id']);
        $this->assertTrue($primary['is_fallback']);

        $this->actingAs($user)
            ->get(route('plans.show', $plan))
            ->assertOk()
            ->assertSee('集中タイマーで進める')
            ->assertSee('実行前後を一緒に整理');
    }

    public function test_specialized_study_action_still_wins_over_guided_execution(): void
    {
        [$user, $plan, $task] = $this->scenario(
            'TOEIC 800点',
            '資格学習',
            'TOEIC英単語を暗記する',
            '頻出語彙を単語帳で覚える',
        );

        $tools = app(PlanToolService::class)->forTask($plan, $task, true, $user);
        $primary = app(ExecutionActionPolicyService::class)->primary($tools);

        $this->assertContains($primary['id'], ['study_activity', 'ai_practice']);
        $this->assertNotSame('guided_execution', $primary['id']);
        $this->assertFalse(collect($tools)->contains(fn ($tool) => ($tool['id'] ?? null) === 'guided_execution'));
    }

    public function test_before_action_preparation_creates_no_evidence_and_no_work_session(): void
    {
        [$user, $plan, $task] = $this->scenario(
            'サッカー上達',
            'その他',
            'チーム練習に参加する',
            'ファーストタッチを意識して練習する',
        );

        $progressBefore = $task->progress_percent;

        $this->actingAs($user)
            ->post(route('plans.tasks.guided_execution.prepare', [$plan, $task]), [
                'prepare_request_id' => (string) Str::uuid(),
                'intent' => '今日はファーストタッチを重点的に見る',
                'focus_points' => "トラップを身体から離しすぎない\n次のプレーへ早く移る",
                'observation_points' => "強いパスでも安定するか\n右足と左足で差があるか",
                'success_signal' => '強いパスで崩れる条件が分かれば学びあり',
            ])
            ->assertRedirect(route('plans.tasks.guided_execution.show', [$plan, $task]))
            ->assertSessionHasNoErrors();

        $execution = GuidedExecution::firstOrFail();

        $this->assertSame(GuidedExecution::STATUS_PREPARED, $execution->status);
        $this->assertSame(['トラップを身体から離しすぎない', '次のプレーへ早く移る'], $execution->focus_points);
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('work_sessions', 0);
        $this->assertSame($progressBefore, $task->fresh()->progress_percent);

        $this->actingAs($user)
            ->get(route('plans.tasks.guided_execution.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('ここからはCanoviaを閉じても大丈夫')
            ->assertSee('Timerを回す必要はありません')
            ->assertSee('どうだった？');
    }

    public function test_structured_reflection_creates_evidence_without_changing_task_progress(): void
    {
        [$user, $plan, $task] = $this->scenario(
            '営業力を上げる',
            'その他',
            '顧客と商談する',
            '見積後の反応と失注理由を確認する',
        );

        $prepareId = (string) Str::uuid();
        $this->actingAs($user)->post(route('plans.tasks.guided_execution.prepare', [$plan, $task]), [
            'prepare_request_id' => $prepareId,
            'intent' => '購入時期を見積前に確認する',
            'focus_points' => '相手の購入時期を先に確認する',
            'observation_points' => "見積まで進んだか\n断られた理由",
            'success_signal' => '成約しなくても理由が分かれば学びあり',
        ])->assertSessionHasNoErrors();

        $execution = GuidedExecution::firstOrFail();
        $progressBefore = $task->progress_percent;
        $reflectionId = (string) Str::uuid();

        $this->actingAs($user)
            ->post(route('plans.tasks.guided_execution.reflect', [$plan, $task, $execution]), [
                'reflection_request_id' => $reflectionId,
                'outcome_rating' => 'partly',
                'actual_outcome' => '見積提示まで進んだが、購入時期は来月だった',
                'observations' => '価格ではなく購入時期が今月成約しない主因だった',
                'discoveries' => 'クロージング前に購入時期を確認した方が商談の見通しを立てやすい',
                'next_adjustment' => '次回は商談序盤で購入希望時期を確認する',
            ])
            ->assertRedirect(route('plans.tasks.guided_execution.show', [$plan, $task]))
            ->assertSessionHasNoErrors();

        $execution->refresh();
        $evidence = TaskEvidence::firstOrFail();

        $this->assertSame(GuidedExecution::STATUS_COMPLETED, $execution->status);
        $this->assertSame($evidence->id, $execution->task_evidence_id);
        $this->assertSame('guided_execution_reflected', $evidence->type);
        $this->assertSame(0.70, $evidence->confidence);
        $this->assertSame('structured_self_reflection', data_get($evidence->metadata, 'evidence_strength'));
        $this->assertSame('実行振り返り', $evidence->typeLabel());
        $this->assertStringContainsString('購入時期を見積前に確認する', $evidence->summary());

        $this->assertSame($progressBefore, $task->fresh()->progress_percent);
        $this->assertNull(app(EvidenceProgressService::class)->recommendPercent($task, $evidence));
        $this->assertDatabaseCount('work_sessions', 0);
    }

    public function test_reflection_is_idempotent_and_does_not_duplicate_evidence(): void
    {
        [$user, $plan, $task] = $this->scenario(
            'プレゼン改善',
            'その他',
            '顧客向けにプレゼンする',
            '相手の質問を観察する',
        );

        $this->actingAs($user)->post(route('plans.tasks.guided_execution.prepare', [$plan, $task]), [
            'prepare_request_id' => (string) Str::uuid(),
            'intent' => '説明の分かりにくい箇所を見つける',
        ]);

        $execution = GuidedExecution::firstOrFail();
        $reflectionId = (string) Str::uuid();
        $payload = [
            'reflection_request_id' => $reflectionId,
            'outcome_rating' => 'learning_only',
            'actual_outcome' => '料金体系について質問が集中した',
            'observations' => '3人中2人が料金の違いを質問した',
        ];

        $this->actingAs($user)
            ->post(route('plans.tasks.guided_execution.reflect', [$plan, $task, $execution]), $payload)
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('plans.tasks.guided_execution.reflect', [$plan, $task, $execution->fresh()]), $payload)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('task_evidences', 1);
        $this->assertDatabaseCount('guided_executions', 1);
    }

    public function test_cancelled_preparation_produces_no_evidence(): void
    {
        [$user, $plan, $task] = $this->scenario(
            '体力を上げる',
            'その他',
            'ランニングする',
            'フォームを意識して走る',
        );

        $this->actingAs($user)->post(route('plans.tasks.guided_execution.prepare', [$plan, $task]), [
            'prepare_request_id' => (string) Str::uuid(),
            'intent' => 'フォームを確認する',
        ]);

        $execution = GuidedExecution::firstOrFail();

        $this->actingAs($user)
            ->post(route('plans.tasks.guided_execution.cancel', [$plan, $task, $execution]))
            ->assertRedirect(route('plans.tasks.guided_execution.show', [$plan, $task]));

        $this->assertSame(GuidedExecution::STATUS_CANCELLED, $execution->fresh()->status);
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_another_user_cannot_open_or_reflect_someone_elses_execution(): void
    {
        [$owner, $plan, $task] = $this->scenario(
            'サッカー上達',
            'その他',
            '試合に出る',
            'ポジショニングを観察する',
        );

        $this->actingAs($owner)->post(route('plans.tasks.guided_execution.prepare', [$plan, $task]), [
            'prepare_request_id' => (string) Str::uuid(),
            'intent' => '守備時の立ち位置を見る',
        ]);

        $execution = GuidedExecution::firstOrFail();
        $other = User::factory()->create();

        $this->actingAs($other)
            ->get(route('plans.tasks.guided_execution.show', [$plan, $task]))
            ->assertForbidden();

        $this->actingAs($other)
            ->post(route('plans.tasks.guided_execution.reflect', [$plan, $task, $execution]), [
                'reflection_request_id' => (string) Str::uuid(),
                'outcome_rating' => 'partly',
                'actual_outcome' => '他人の記録',
            ])
            ->assertForbidden();

        $this->assertSame(GuidedExecution::STATUS_PREPARED, $execution->fresh()->status);
        $this->assertDatabaseCount('task_evidences', 0);
    }

    private function scenario(
        string $planTitle,
        string $category,
        string $taskTitle,
        string $description,
    ): array {
        $user = User::factory()->create();

        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $planTitle,
            'description' => $planTitle.'の計画',
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
            'description' => $description,
            'next_action_note' => $description,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
