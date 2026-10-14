<?php

namespace Tests\Feature;

use App\Models\GuidedExecution;
use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V6.8 Free user HTTP journeys at the current development main:
 * screen -> POST -> persisted action -> next runnable screen.
 * Not a real browser, live provider, TestFlight or human UX study.
 */
final class DevelopmentFirstActionJourneyV68Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'session.driver' => 'array',
            'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    private function plan(User $owner, string $title, string $category): Plan
    {
        $requestId = (string) Str::uuid();
        $this->actingAs($owner)->post(route('plans.store'), [
            'title' => $title,
            'category' => $category,
            'create_request_id' => $requestId,
        ])->assertSessionHasNoErrors();

        return Plan::query()->where('creation_request_id', $requestId)->sole();
    }

    private function actionTask(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => 'Synthetic integration action',
            'next_action_note' => '最初の作業を確認する',
            'estimated_minutes' => 45,
            'remaining_minutes' => 45,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);
    }

    public function test_development_reflection_to_confirmation_to_next_preparation_preserves_truth(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, 'ログイン復帰を改善', '個人開発');
        $task = $this->actionTask($plan, 'ログインの問題を調べる');
        $next = 'ログアウト後の復帰先を修正する';

        // Start with a real form route and a real Guided Execution row.
        $this->actingAs($owner)->get(route('plans.tasks.guided_execution.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('data-guided-execution-prepare', false);
        $this->actingAs($owner)->post(route('plans.tasks.guided_execution.prepare', [$plan, $task]), [
            'prepare_request_id' => (string) Str::uuid(),
            'intent' => 'ログイン後の復帰を試す',
        ])->assertSessionHasNoErrors();
        $first = GuidedExecution::query()->where('task_id', $task->id)->sole();

        $this->actingAs($owner)->post(route('plans.tasks.guided_execution.reflect', [$plan, $task, $first]), [
            'reflection_request_id' => (string) Str::uuid(),
            'outcome_rating' => 'partly',
            'actual_outcome' => '一部の画面状態が復帰しない',
            'next_adjustment' => $next,
        ])->assertSessionHasNoErrors();

        $this->actingAs($owner)->get(route('plans.tasks.guided_execution.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('data-reflection-next-action', false)
            ->assertSee('この内容を次のActionに反映する')
            ->assertSee(hash('sha256', '最初の作業を確認する'));
        $this->assertSame('最初の作業を確認する', $task->fresh()->next_action_note);

        $this->actingAs($owner)->post(
            route('plans.tasks.guided_execution.confirm_next_action', [$plan, $task, $first]),
            ['expected_next_action_hash' => hash('sha256', '最初の作業を確認する')],
        )->assertRedirect(route('plans.tasks.guided_execution.show', [$plan, $task]))
            ->assertSessionHasNoErrors();

        $this->assertSame($next, $task->fresh()->next_action_note);
        $this->assertSame(20, $task->fresh()->progress_percent);
        $this->assertSame('doing', $task->fresh()->status);
        $this->assertSame(1, TaskEvidence::query()->where('task_id', $task->id)->count());

        // A fresh GET displays the adopted next action and an actionable
        // anchor to the preparation form whose intent is already filled.
        $this->actingAs($owner)->get(route('plans.tasks.guided_execution.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('data-reflection-next-action-adopted', false)
            ->assertSee('data-reflection-next-action-start', false)
            ->assertSee('href="#guided-execution-prepare"', false)
            ->assertSee('id="guided-execution-prepare"', false)
            ->assertSee($next);
        $this->actingAs($owner)->post(route('plans.tasks.guided_execution.prepare', [$plan, $task]), [
            'prepare_request_id' => (string) Str::uuid(),
            'intent' => $next,
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, GuidedExecution::query()->where('task_id', $task->id)->count());
        $this->assertSame(
            1,
            TaskEvidence::query()->where('task_id', $task->id)->count(),
            'Preparing the next action must not create evidence until a reflection is submitted.',
        );
        $this->actingAs($owner)->get(route('plans.tasks.guided_execution.show', [$plan, $task]))
            ->assertOk()->assertSee($next);
        $this->assertSame(20, $task->fresh()->progress_percent);
        $this->assertSame(1, Task::query()->where('plan_id', $plan->id)->count());
    }

    public function test_long_reflection_remains_evidence_and_cannot_be_adopted(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, '開発の復帰を改善', '個人開発');
        $task = $this->actionTask($plan, 'ログイン確認');
        $this->actingAs($owner)->post(route('plans.tasks.guided_execution.prepare', [$plan, $task]), [
            'prepare_request_id' => (string) Str::uuid(),
            'intent' => '原因を調査する',
        ])->assertSessionHasNoErrors();

        $execution = GuidedExecution::query()->where('task_id', $task->id)->sole();
        $this->actingAs($owner)->post(route('plans.tasks.guided_execution.reflect', [$plan, $task, $execution]), [
            'reflection_request_id' => (string) Str::uuid(),
            'outcome_rating' => 'partly',
            'actual_outcome' => '修正事項が多数見つかった',
            'next_adjustment' => str_repeat('あ', 1001),
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, TaskEvidence::query()->where('task_id', $task->id)->count());
        $this->actingAs($owner)->get(route('plans.tasks.guided_execution.show', [$plan, $task]))
            ->assertOk()->assertSee('data-reflection-next-action', false)
            ->assertDontSee('data-reflection-next-action-start', false)
            ->assertSee('長すぎる提案');
        $this->actingAs($owner)->post(
            route('plans.tasks.guided_execution.confirm_next_action', [$plan, $task, $execution]),
            ['expected_next_action_hash' => hash('sha256', '最初の作業を確認する')],
        )->assertStatus(422);
        $this->assertSame('最初の作業を確認する', $task->fresh()->next_action_note);
    }

    public function test_study_result_explains_actual_learning_phase_without_rewriting_assessment(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, '応用情報技術者合格', '資格学習');
        $task = $this->actionTask($plan, 'ネットワークの理解度を確認する');

        $session = [
            "study_practice.{$plan->id}.{$task->id}" => [
                'title' => 'DNS理解度確認',
                'questions' => [[
                    'id' => 'q1',
                    'type' => 'text',
                    'prompt' => 'MXレコードの役割を説明する',
                    'choices' => [],
                ]],
                'answers' => [],
                'evaluation_prompt' => null,
                'assessment' => null,
                'attempt_id' => null,
                'attempt_token' => (string) Str::uuid(),
            ],
        ];
        $this->actingAs($owner)->withSession($session)
            ->post(route('plans.tasks.study_practice.answers', [$plan, $task]), [
                'answers' => ['q1' => 'メール配送サーバーの指定'],
            ])->assertRedirect(route('plans.tasks.study_practice.show', [$plan, $task]));

        $proposal = 'MXとCNAMEの違いを3問復習する';
        $this->actingAs($owner)->post(route('plans.tasks.study_practice.assessment', [$plan, $task]), [
            'assessment_json' => json_encode([
                'flow' => 'study_assessment',
                'target_plan' => ['id' => $plan->id],
                'target_task' => ['id' => $task->id],
                'score_percent' => 75,
                'strengths' => ['DNS基礎'],
                'weaknesses' => ['MX'],
                'recommended_task_progress_percent' => 65,
                'evidence_summary' => 'MXの再復習が必要',
                'next_action' => $proposal,
            ], JSON_UNESCAPED_UNICODE),
        ])->assertSessionHasNoErrors();

        $attempt = StudyPracticeAttempt::query()->where('plan_id', $plan->id)
            ->where('task_id', $task->id)->sole();
        $this->assertSame($proposal, $attempt->next_action);
        $this->assertNull($attempt->applied_at);

        $this->actingAs($owner)->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('data-study-next-action-reason', false)
            ->assertSee('なぜこの学習方針？')
            ->assertSee('採点結果の提案')
            ->assertSee($proposal);
        $this->assertSame('最初の作業を確認する', $task->fresh()->next_action_note);
        $this->assertNull($attempt->fresh()->applied_at);
    }
}
