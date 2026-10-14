<?php

namespace Tests\Feature;

use App\Models\GuidedExecution;
use App\Models\Plan;
use App\Models\StudyScopeCapture;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V6.5: candidate PR #513 exact-SHA Laravel HTTP integration without
 * creating Task models as fixtures. Never claim browser/iPhone E2E.
 */
final class Release513FirstActionWithoutSeedV65Test extends TestCase
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

    private function createEmptyPlan(User $user, string $title, string $category, string $workspace): Plan
    {
        $id = (string) Str::uuid();
        $this->actingAs($user)
            ->post(route('plans.store'), [
                'title' => $title, 'category' => $category,
                'create_request_id' => $id,
            ])
            ->assertSessionHasNoErrors();

        $plan = Plan::query()->where('creation_request_id', $id)->firstOrFail();
        $this->assertSame($user->id, $plan->user_id);
        $this->assertDatabaseMissing('tasks', ['plan_id' => $plan->id]);
        $this->actingAs($user)
            ->get(route($workspace, ['plan_id' => $plan->id]))
            ->assertOk();
        return $plan;
    }

    public function test_study_zero_task_plan_projects_first_task_directly_without_injected_task(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->createEmptyPlan(
            $user, '応用情報技術者試験 合格', '資格学習', 'workspace.study.index'
        );

        // Actual PR #513 behavior discovered in the first disposable CI run:
        // a recognizable AP title projects an executable Task even without
        // confirmed scope. Do not fabricate a missing-scope redirect.
        $first = $this->actingAs($user)
            ->post(route('plans.study_action.execute', $plan));
        $task = Task::query()->where('plan_id', $plan->id)->sole();
        $first->assertRedirect(route('plans.tasks.study_practice.show', [$plan, $task]));
        $this->assertSame((int) $plan->id, (int) $task->plan_id);
        $this->assertSame(0, (int) $task->progress_percent);

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk();
        $this->actingAs($user)
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk();

        // Repeated human action must not create a second Task.
        $this->actingAs($user)
            ->post(route('plans.study_action.execute', $plan))
            ->assertRedirect();
        $this->assertSame(1, Task::query()->where('plan_id', $plan->id)->count());
    }

    public function test_development_zero_task_plan_creates_first_task_by_owner_import_without_fixture(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->createEmptyPlan(
            $user, 'Webアプリを公開', '個人開発', 'workspace.development.index'
        );
        $this->actingAs($user)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('初期タスクを作る')
            ->assertSee(route('plans.ai_task_assistant.show', [
                'plan' => $plan, 'return_to_workspace' => 1,
            ]), false);
        $this->actingAs($user)
            ->get(route('plans.ai_task_assistant.show', [
                'plan' => $plan, 'return_to_workspace' => 1,
            ]))
            ->assertOk();

        // Candidate requires owner-supplied, schema-valid JSON; this is
        // not native AI creation, autonomous recommendation, or a zero-friction UX.
        $payload = [
            'schema_version' => '2.0',
            'flow' => 'plan_generation',
            'target_plan' => [
                'id' => $plan->id, 'title' => $plan->title,
                'category' => $plan->category,
            ],
            'summary' => '最初の検証作業',
            'operations' => [[
                'type' => 'add_task', 'client_ref' => 'task_1',
                'title' => 'ログイン画面を試す',
                'description' => 'ブラウザでログイン後の復帰を確認',
                'estimated_minutes' => 30, 'remaining_minutes' => 30,
                'progress_percent' => 0, 'status' => 'todo',
                'priority' => 1, 'activation_cost' => 1,
            ]],
        ];
        $post = [
            'tasks_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'return_to_workspace' => '1',
        ];
        $this->actingAs($user)
            ->post(route('plans.ai_task_assistant.import', $plan), $post)
            ->assertRedirect(route('workspace.development.index', ['plan_id' => $plan->id]))
            ->assertSessionHasNoErrors();

        $task = Task::query()->where('plan_id', $plan->id)->sole();
        $this->assertSame('ログイン画面を試す', $task->title);
        $this->actingAs($user)
            ->post(route('plans.ai_task_assistant.import', $plan), $post)
            ->assertRedirect(route('workspace.development.index', ['plan_id' => $plan->id]));
        $this->assertSame(1, Task::query()->where('plan_id', $plan->id)->count());
        $this->actingAs($user)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id]))
            ->assertOk()->assertSee('ログイン画面を試す');

        // Continue the first Task via actual owner-controlled prepare/reflect
        // routes, checking durable evidence without false automatic progress.
        $this->actingAs($user)
            ->post(route('plans.tasks.guided_execution.prepare', [$plan, $task]), [
                'prepare_request_id' => (string) Str::uuid(),
                'intent' => 'Safari上のログイン復帰を確認する',
            ])
            ->assertRedirect(route('plans.tasks.guided_execution.show', [$plan, $task]))
            ->assertSessionHasNoErrors();
        $execution = GuidedExecution::query()->where('task_id', $task->id)->sole();
        $this->actingAs($user)
            ->post(route('plans.tasks.guided_execution.reflect', [$plan, $task, $execution]), [
                'reflection_request_id' => (string) Str::uuid(),
                'outcome_rating' => 'partly',
                'actual_outcome' => '画面の遷移を記録できた',
                'next_adjustment' => '次は復帰動線を確認する',
            ])
            ->assertRedirect(route('plans.tasks.guided_execution.show', [$plan, $task]))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, TaskEvidence::query()->where('task_id', $task->id)->count());
        $this->assertSame(0, (int) $task->fresh()->progress_percent);
    }
}
