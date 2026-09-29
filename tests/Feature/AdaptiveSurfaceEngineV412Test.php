<?php

namespace Tests\Feature;

use App\Data\PlanCategoryProfileData;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanSituationResolver;
use App\Services\PlanSurfaceEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdaptiveSurfaceEngineV412Test extends TestCase
{
    use RefreshDatabase;

    public function test_common_career_aliases_resolve_to_career_profile_and_icon(): void
    {
        $profiles = app(PlanCategoryProfileService::class);

        foreach (['就活', '就職活動', '転職', 'キャリア', '就職・将来'] as $category) {
            $this->assertSame('career', $profiles->forCategory($category)->key);

            $plan = $this->plan('Career', $category);
            $this->assertSame('💼', $plan->displayIcon());
        }
    }

    public function test_category_profiles_choose_different_future_roadmap_renderers(): void
    {
        $profiles = app(PlanCategoryProfileService::class);

        $study = $profiles->forCategory('資格学習');
        $career = $profiles->forCategory('就活・キャリア');
        $development = $profiles->forCategory('個人開発');
        $general = $profiles->forCategory('その他');

        $this->assertSame('study', $study->key);
        $this->assertSame('study_map', $study->roadmapRenderer);
        $this->assertSame('学習ロードマップ', $study->roadmapTitle);

        $this->assertSame('career', $career->key);
        $this->assertSame('pipeline', $career->roadmapRenderer);
        $this->assertSame('選考ロードマップ', $career->roadmapTitle);

        $this->assertSame('development', $development->key);
        $this->assertSame('delivery_flow', $development->roadmapRenderer);

        $this->assertSame('general', $general->key);
        $this->assertSame('task_flow', $general->roadmapRenderer);
    }

    public function test_career_pipeline_groups_existing_tasks_by_situation(): void
    {
        $plan = $this->plan('エンジニア就活', '就活・キャリア');
        $research = $this->task($plan, '応募候補企業をリサーチする', 1);
        $application = $this->task($plan, 'A社へ履歴書を提出する', 2);
        $interview = $this->task($plan, 'A社 一次面接対策', 3, status: 'doing', progress: 40);
        $offer = $this->task($plan, '内定後の条件を確認する', 4);

        $plan->load(['tasks', 'resources', 'artifacts']);
        $profile = app(PlanCategoryProfileService::class)->forPlan($plan);
        $situation = app(PlanSituationResolver::class)->resolve(
            $plan,
            $profile,
            $interview,
            collect(),
            collect(),
        );

        $pipeline = collect($situation['career_pipeline'])->keyBy('key');

        $this->assertSame(1, $pipeline['discovery']['total']);
        $this->assertSame(1, $pipeline['application']['total']);
        $this->assertSame(1, $pipeline['interview']['total']);
        $this->assertSame(1, $pipeline['offer']['total']);
        $this->assertTrue($situation['career_has_interview']);
        $this->assertTrue($situation['career_interview_is_current']);
        $this->assertSame($interview->id, $situation['career_interview_tasks']->first()->id);
    }

    public function test_interview_surface_appears_only_while_interview_work_is_relevant(): void
    {
        $plan = $this->plan('就活', '就活・キャリア');
        $research = $this->task($plan, '企業研究をする', 1);
        $interview = $this->task($plan, '一次面接の逆質問を準備する', 2, status: 'doing');

        $profile = app(PlanCategoryProfileService::class)->forPlan($plan);
        $modules = $this->modulesFor($plan, $profile, $interview);

        $this->assertContains('career_pipeline', $modules->pluck('id')->all());
        $this->assertContains('career_interview_focus', $modules->pluck('id')->all());
        $this->assertSame('current_task', $modules->get(0)->id);
        $this->assertSame('career_interview_focus', $modules->get(1)->id);

        $interview->update(['status' => 'done', 'progress_percent' => 100]);
        $research->refresh();
        $modulesAfter = $this->modulesFor($plan->fresh(), $profile, $research);

        $this->assertContains('career_pipeline', $modulesAfter->pluck('id')->all());
        $this->assertNotContains('career_interview_focus', $modulesAfter->pluck('id')->all());
    }

    public function test_study_surface_appears_when_ai_practice_is_available(): void
    {
        $plan = $this->plan('AP対策', '資格学習');
        $task = $this->task($plan, 'ネットワーク演習', 1);

        $plan->load(['tasks', 'resources', 'artifacts']);
        $profile = app(PlanCategoryProfileService::class)->forPlan($plan);
        $tools = collect([
            ['id' => 'ai_practice', 'recommended' => true],
            ['id' => 'timer', 'recommended' => false],
        ]);
        $situation = app(PlanSituationResolver::class)->resolve(
            $plan,
            $profile,
            $task,
            collect(),
            $tools,
        );
        $modules = app(PlanSurfaceEngine::class)->build($plan, $profile, $situation, $task);

        $this->assertContains('study_focus', $modules->pluck('id')->all());
        $this->assertNotContains('career_pipeline', $modules->pluck('id')->all());
    }

    public function test_development_delivery_surface_stays_hidden_until_artifacts_exist(): void
    {
        $plan = $this->plan('Canovia開発', '個人開発');
        $task = $this->task($plan, 'Home UIを改善する', 1);
        $profile = app(PlanCategoryProfileService::class)->forPlan($plan);

        $before = $this->modulesFor($plan, $profile, $task);
        $this->assertNotContains('delivery_focus', $before->pluck('id')->all());

        $artifact = $plan->artifacts()->create([
            'provider' => 'github',
            'artifact_type' => 'repository',
            'title' => 'Canovia',
            'url' => 'https://example.com/canovia',
        ]);
        $artifact->tasks()->sync([$task->id]);

        $after = $this->modulesFor($plan->fresh(), $profile, $task->fresh());

        $this->assertContains('delivery_focus', $after->pluck('id')->all());
    }

    public function test_dashboard_uses_registered_surface_views_instead_of_hardcoded_plan_cards(): void
    {
        $view = file_get_contents(resource_path('views/dashboard/index.blade.php'));

        $disclosure = file_get_contents(resource_path('views/dashboard/surfaces/disclosure.blade.php'));
        $this->assertStringContainsString("@include('dashboard.surfaces.disclosure'", $view);
        $this->assertStringContainsString("@include(\$surface->view", $disclosure);
        $this->assertStringContainsString("\$surfaceModules", $view);
        $this->assertStringNotContainsString('data-plan-hub-current>', $view);

        $career = file_get_contents(resource_path('views/dashboard/surfaces/career-pipeline.blade.php'));
        $interview = file_get_contents(resource_path('views/dashboard/surfaces/career-interview-focus.blade.php'));

        $this->assertStringContainsString('CAREER PIPELINE', $career);
        $this->assertStringContainsString('INTERVIEW FOCUS', $interview);
        $this->assertStringContainsString('面接対策が落ち着けば', $interview);
    }

    public function test_future_ai_decision_can_only_reorder_registered_surfaces(): void
    {
        $plan = $this->plan('就活', '就活・キャリア');
        $task = $this->task($plan, '一次面接対策', 1, status: 'doing');
        $profile = app(PlanCategoryProfileService::class)->forPlan($plan);
        $modules = $this->modulesFor($plan, $profile, $task);
        $engine = app(PlanSurfaceEngine::class);

        $context = $engine->policyContext(
            $plan,
            $profile,
            ['career_has_interview' => true, 'active_task_count' => 1],
            $modules,
        );

        $this->assertSame(1, $context['schema_version']);
        $this->assertContains('current_task', $context['protected_module_ids']);
        $this->assertNotEmpty($context['available_modules']);

        $decided = $engine->applyDecision($modules, [
            'ordered_module_ids' => ['career_pipeline', 'made_up_module', 'current_task'],
            'hidden_module_ids' => ['current_task', 'recent_activity', 'evil_html_module'],
        ]);

        $ids = $decided->pluck('id')->all();

        $this->assertSame('career_pipeline', $ids[0]);
        $this->assertSame('current_task', $ids[1]);
        $this->assertContains('plan_tools', $ids);
        $this->assertNotContains('recent_activity', $ids);
        $this->assertNotContains('made_up_module', $ids);
        $this->assertNotContains('evil_html_module', $ids);
    }

    public function test_home_renders_career_surfaces_and_hides_interview_card_when_no_interview_task_remains(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'エンジニア就活',
            'description' => '応募と面接を進める',
            'category' => '就活・キャリア',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $this->task($plan, '応募候補企業をリサーチする', 1);
        $interview = $this->task($plan, 'A社 一次面接対策', 2, status: 'doing', progress: 30);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('CAREER PIPELINE')
            ->assertSee('INTERVIEW FOCUS')
            ->assertSee('応募・選考の流れ');

        $interview->update(['status' => 'done', 'progress_percent' => 100, 'remaining_minutes' => 0]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('CAREER PIPELINE')
            ->assertDontSee('INTERVIEW FOCUS');
    }

    public function test_roadmap_copy_changes_with_career_profile_while_spatial_map_is_primary(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => '就活',
            'category' => '就活・キャリア',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $this->task($plan, '企業研究', 1);

        $this->actingAs($user)
            ->get(route('roadmap.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('選考ロードマップ')
            ->assertSee('企業探し・応募・面接・内定')
            ->assertSee('data-roadmap-renderer-preference="pipeline"', false)
            ->assertSee('data-roadmap-renderer-active="spatial_map"', false);
    }

    public function test_plan_creation_and_editing_offer_career_category(): void
    {
        $create = file_get_contents(resource_path('views/plans/create.blade.php'));
        $edit = file_get_contents(resource_path('views/plans/edit.blade.php'));

        $this->assertStringContainsString('就活・キャリア', $create);
        $this->assertStringContainsString('就活・キャリア', $edit);
    }

    public function test_roadmap_view_exposes_profile_and_renderer_boundary(): void
    {
        $view = file_get_contents(resource_path('views/roadmap/index.blade.php'));

        $this->assertStringContainsString('data-roadmap-profile', $view);
        $this->assertStringContainsString('data-roadmap-renderer-preference', $view);
        $this->assertStringContainsString('data-roadmap-renderer-active', $view);
        $this->assertStringContainsString("roadmap_title", $view);
    }

    private function modulesFor(Plan $plan, PlanCategoryProfileData $profile, Task $currentTask)
    {
        $plan->load(['tasks', 'resources', 'artifacts']);

        $situation = app(PlanSituationResolver::class)->resolve(
            $plan,
            $profile,
            $currentTask,
            collect(),
            collect(),
        );

        return app(PlanSurfaceEngine::class)->build($plan, $profile, $situation, $currentTask);
    }

    private function plan(string $title, string $category): Plan
    {
        return Plan::create([
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => '状況に応じてSurfaceを変えるPlan',
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }

    private function task(
        Plan $plan,
        string $title,
        int $sortOrder,
        string $status = 'todo',
        int $progress = 0,
    ): Task {
        return Task::create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => '状況判定用Task',
            'estimated_minutes' => 60,
            'remaining_minutes' => $progress >= 100 ? 0 : 45,
            'progress_percent' => $progress,
            'status' => $status,
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => $sortOrder,
        ]);
    }
}
