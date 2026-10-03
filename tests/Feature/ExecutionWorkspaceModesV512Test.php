<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\ExecutionModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExecutionWorkspaceModesV512Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_execution_modes_follow_existing_specialized_capability_boundaries(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $study = $this->plan($user, 'AP合格', '資格学習');
        $development = $this->plan($user, 'Canovia開発', '個人開発');
        $career = $this->plan($user, '就職活動', '就活');
        $creative = $this->plan($user, '卒業制作', '制作活動');
        $genericLearning = $this->plan($user, '英語を学ぶ', '学習');

        $service = app(ExecutionModeService::class);

        $this->assertSame(ExecutionModeService::STUDY, $service->modeForPlan($study));
        $this->assertSame(ExecutionModeService::DEVELOPMENT, $service->modeForPlan($development));
        $this->assertSame(ExecutionModeService::CAREER, $service->modeForPlan($career));
        $this->assertSame(ExecutionModeService::GENERAL, $service->modeForPlan($creative));

        // PlanCategoryProfileService broadly recognizes learning, but the
        // current StudyActivityController supports qualification study only.
        $this->assertSame(ExecutionModeService::GENERAL, $service->modeForPlan($genericLearning));
    }

    public function test_available_modes_group_plans_without_turning_mode_into_a_plan_property(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $plans = collect([
            $this->plan($user, 'Study A', '資格学習'),
            $this->plan($user, 'Study B', '資格学習'),
            $this->plan($user, 'Dev', 'ゲーム開発'),
            $this->plan($user, 'Career', 'キャリア'),
            $this->plan($user, 'General', '生活'),
        ]);

        $modes = app(ExecutionModeService::class)->availableModes($plans);

        $this->assertSame(
            ['study', 'development', 'career', 'general'],
            array_keys($modes),
        );
        $this->assertSame(2, $modes['study']['plan_count']);
        $this->assertSame(1, $modes['development']['plan_count']);
        $this->assertSame(1, $modes['career']['plan_count']);
        $this->assertSame(1, $modes['general']['plan_count']);

        $this->assertArrayNotHasKey('execution_mode', $plans->first()->getAttributes());
    }

    public function test_each_specialized_mode_hands_selected_task_to_existing_workspace(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $service = app(ExecutionModeService::class);

        $studyPlan = $this->plan($user, 'Study', '資格学習');
        $studyTask = $this->task($studyPlan, '過去問を解く');

        $devPlan = $this->plan($user, 'Dev', '個人開発');
        $devTask = $this->task($devPlan, '実装する');

        $careerPlan = $this->plan($user, 'Career', '就活');
        $careerTask = $this->task($careerPlan, '応募する');

        $generalPlan = $this->plan($user, 'General', '生活');
        $generalTask = $this->task($generalPlan, '片付ける');

        $study = $service->actionFor($studyPlan, $studyTask);
        $development = $service->actionFor($devPlan, $devTask);
        $career = $service->actionFor($careerPlan, $careerTask);
        $general = $service->actionFor($generalPlan, $generalTask);

        $this->assertSame('plans.tasks.study_activity.show', $study['route_name']);
        $this->assertSame([$studyPlan->id, $studyTask->id], $study['route_parameters']);
        $this->assertFalse($study['supports_timer']);

        $this->assertSame('plans.tasks.execution_orchestration.show', $development['route_name']);
        $this->assertSame([$devPlan->id, $devTask->id], $development['route_parameters']);
        $this->assertFalse($development['supports_timer']);

        $this->assertSame('plans.career.index', $career['route_name']);
        $this->assertSame([$careerPlan->id], $career['route_parameters']);
        $this->assertFalse($career['supports_timer']);

        $this->assertSame('timer', $general['action_id']);
        $this->assertTrue($general['supports_timer']);
        $this->assertNull($general['route_name']);
    }

    public function test_multiple_modes_show_mode_picker_before_recommendation(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $this->task($this->plan($user, 'Study', '資格学習'), 'Study Task');
        $this->task($this->plan($user, 'Dev', '個人開発'), 'Dev Task');

        $response = $this->actingAs($user)->get(route('navigation.index'));

        $response
            ->assertOk()
            ->assertSee('data-execution-mode-picker', false)
            ->assertSee('data-execution-mode="study"', false)
            ->assertSee('data-execution-mode="development"', false)
            ->assertDontSee('data-execution-mode-switcher', false);

        $this->assertNull($response->viewData('selectedExecutionMode'));
        $this->assertNull($response->viewData('recommendation'));
        $this->assertCount(2, $response->viewData('availableExecutionModes'));
    }

    public function test_mode_query_scopes_recommendation_candidates_to_that_workspace(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $studyPlan = $this->plan($user, 'Study', '資格学習');
        $this->task($studyPlan, 'Study Task');

        $devPlan = $this->plan($user, 'Dev', '個人開発');
        $this->task($devPlan, 'Dev Task');

        $response = $this->actingAs($user)->get(route('navigation.index', [
            'mode' => ExecutionModeService::DEVELOPMENT,
        ]));

        $response
            ->assertOk()
            ->assertSee('data-execution-mode-switcher', false)
            ->assertSee('DEVELOPMENT')
            ->assertSee('開発Workspace');

        $this->assertSame(
            ExecutionModeService::DEVELOPMENT,
            $response->viewData('selectedExecutionMode'),
        );
        $this->assertSame(
            [$devPlan->id],
            $response->viewData('modePlans')->pluck('id')->all(),
        );

        $recommendation = $response->viewData('recommendation');
        if ($recommendation) {
            $this->assertSame($devPlan->id, $recommendation->plan->id);
        }
    }

    public function test_plan_context_auto_selects_its_mode_and_scope(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $studyPlan = $this->plan($user, 'Study', '資格学習');
        $this->task($studyPlan, 'Study Task');

        $devPlan = $this->plan($user, 'Dev', '個人開発');
        $this->task($devPlan, 'Dev Task');

        $response = $this->actingAs($user)->get(route('navigation.index', [
            'plan_id' => $devPlan->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('開発Workspace')
            ->assertSee('から選んでいます。');

        $this->assertSame(
            ExecutionModeService::DEVELOPMENT,
            $response->viewData('selectedExecutionMode'),
        );
        $this->assertSame($devPlan->id, $response->viewData('scopePlan')->id);
        $this->assertSame(
            [$devPlan->id],
            $response->viewData('modePlans')->pluck('id')->all(),
        );
    }

    public function test_single_available_mode_is_selected_without_extra_choice(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'Only Study', '資格学習');
        $this->task($plan, 'Study Task');

        $response = $this->actingAs($user)->get(route('navigation.index'));

        $response
            ->assertOk()
            ->assertDontSee('data-execution-mode-picker', false)
            ->assertSee('data-execution-mode-switcher', false)
            ->assertSee('学習Workspace');

        $this->assertSame(
            ExecutionModeService::STUDY,
            $response->viewData('selectedExecutionMode'),
        );
    }

    public function test_reset_keeps_current_mode_but_clears_recommendation_configuration(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $response = $this->actingAs($user)
            ->withSession([
                'navigation.draft' => [
                    'step' => 'time',
                    'intent' => 'urgent',
                    'execution_mode' => ExecutionModeService::STUDY,
                    'selection_steps' => 2,
                    'excluded_task_ids' => [10],
                ],
            ])
            ->post(route('navigation.reset'), [
                'mode' => ExecutionModeService::STUDY,
            ]);

        $response->assertRedirect(route('navigation.index', [
            'mode' => ExecutionModeService::STUDY,
        ]));

        $this->assertFalse(session()->has('navigation.draft'));
    }

    public function test_execution_workspace_view_uses_mode_scoped_plan_selection_and_specialized_handoff_contracts(): void
    {
        $view = file_get_contents(resource_path('views/navigation/index.blade.php'));
        $css = file_get_contents(resource_path('css/execution-workspace.css'));
        $controller = file_get_contents(app_path('Http/Controllers/NavigationController.php'));

        $this->assertStringContainsString('data-execution-mode-picker', $view);
        $this->assertStringContainsString('data-execution-mode-switcher', $view);
        $this->assertStringContainsString('$modePlans as $modePlan', $view);
        $this->assertStringContainsString('data-execution-handoff', $view);
        $this->assertStringContainsString('data-execution-recommendation-rail', $view);
        $this->assertStringContainsString('data-execution-primary-timer', $view);
        $this->assertStringNotContainsString('Timerで始める', $view);
        $this->assertStringNotContainsString('data-candidate-toggle', $view);

        $this->assertStringContainsString('.execution-mode-grid', $css);
        $this->assertStringContainsString('.execution-mode-switcher', $css);
        $this->assertStringContainsString('.execution-plan-switcher', $css);

        $this->assertStringContainsString(
            '$executionModes->plansForMode($plans, $selectedExecutionMode)',
            $controller,
        );
        $this->assertStringContainsString(
            '$executionModes->actionFor($recommendation->plan, $recommendation->task)',
            $controller,
        );
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
