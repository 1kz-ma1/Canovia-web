<?php

namespace Tests\Feature;

use App\Intelligence\Development\DevelopmentAdaptiveActionService;
use App\Models\GuidedExecution;
use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Services\StudyExamConvergencePolicyService;
use App\Services\StudyTaskProgressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PR #513 exact-commit HTTP route and policy probes. The source is transplanted
 * into a disposable checkout. No live AI, browser/device or release GO.
 *
 * Key distinction: provider suggestion != Canovia authoritative Next Action;
 * reflection evidence != an owner-authorized change to a Development Task.
 */
final class Release513EvidenceNextActionV66Test extends TestCase
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

    private function plan(User $owner, string $name, string $category): Plan
    {
        $requestId = (string) Str::uuid();
        $this->actingAs($owner)
            ->post(route('plans.store'), [
                'title' => $name,
                'category' => $category,
                'create_request_id' => $requestId,
            ])->assertSessionHasNoErrors();

        return Plan::query()->where('creation_request_id', $requestId)->sole();
    }

    public function test_study_weakness_suggestion_and_phase_override_are_distinct_durable_facts(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, '応用情報技術者合格', '資格学習');

        // The actual owner action projects a Task from a zero-Task Plan;
        // no Task::create fixture nor real external AI provider.
        $this->assertDatabaseMissing('tasks', ['plan_id' => $plan->id]);
        $this->actingAs($owner)->post(route('plans.study_action.execute', $plan))
            ->assertRedirect();
        $task = Task::query()->where('plan_id', $plan->id)->sole();

        $session = [
            "study_practice.{$plan->id}.{$task->id}" => [
                'title' => 'DNSの理解度確認',
                'questions' => [[
                    'id' => 'q1',
                    'type' => 'text',
                    'prompt' => 'DNSでMXレコードが使われる用途',
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
                'answers' => ['q1' => 'メール配送先サーバーの指定'],
            ])
            ->assertRedirect(route('plans.tasks.study_practice.show', [$plan, $task]));

        $suggested = 'MXとCNAMEの違いを3問復習する';
        $this->actingAs($owner)->post(
            route('plans.tasks.study_practice.assessment', [$plan, $task]),
            ['assessment_json' => json_encode([
                'flow' => 'study_assessment',
                'target_plan' => ['id' => $plan->id],
                'target_task' => ['id' => $task->id],
                'score_percent' => 75,
                'strengths' => ['名前解決'],
                'weaknesses' => ['MXレコード'],
                'recommended_task_progress_percent' => 65,
                'evidence_summary' => 'メール配送の知識をもう少し確認する',
                'next_action' => $suggested,
            ], JSON_UNESCAPED_UNICODE)],
        )->assertSessionHasNoErrors();

        $attempt = StudyPracticeAttempt::query()->where('plan_id', $plan->id)
            ->where('task_id', $task->id)->sole();
        $this->assertSame($suggested, $attempt->next_action);
        $this->assertNull($attempt->applied_at);
        $this->actingAs($owner)
            ->post(route('plans.tasks.study_practice.apply', [$plan, $task]), [
                'attempt_id' => $attempt->id,
                'request_hash' => $attempt->request_hash,
            ])->assertSessionHasNoErrors();

        $this->assertSame($suggested, $attempt->fresh()->next_action);
        $this->assertNotNull($attempt->fresh()->applied_at);
        $this->assertSame(65, (int) $task->fresh()->progress_percent);
        $this->assertSame(
            '総合演習で全体成績を再確認する',
            $task->fresh()->next_action_note,
        );
        $this->assertSame(1, Task::query()->where('plan_id', $plan->id)->count());

        // Policy scenario matrix: for the SAME recorded attempt, a human
        // inspection of the declared phase changes the candidate next step.
        // These are explicit controlled phases, NOT proof the live classifier
        // selected a weakness phase for this learner.
        $attempts = StudyPracticeAttempt::query()
            ->where('task_id', $task->id)->get();
        $policy = app(StudyTaskProgressionService::class);
        $weakness = $policy->resolve($plan, $task->fresh(), $attempts, [
            'phase' => StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT,
        ]);
        $general = $policy->resolve($plan, $task->fresh(), $attempts, [
            'phase' => StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
        ]);
        $exam = $policy->resolve($plan, $task->fresh(), $attempts, [
            'phase' => StudyExamConvergencePolicyService::PHASE_EXAM_MODE,
        ]);
        $this->assertSame('reinforce_current', $weakness['kind']);
        $this->assertSame('general_practice_return', $general['kind']);
        $this->assertSame('exam_mode', $exam['kind']);

        $this->actingAs($owner)
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()->assertSee($suggested);
    }

    public function test_development_reflection_is_durable_but_not_promoted_to_unapproved_next_task(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, 'Webアプリを公開', '個人開発');
        $this->assertDatabaseMissing('tasks', ['plan_id' => $plan->id]);

        // Owner-provided import is the real PR #513 initial-task route.
        $this->actingAs($owner)
            ->post(route('plans.ai_task_assistant.import', $plan), [
                'return_to_workspace' => '1',
                'tasks_json' => json_encode([
                    'schema_version' => '2.0',
                    'flow' => 'plan_generation',
                    'target_plan' => [
                        'id' => $plan->id,
                        'title' => $plan->title,
                        'category' => $plan->category,
                    ],
                    'summary' => '公開前にログインを検証する',
                    'operations' => [[
                        'type' => 'add_task',
                        'client_ref' => 'first_action',
                        'title' => 'ログイン復帰を実機で調べる',
                        'description' => 'ログイン後の復帰状態を調べる',
                        'estimated_minutes' => 40,
                        'remaining_minutes' => 40,
                        'progress_percent' => 0,
                        'status' => 'todo',
                        'priority' => 1,
                        'activation_cost' => 1,
                    ]],
                ], JSON_UNESCAPED_UNICODE),
            ])->assertSessionHasNoErrors();
        $task = Task::query()->where('plan_id', $plan->id)->sole();
        $before = app(DevelopmentAdaptiveActionService::class)->evaluate($plan);
        $this->assertNotNull($before->primaryAction());

        $this->actingAs($owner)
            ->post(route('plans.tasks.guided_execution.prepare', [$plan, $task]), [
                'prepare_request_id' => (string) Str::uuid(),
                'intent' => 'ログイン復帰を確認する',
            ])->assertSessionHasNoErrors();
        $execution = GuidedExecution::query()->where('task_id', $task->id)->sole();
        $newAction = '次はログアウトからの再ログイン導線を修正する';
        $payload = [
            'reflection_request_id' => (string) Str::uuid(),
            'outcome_rating' => 'partly',
            'actual_outcome' => '画面は開くが一部の状態が復帰しない',
            'observations' => 'タブの表示が元に戻る',
            'discoveries' => 'セッション復帰を検査する必要がある',
            'next_adjustment' => $newAction,
        ];
        $this->actingAs($owner)
            ->post(route('plans.tasks.guided_execution.reflect', [$plan, $task, $execution]), $payload)
            ->assertSessionHasNoErrors();

        $evidence = TaskEvidence::query()->where('task_id', $task->id)->sole();
        $this->assertSame('guided_execution_reflected', $evidence->type);
        $this->assertSame($newAction, data_get($evidence->metadata, 'next_adjustment'));
        $this->assertSame(0, (int) $task->fresh()->progress_percent);
        $this->assertSame(1, Task::query()->where('plan_id', $plan->id)->count());

        $after = app(DevelopmentAdaptiveActionService::class)->evaluate($plan);
        $this->assertNotNull($after->primaryAction());
        // The Development release policy does not convert a self-report into
        // a verified GitHub event or automatically apply its textual next step.
        $this->assertNotSame($newAction, (string) $after->primaryAction()->title);
        $this->assertSame(
            (string) $before->primaryAction()->kind,
            (string) $after->primaryAction()->kind,
        );
        $this->actingAs($owner)->get(
            route('plans.tasks.guided_execution.show', [$plan, $task])
        )->assertOk()->assertSee($newAction);
        $this->actingAs($owner)->get(
            route('workspace.development.index', ['plan_id' => $plan->id])
        )->assertOk()->assertSee($task->title);

        // Identical retry is idempotent; the changed next_adjustment is still
        // a stored suggestion, not a second Evidence or new Task.
        $this->actingAs($owner)
            ->post(route('plans.tasks.guided_execution.reflect', [$plan, $task, $execution]), $payload)
            ->assertSessionHasNoErrors();
        $this->assertSame(1, TaskEvidence::query()->where('task_id', $task->id)->count());
        $this->assertSame(1, Task::query()->where('plan_id', $plan->id)->count());
    }
}
