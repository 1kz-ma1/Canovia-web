<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InitialPlanJsonReliabilityV387Test extends TestCase
{
    use RefreshDatabase;

    public function test_initial_plan_import_absorbs_priority_sequence_and_title_variation(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, 'Canoviaを事業として成功させる');

        $operations = [];
        for ($index = 1; $index <= 15; $index++) {
            $operations[] = [
                'type' => 'add_task',
                'client_ref' => 'task_'.$index,
                'title' => 'タスク'.$index,
                'description' => '完了条件'.$index,
                'estimated_minutes' => 60,
                'remaining_minutes' => 60,
                'progress_percent' => 0,
                'progress_reason' => '未着手',
                'status' => 'todo',
                // External AI may incorrectly use priority as an ordinal.
                'priority' => $index,
                'activation_cost' => min(5, max(1, $index % 5 + 1)),
            ];
        }
        $operations[] = [
            'type' => 'reorder_tasks',
            'items' => collect(range(1, 15))->map(fn ($index) => ['task_ref' => 'task_'.$index])->all(),
        ];

        $payload = [
            'schema_version' => '2.0',
            'flow' => 'plan_generation',
            'target_plan' => [
                'id' => $plan->id,
                // Same plan id is authoritative; title formatting must not block import.
                'title' => 'Canoviaを事業として成功させる ',
                'category' => '個人開発',
            ],
            'summary' => '初期計画',
            'operations' => $operations,
        ];

        $this->actingAs($user)
            ->post(route('plans.ai_task_assistant.import', $plan), [
                'tasks_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ])
            ->assertRedirect(route('plans.show', $plan));

        $tasks = Task::where('plan_id', $plan->id)->orderBy('sort_order')->get();
        $this->assertCount(15, $tasks);
        $this->assertSame('タスク1', $tasks->first()->title);
        $this->assertLessThanOrEqual(5, (int) $tasks->max('priority'));
        $this->assertGreaterThanOrEqual(1, (int) $tasks->min('priority'));
    }

    public function test_initial_plan_import_rejects_an_explicit_different_plan_id(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, '対象計画');

        $payload = [
            'schema_version' => '2.0',
            'flow' => 'plan_generation',
            'target_plan' => ['id' => $plan->id + 999, 'title' => '別計画'],
            'summary' => '初期計画',
            'operations' => [[
                'type' => 'add_task',
                'client_ref' => 'task_1',
                'title' => '最初のタスク',
                'description' => '完了条件',
                'estimated_minutes' => 30,
                'remaining_minutes' => 30,
                'progress_percent' => 0,
                'status' => 'todo',
                'priority' => 1,
                'activation_cost' => 1,
            ]],
        ];

        $this->actingAs($user)
            ->from(route('plans.ai_task_assistant.show', $plan))
            ->post(route('plans.ai_task_assistant.import', $plan), [
                'tasks_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ])
            ->assertRedirect(route('plans.ai_task_assistant.show', $plan))
            ->assertSessionHasErrors('tasks_json');

        $this->assertSame(0, Task::where('plan_id', $plan->id)->count());
    }

    public function test_initial_plan_screen_uses_native_server_owned_import_and_chat_error_area(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, '表示確認');

        $response = $this->actingAs($user)->get(route('plans.ai_task_assistant.show', $plan));

        $response->assertOk()
            ->assertSee('data-ai-plan-generation-import', false)
            ->assertDontSee('data-plan-title=', false)
            ->assertDontSee('別の計画向けの回答のようです。この画面の相談用文章から作った回答を貼り付けてください。');
    }

    public function test_development_first_use_import_returns_to_workspace_only_when_requested(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, '初めての開発');

        $this->actingAs($user)
            ->get(route('plans.ai_task_assistant.show', [
                'plan' => $plan,
                'return_to_workspace' => 1,
            ]))
            ->assertOk()
            ->assertSee('name="return_to_workspace" value="1"', false);

        $payload = [
            'schema_version' => '2.0',
            'flow' => 'plan_generation',
            'target_plan' => ['id' => $plan->id, 'title' => $plan->title],
            'summary' => '最初の開発タスク',
            'operations' => [[
                'type' => 'add_task',
                'client_ref' => 'task_1',
                'title' => '画面を1つ作る',
                'description' => '動作を確認する',
                'estimated_minutes' => 60,
                'remaining_minutes' => 60,
                'progress_percent' => 0,
                'status' => 'todo',
                'priority' => 1,
                'activation_cost' => 1,
            ]],
        ];

        $this->actingAs($user)
            ->post(route('plans.ai_task_assistant.import', $plan), [
                'return_to_workspace' => 1,
                'tasks_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ])
            ->assertRedirect(route('workspace.development.index', ['plan_id' => $plan->id]));

        $this->assertSame(1, Task::query()->where('plan_id', $plan->id)->count());
        $this->actingAs($user)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('画面を1つ作る')
            ->assertSee('data-development-import-confirmation', false)
            ->assertSee('AIが生成した初期タスクを登録しました。');

        // Retry after a lost PWA/iOS redirect: same Plan, same destination,
        // no duplicate Task and an accurate confirmation for this retry.
        $this->actingAs($user)
            ->post(route('plans.ai_task_assistant.import', $plan), [
                'return_to_workspace' => 1,
                'tasks_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ])
            ->assertRedirect(route('workspace.development.index', ['plan_id' => $plan->id]));

        $this->assertSame(1, Task::query()->where('plan_id', $plan->id)->count());
        $this->actingAs($user)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-development-import-confirmation', false)
            ->assertSee('重複登録せず、続きから開きました。');
    }

    public function test_return_to_workspace_does_not_change_non_development_plan_destinations(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, '生活の計画');
        $plan->update(['category' => '生活']);

        $payload = [
            'schema_version' => '2.0',
            'flow' => 'plan_generation',
            'target_plan' => ['id' => $plan->id, 'title' => $plan->title],
            'summary' => '初期タスク',
            'operations' => [[
                'type' => 'add_task',
                'client_ref' => 'task_1',
                'title' => '最初のタスクを実行',
                'estimated_minutes' => 30,
                'remaining_minutes' => 30,
                'progress_percent' => 0,
                'status' => 'todo',
                'priority' => 1,
                'activation_cost' => 1,
            ]],
        ];

        foreach ([1, 2] as $_retry) {
            $this->actingAs($user)
                ->post(route('plans.ai_task_assistant.import', $plan), [
                    'return_to_workspace' => 1,
                    'tasks_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ])
                ->assertRedirect(route('plans.show', $plan));
        }

        $this->assertSame(1, Task::query()->where('plan_id', $plan->id)->count());
    }

    private function plan(User $user, string $title): Plan
    {
        return Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => Str::uuid()->toString(),
            'title' => $title,
            'category' => '個人開発',
            'start_date' => today()->toDateString(),
            'deadline' => today()->addMonths(2)->toDateString(),
            'is_public' => false,
        ]);
    }
}
