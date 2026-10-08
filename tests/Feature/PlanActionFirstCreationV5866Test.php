<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlanActionFirstCreationV5866Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled',
            'session.driver' => 'array',
        ]);
    }

    public function test_manual_form_promises_action_first_without_copy_json_as_required_step(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('plans.create.manual'))
            ->assertOk()
            ->assertSee('目標を保存して次の行動へ')
            ->assertSee('タスクやロードマップを先に完成させる必要はありません')
            ->assertDontSee('AIへ相談文をコピーして')
            ->assertDontSee('JSONを戻してロードマップを完成')
            ->assertDontSee('計画を作ってAIへ進む');

        $this->actingAs($user)
            ->get(route('plans.create.manual', ['workspace_mode' => 'study']))
            ->assertOk()
            ->assertSee('Planを作って学習 Workspaceへ')
            ->assertSee('data-plan-create-workspace-mode="study"', false);
    }

    public function test_generic_study_and_development_plans_enter_matching_workspace_without_manual_roadmap(): void
    {
        $user = User::factory()->create();
        $cases = [
            ['応用情報技術者試験 合格', '資格学習', 'workspace.study.index'],
            ['Canovia Webアプリを公開', '個人開発', 'workspace.development.index'],
        ];

        foreach ($cases as [$title, $category, $route]) {
            $requestId = (string) Str::uuid();
            $result = $this->actingAs($user)->post(route('plans.store'), [
                'title' => $title,
                'category' => $category,
                'create_request_id' => $requestId,
            ]);

            $plan = Plan::query()->where('creation_request_id', $requestId)->firstOrFail();
            $result->assertRedirect(route($route, ['plan_id' => $plan->id]));
            $this->assertDatabaseMissing('tasks', ['plan_id' => $plan->id]);

            // A browser retry opens the same Plan without task duplication.
            $this->actingAs($user)
                ->post(route('plans.store'), [
                    'title' => $title,
                    'category' => $category,
                    'create_request_id' => $requestId,
                ])
                ->assertRedirect(route($route, ['plan_id' => $plan->id]));
        }

        $this->assertDatabaseCount('plans', 2);
    }

    public function test_unspecified_or_creative_plan_does_not_force_wrong_workspace_or_ai_task_flow(): void
    {
        $user = User::factory()->create();

        foreach ([
            ['やりたいことを少しずつ進める', null, null],
            ['卒業制作の作品を完成させる', '制作活動', null],
            ['Canoviaを実装する', '個人開発', 'study'],
        ] as [$title, $category, $mode]) {
            $payload = [
                'title' => $title,
                'create_request_id' => (string) Str::uuid(),
            ];
            if ($category !== null) {
                $payload['category'] = $category;
            }
            if ($mode !== null) {
                $payload['workspace_mode'] = $mode;
            }

            $this->actingAs($user)->post(route('plans.store'), $payload)
                ->assertSessionHasNoErrors();
            $plan = Plan::query()->where('creation_request_id', $payload['create_request_id'])->firstOrFail();

            // Explicit study hint does not relabel a Development Plan.
            // Unsupported creative/general categories fall back to the Plan.
            $this->actingAs($user)->post(route('plans.store'), $payload)
                ->assertRedirect(route('plans.show', $plan));

            $this->actingAs($user)
                ->get(route('plans.show', $plan))
                ->assertOk()
                ->assertSee('最初の行動から始めましょう')
                ->assertSee('data-plan-optional-task-planning', false)
                ->assertDontSee('class="mb-8 page-card roadmap-shell', false);
        }

        $this->assertDatabaseCount('plans', 3);
        $this->assertDatabaseCount('tasks', 0);
    }
}
