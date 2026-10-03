<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\ExecutionModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RealDeviceUiRefinementV5196Test extends TestCase
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

    public function test_study_handoff_distinguishes_question_recall_and_admin_tasks(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'AP合格', '資格学習');

        $question = $this->task($plan, 'ネットワークの過去問を解く', 1);
        $recall = $this->task($plan, '英単語を暗記して復習する', 2);
        $admin = $this->task($plan, '受験申込日を予約する', 3);

        $service = app(ExecutionModeService::class);

        $questionAction = $service->actionFor($plan, $question);
        $recallAction = $service->actionFor($plan, $recall);
        $adminAction = $service->actionFor($plan, $admin);

        $this->assertSame('study:question_practice', $questionAction['compatibility_key']);
        $this->assertSame('study:recall', $recallAction['compatibility_key']);

        $this->assertSame('timer', $adminAction['action_id']);
        $this->assertSame('timer', $adminAction['compatibility_key']);
        $this->assertTrue($adminAction['supports_timer']);
        $this->assertNull($adminAction['route_name']);
    }

    public function test_execution_rail_is_other_plan_only_and_matches_primary_handoff(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $primaryPlan = $this->plan($user, 'AP本番対策', '資格学習');
        $this->task($primaryPlan, '科目Aの過去問を解く', 1);
        $this->task($primaryPlan, '科目Bの演習問題を解く', 2);

        $adminPlan = $this->plan($user, '別資格の準備', '資格学習');
        $this->task($adminPlan, '受験会場を予約する', 1);

        $practicePlan = $this->plan($user, 'DB資格対策', '資格学習');
        $this->task($practicePlan, 'データベースの問題演習をする', 1);

        $response = $this->actingAs($user)->get(route('navigation.index', [
            'plan_id' => $primaryPlan->id,
        ]));

        $response->assertOk();

        $recommendation = $response->viewData('recommendation');
        $recommendations = collect($response->viewData('recommendations'));

        $this->assertNotNull($recommendation);
        $this->assertSame($primaryPlan->id, $recommendation->plan->id);

        $planIds = $recommendations
            ->pluck('plan.id')
            ->map(fn ($id) => (int) $id)
            ->values();

        $this->assertSame(
            $planIds->unique()->count(),
            $planIds->count(),
            'The horizontal rail must not add another candidate from the same Plan.',
        );
        $this->assertTrue($planIds->contains($practicePlan->id));
        $this->assertFalse(
            $planIds->contains($adminPlan->id),
            'A qualification administration Task must not appear beside an AI-question-practice handoff.',
        );
    }

    public function test_old_condition_flow_is_normalized_back_to_recommendation(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'Study', '資格学習');
        $this->task($plan, '過去問を解く', 1);

        $response = $this->actingAs($user)
            ->withSession([
                'navigation.draft' => [
                    'step' => 'intent',
                    'intent' => 'urgent',
                    'minutes' => 30,
                    'execution_mode' => ExecutionModeService::STUDY,
                    'selection_steps' => 2,
                    'excluded_task_ids' => [],
                ],
            ])
            ->get(route('navigation.index', [
                'mode' => ExecutionModeService::STUDY,
                'configure' => 1,
            ]));

        $response
            ->assertOk()
            ->assertDontSee('条件変更')
            ->assertDontSee('今日はどう進めたい？')
            ->assertDontSee('どれくらい時間がありますか？');

        $draft = $response->viewData('draft');
        $this->assertSame('recommendation', $draft['step']);
        $this->assertSame('decide', $draft['intent']);
        $this->assertSame(0, $draft['minutes']);
    }

    public function test_mobile_constellation_reserves_more_vertical_space_for_tasks(): void
    {
        $css = file_get_contents(resource_path('css/constellation-roadmap.css'));
        $view = file_get_contents(resource_path('views/navigation/index.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/NavigationController.php'));

        $this->assertStringContainsString(
            'height: clamp(34rem, 70dvh, 41rem);',
            $css,
        );
        $this->assertStringContainsString(
            'grid-template-rows: minmax(14.5rem, 45%) minmax(0, 55%);',
            $css,
        );
        $this->assertStringContainsString('overscroll-behavior: contain;', $css);

        $this->assertStringNotContainsString('条件変更', $view);
        $this->assertStringNotContainsString("['configure' => 1]", $view);
        $this->assertStringContainsString('他Planの同じ実行タイプ', $view);

        $this->assertStringContainsString('$primaryCompatibilityKey', $controller);
        $this->assertStringContainsString('candidateTaskIds: $compatibleTaskIds', $controller);
        $this->assertStringNotContainsString('while ($recommendations->count() < 4)', $controller);
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

    private function task(Plan $plan, string $title, int $sortOrder): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'next_action_note' => $title.'を進める',
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => $sortOrder,
        ]);
    }
}
