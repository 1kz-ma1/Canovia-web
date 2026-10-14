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
 * Transplanted by a disposable CI job into the exact, unchanged PR #513 SHA.
 *
 * Route-level integration, NOT real browser/iPhone E2E. Fixtures explicitly
 * seed a Task after checking Task=0, and use a synthetic study assessment.
 * These gaps prevent automatic product P0 acceptance.
 */
final class Release513P0JourneyV64Test extends TestCase
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

    private function initialPlan(User $owner, string $title, string $category, string $workspace): Plan
    {
        $requestId = (string) Str::uuid();
        $this->actingAs($owner)->post(route('plans.store'), [
            'title' => $title,
            'category' => $category,
            'create_request_id' => $requestId,
        ])->assertRedirect(route($workspace, ['plan_id' => Plan::query()
            ->where('creation_request_id', $requestId)->firstOrFail()->id]))
          ->assertSessionHasNoErrors();

        $plan = Plan::query()->where('creation_request_id', $requestId)->firstOrFail();
        $this->assertSame((int) $owner->id, (int) $plan->user_id);
        $this->assertDatabaseMissing('tasks', ['plan_id' => $plan->id]);
        return $plan;
    }

    private function fixtureTask(Plan $plan, string $title): Task
    {
        // Deliberate acceptance gap: the candidate still needs the first
        // Task / executable action to be supplied by another step.
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => 'Synthetic free-path integration fixture',
            'next_action_note' => 'まず最初の作業を実行する',
            'estimated_minutes' => 45,
            'remaining_minutes' => 45,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);
    }

    public function test_study_plan_to_saved_assessment_and_human_confirmed_next_action(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->initialPlan($owner, '応用情報技術者 合格', '資格学習', 'workspace.study.index');
        $this->actingAs($owner)->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-study-workspace', false);
        $task = $this->fixtureTask($plan, 'ネットワークの確認');
        $session = [
            "study_practice.{$plan->id}.{$task->id}" => [
                'title' => 'DNS確認',
                'questions' => [[
                    'id' => 'q1',
                    'type' => 'text',
                    'prompt' => 'DNSの役割は何か',
                    'choices' => [],
                ]],
                'answers' => [],
                'evaluation_prompt' => null,
                'assessment' => null,
                'attempt_id' => null,
                'attempt_token' => (string) Str::uuid(),
            ],
        ];
        $this->actingAs($owner)
            ->withSession($session)
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()->assertSee('DNSの役割は何か');

        $this->actingAs($owner)->withSession($session)
            ->post(route('plans.tasks.study_practice.answers', [$plan, $task]), [
                'answers' => ['q1' => 'ドメイン名からIPアドレスを調べる'],
            ])->assertRedirect(route('plans.tasks.study_practice.show', [$plan, $task]));

        $assessment = [
            'flow' => 'study_assessment',
            'target_plan' => ['id' => $plan->id],
            'target_task' => ['id' => $task->id],
            'score_percent' => 75,
            'strengths' => ['DNS基本'],
            'weaknesses' => ['MXレコード'],
            'recommended_task_progress_percent' => 65,
            'evidence_summary' => 'DNSの名前解決は理解。メール配送を復習する',
            'next_action' => 'MXとCNAMEの違いを3問復習する',
        ];
        $this->actingAs($owner)
            ->post(route('plans.tasks.study_practice.assessment', [$plan, $task]), [
                'assessment_json' => json_encode($assessment, JSON_UNESCAPED_UNICODE),
            ])->assertRedirect(route('plans.tasks.study_practice.show', [$plan, $task]))
              ->assertSessionHasNoErrors();

        $attempt = StudyPracticeAttempt::query()->where('plan_id', $plan->id)
            ->where('task_id', $task->id)->sole();
        $this->assertSame(75, $attempt->score_percent);
        $this->assertNull($attempt->applied_at); // Never auto-apply AI assessment.
        $this->actingAs($owner)
            ->post(route('plans.tasks.study_practice.apply', [$plan, $task]), [
                'attempt_id' => $attempt->id,
                'request_hash' => $attempt->request_hash,
            ])->assertRedirect(route('plans.tasks.study_practice.show', [$plan, $task]));

        $this->assertSame(65, $task->fresh()->progress_percent);
        $this->assertSame('MXとCNAMEの違いを3問復習する', $task->fresh()->next_action_note);
        $this->assertNotNull($attempt->fresh()->applied_at);
        $this->actingAs($owner)
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()->assertSee('MXとCNAMEの違いを3問復習する');
        $this->actingAs($owner)->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk();
        $this->assertDatabaseCount('study_practice_attempts', 1);
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_development_plan_to_manual_reflection_and_reopened_next_action(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->initialPlan($owner, 'Webアプリを公開', '個人開発', 'workspace.development.index');
        $this->actingAs($owner)->get(route('workspace.development.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-development-local-create-action', false);
        $task = $this->fixtureTask($plan, 'ログイン画面を検証する');
        $this->actingAs($owner)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id]))
            ->assertOk()->assertSee($task->title);

        $this->actingAs($owner)
            ->post(route('plans.tasks.guided_execution.prepare', [$plan, $task]), [
                'prepare_request_id' => (string) Str::uuid(),
                'intent' => 'ログイン画面を端末で試す',
                'observation_points' => '再ログイン後も同じPlanに戻るか',
                'success_signal' => '復帰できれば成功',
            ])->assertRedirect(route('plans.tasks.guided_execution.show', [$plan, $task]))
              ->assertSessionHasNoErrors();

        $execution = GuidedExecution::query()->where('task_id', $task->id)->sole();
        $this->actingAs($owner)
            ->post(route('plans.tasks.guided_execution.reflect', [$plan, $task, $execution]), [
                'reflection_request_id' => (string) Str::uuid(),
                'outcome_rating' => 'partly',
                'actual_outcome' => 'ログイン後に操作を再開できた',
                'observations' => 'メニューが分かりづらい',
                'discoveries' => '戻り先の表示が重要',
                'next_adjustment' => '次にホーム導線を修正する',
            ])->assertRedirect(route('plans.tasks.guided_execution.show', [$plan, $task]))
              ->assertSessionHasNoErrors();

        $evidence = TaskEvidence::query()->where('task_id', $task->id)->sole();
        $this->assertSame('guided_execution_reflected', $evidence->type);
        $this->assertSame('completed', $execution->fresh()->status);
        $this->assertSame(0, $task->fresh()->progress_percent);
        $this->actingAs($owner)
            ->get(route('plans.tasks.guided_execution.show', [$plan, $task]))
            ->assertOk()->assertSee('ログイン後に操作を再開できた');
        $this->actingAs($owner)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id]))
            ->assertOk()->assertSee($task->title);
        $this->assertDatabaseCount('task_evidences', 1);
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_owner_isolation_after_stored_study_and_development_work(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $stranger = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->initialPlan($owner, 'Webアプリを公開', '個人開発', 'workspace.development.index');
        $task = $this->fixtureTask($plan, '作業を記録する');

        $this->actingAs($stranger)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id]))
            ->assertNotFound();
        $this->actingAs($stranger)
            ->post(route('plans.tasks.guided_execution.prepare', [$plan, $task]), [
                'prepare_request_id' => (string) Str::uuid(), 'intent' => '他人の作業',
            ])->assertForbidden();

        $this->assertDatabaseCount('guided_executions', 0);
        $this->assertDatabaseCount('task_evidences', 0);
    }
}
