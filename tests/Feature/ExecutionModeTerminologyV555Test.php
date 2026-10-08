<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\ExecutionModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExecutionModeTerminologyV555Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'session.driver' => 'array',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_execution_picker_uses_mode_vocabulary_instead_of_workspace_vocabulary(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $this->task($this->plan($user, 'Study', '資格学習'), '過去問を解く');
        $this->task($this->plan($user, 'Dev', '個人開発'), 'APIを実装する');

        $response = $this->actingAs($user)->get(route('navigation.index'));

        $response
            ->assertOk()
            ->assertSee('CHOOSE MODE')
            ->assertSee('実行タイプ')
            ->assertDontSee('CHOOSE WORKSPACE')
            ->assertDontSee('Execution Workspace')
            ->assertDontSee('SAME WORKSPACE')
            ->assertDontSee('このTaskをWorkspaceへ引き継ぐ')
            ->assertDontSee('このWorkspaceの全Planから選ぶ');

        $view = file_get_contents(resource_path('views/navigation/index.blade.php'));
        $this->assertStringNotContainsString('Workspace', $view);
    }

    public function test_selected_execution_mode_is_presented_as_a_mode_and_scopes_plan_choices(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $devA = $this->plan($user, 'Dev A', '個人開発');
        $this->task($devA, 'APIを実装する');

        $devB = $this->plan($user, 'Dev B', 'ゲーム開発');
        $this->task($devB, 'UIを実装する');

        $response = $this->actingAs($user)->get(route('navigation.index', [
            'plan_id' => $devA->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('開発モード')
            ->assertSee('aria-label="Execution Modeを切り替える"', false)
            ->assertSee('aria-label="この実行タイプのPlan"', false)
            ->assertSee('この実行タイプの全Planから選ぶ')
            ->assertDontSee('開発Workspace');
    }

    public function test_specialized_handoff_labels_name_the_actual_operation_without_changing_routes(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $service = app(ExecutionModeService::class);

        $studyPlan = $this->plan($user, 'Study', '資格学習');
        $studyTask = $this->task($studyPlan, 'ネットワークの過去問を解く');

        $devPlan = $this->plan($user, 'Dev', '個人開発');
        $devTask = $this->task($devPlan, 'APIを実装する');

        $careerPlan = $this->plan($user, 'Career', '就活');
        $careerTask = $this->task($careerPlan, '応募準備をする');

        $study = $service->actionFor($studyPlan, $studyTask);
        $development = $service->actionFor($devPlan, $devTask);
        $career = $service->actionFor($careerPlan, $careerTask);

        $this->assertSame('学習Activityで進める', $study['label']);
        $this->assertSame('study_activity', $study['action_id']);
        $this->assertSame('plans.tasks.study_activity.show', $study['route_name']);

        $this->assertSame('開発フローで進める', $development['label']);
        $this->assertSame('execution_orchestration', $development['action_id']);
        $this->assertSame('plans.tasks.execution_orchestration.show', $development['route_name']);

        $this->assertSame('Career管理で進める', $career['label']);
        $this->assertSame('career_workspace', $career['action_id']);
        $this->assertSame('plans.career.index', $career['route_name']);
    }

    public function test_global_navigation_keeps_overview_separate_from_activity_mode(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $response = $this->actingAs($user)->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('data-navigation-shell="global"', false)
            ->assertSee('data-canovia-nav-key="mobile-workspace"', false)
            ->assertDontSee('data-workspace-mode-bar', false);
    }

    private function plan(User $user, string $title, string $category): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }
}
